<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * FLT-01: persistence boundary for already-classified observations.
 *
 * Provider adapters in later phases classify signals; they never manipulate
 * incidents or emit notifications. SQL is authoritative across restarts.
 */
final class FilteringFoundation
{
    private const MODULES = ['node', 'regional'];

    private const OUTCOMES = ['healthy', 'suspicious', 'disrupted', 'unknown'];

    private const COUNTERS = [
        'online_users', 'rx_bytes_per_sec', 'tx_bytes_per_sec',
        'completed_probes', 'failed_probes', 'asn_count', 'city_count',
    ];

    private const CAUSES = [
        'node' => ['online_zero', 'traffic_zero'],
        'regional' => ['regional_possible', 'regional_likely'],
    ];

    /**
     * An identical node/module/second is a replay, not a second observation.
     * UNKNOWN persists as evidence but cannot open, recover or close incidents.
     *
     * @param  array<string, mixed>  $evidence
     * @return array{node_uuid:string,state:string,incident_id:int|null,affected_modules:array<string, mixed>,latest:array<string, mixed>}
     */
    public function record(
        string $nodeUuid,
        string $module,
        string $outcome,
        DateTimeInterface $observedAt,
        array $evidence = [],
        ?DateTimeInterface $providerObservedAt = null,
    ): array {
        $nodeUuid = $this->validatedNode($nodeUuid);
        if (! in_array($module, self::MODULES, true) || ! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Unsupported filtering module or outcome.');
        }

        $observed = CarbonImmutable::instance($observedAt)->utc()->startOfSecond();
        $providerObserved = $providerObservedAt === null
            ? null : CarbonImmutable::instance($providerObservedAt)->utc()->startOfSecond();
        if ($observed->lessThan(now()->subDay()) || $observed->greaterThan(now()->addMinute())
            || ($providerObserved !== null && $providerObserved->greaterThan($observed->addMinute()))) {
            throw new InvalidArgumentException('Filtering observation timestamp is invalid.');
        }
        $evidence = $this->validatedEvidence($module, $outcome, $evidence);

        DB::transaction(function () use ($nodeUuid, $module, $outcome, $observed, $providerObserved, $evidence): void {
            $inserted = DB::table('filtering_checks')->insertOrIgnore([
                'node_uuid' => $nodeUuid,
                'module' => $module,
                'outcome' => $outcome,
                'observed_at' => $observed,
                'provider_observed_at' => $providerObserved,
                'evidence' => $evidence === [] ? null : json_encode($evidence, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            // Never let a replay mutate an incident (including one already resolved).
            if ($inserted === 0) {
                return;
            }

            $latest = DB::table('filtering_checks')
                ->where('node_uuid', $nodeUuid)->where('module', $module)
                ->orderByDesc('observed_at')->orderByDesc('id')
                ->lockForUpdate()->first(['id', 'observed_at']);
            $current = DB::table('filtering_checks')
                ->where('node_uuid', $nodeUuid)->where('module', $module)
                ->where('observed_at', $observed)->first(['id']);
            if ($latest === null || $current === null || $latest->id !== $current->id) {
                return; // A delayed/out-of-order check is historical only.
            }

            if ($outcome === 'unknown') {
                return;
            }

            $now = now();
            if ($outcome === 'healthy') {
                $incident = DB::table('filtering_incidents')
                    ->where('active_node_uuid', $nodeUuid)->lockForUpdate()->first();
                if ($incident === null) {
                    return;
                }
            } else {
                // Attempt an atomic reservation on every abnormal sample.
                // Under competing first opens, the loser MUST update the winner's
                // module map rather than return and silently discard its signal.
                $created = DB::table('filtering_incidents')->insertOrIgnore([
                    'node_uuid' => $nodeUuid,
                    'active_node_uuid' => $nodeUuid,
                    'state' => $outcome,
                    'affected_modules' => json_encode([
                        $module => ['state' => $outcome, 'causes' => $evidence['causes'], 'observed_at' => $observed->toDateTimeString()],
                    ], JSON_THROW_ON_ERROR),
                    'opened_at' => $now,
                    'last_observed_at' => $observed,
                    'updated_at' => $now,
                ]);

                $incident = DB::table('filtering_incidents')
                    ->where('active_node_uuid', $nodeUuid)->lockForUpdate()->first();
                if ($incident === null) {
                    throw new RuntimeException('Filtering incident could not be reserved.');
                }
                if ($created === 1) {
                    return;
                }
            }

            /** @var array<string, mixed> $modules */
            $modules = json_decode((string) $incident->affected_modules, true, 512, JSON_THROW_ON_ERROR);
            if ($outcome === 'healthy') {
                unset($modules[$module]);
            } else {
                $modules[$module] = [
                    'state' => $outcome,
                    'causes' => $evidence['causes'],
                    'observed_at' => $observed->toDateTimeString(),
                ];
            }

            $updated = [
                'affected_modules' => json_encode($modules, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
                'last_observed_at' => max(
                    CarbonImmutable::parse($incident->last_observed_at)->timestamp,
                    $observed->timestamp,
                ) === $observed->timestamp ? $observed : $incident->last_observed_at,
            ];
            if ($modules === []) {
                $updated['state'] = 'resolved';
                $updated['resolved_at'] = $now;
                $updated['active_node_uuid'] = null;
            } else {
                $updated['state'] = in_array('disrupted', array_column($modules, 'state'), true)
                    ? 'disrupted' : 'suspicious';
            }

            DB::table('filtering_incidents')->where('id', $incident->id)->update($updated);
        }, 5);

        return $this->read($nodeUuid);
    }

    /**
     * Bounded per-node read; no network or permissions-sensitive API request.
     *
     * @return array{node_uuid:string,state:string,incident_id:int|null,affected_modules:array<string, mixed>,latest:array<string, mixed>}
     */
    public function read(string $nodeUuid): array
    {
        $nodeUuid = $this->validatedNode($nodeUuid);
        $incident = DB::table('filtering_incidents')->where('active_node_uuid', $nodeUuid)->first();
        $latest = [];
        foreach (self::MODULES as $module) {
            $check = DB::table('filtering_checks')
                ->where('node_uuid', $nodeUuid)->where('module', $module)
                ->orderByDesc('observed_at')->orderByDesc('id')
                ->first(['outcome', 'observed_at', 'provider_observed_at']);
            if ($check !== null) {
                $latest[$module] = [
                    'outcome' => $check->outcome,
                    'observed_at' => $check->observed_at,
                    'provider_observed_at' => $check->provider_observed_at,
                ];
            }
        }

        $state = $incident->state ?? 'unknown';
        if ($incident === null && $latest !== []) {
            $outcomes = array_column($latest, 'outcome');
            $state = in_array('unknown', $outcomes, true) ? 'unknown' : 'healthy';
        }

        return [
            'node_uuid' => $nodeUuid,
            'state' => $state,
            'incident_id' => $incident === null ? null : (int) $incident->id,
            'affected_modules' => $incident === null
                ? [] : json_decode((string) $incident->affected_modules, true, 512, JSON_THROW_ON_ERROR),
            'latest' => $latest,
        ];
    }

    private function validatedNode(string $nodeUuid): string
    {
        if (! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $nodeUuid)) {
            throw new InvalidArgumentException('Invalid Node UUID.');
        }

        return strtolower($nodeUuid);
    }

    /**
     * Only normalized numeric evidence and preapproved cause codes may persist.
     * Reject arbitrary provider payloads, IPs, tokens, URLs and secret fields.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function validatedEvidence(string $module, string $outcome, array $evidence): array
    {
        if (count($evidence) > count(self::COUNTERS) + 1) {
            throw new InvalidArgumentException('Filtering evidence is too large.');
        }
        $causes = $evidence['causes'] ?? [];
        if (! is_array($causes) || ! array_is_list($causes)
            || count($causes) > 2) {
            throw new InvalidArgumentException('Filtering causes are invalid.');
        }
        foreach ($causes as $cause) {
            if (! is_string($cause) || ! in_array($cause, self::CAUSES[$module], true)) {
                throw new InvalidArgumentException('Unsupported filtering cause.');
            }
        }
        if (count(array_unique($causes)) !== count($causes)) {
            throw new InvalidArgumentException('Duplicate filtering causes.');
        }
        if (($outcome === 'healthy' || $outcome === 'unknown') !== ($causes === [])) {
            throw new InvalidArgumentException('Filtering outcome and causes disagree.');
        }

        foreach ($evidence as $key => $value) {
            if ($key === 'causes') {
                continue;
            }
            if (! in_array($key, self::COUNTERS, true) || ! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Unapproved filtering evidence.');
            }
        }
        if ($causes !== []) {
            sort($causes);
            $evidence['causes'] = $causes;
        }

        return $evidence;
    }
}
