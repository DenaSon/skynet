<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodeSummary;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveReadApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

/**
 * FLT-02: a single read-only provider GET per minute, with SQL-authoritative
 * two-sample signal decisions. No polling/HTTP in Telegram webhooks.
 */
final readonly class MonitorNodeFiltering
{
    private const WINDOW_SECONDS = 90;

    private const BASELINE_MINUTES = 30;

    private const MAX_NODES = 100;

    public function __construct(
        private RemnawaveApiClientFactory $clients,
        private FilteringFoundation $foundation,
        private FilteringIncidentAlerts $alerts,
        private ?ScanRegionalFiltering $regional = null,
    ) {}

    /** Number of valid and eligible Nodes sampled; disabled until live verification. */
    public function scan(): int
    {
        if (config('filtering.node_monitor_enabled', false) !== true) {
            return 0;
        }

        try {
            $nodes = (new RemnawaveReadApi($this->clients->make()))->nodes();
            if (count($nodes) > self::MAX_NODES) {
                throw new UnexpectedValueException('Node list exceeds scan budget.');
            }
            $seen = [];
            foreach ($nodes as $node) {
                if (! $this->validUuid($node->uuid) || isset($seen[strtolower($node->uuid)])) {
                    throw new UnexpectedValueException('Invalid or duplicate Node UUID in snapshot.');
                }
                $seen[strtolower($node->uuid)] = true;
            }
        } catch (Throwable) {
            // Record the failure in the minute it actually finished, even if
            // the upstream request was slow; do not backdate a stale result.
            $minute = CarbonImmutable::instance(now())->utc()->startOfMinute();
            $this->unknownForKnownNodes($minute);

            return 0;
        }

        // Collection time is not a guarantee of upstream metric freshness.
        // It is, however, always the actual completed HTTP observation minute.
        $minute = CarbonImmutable::instance(now())->utc()->startOfMinute();
        $count = 0;
        foreach ($nodes as $node) {
            $uuid = strtolower($node->uuid);
            if ($node->disabled || ! $node->connected || $node->connecting === true) {
                $this->store($uuid, 'unknown', $minute);

                continue;
            }

            try {
                $this->observe($node, $minute);
                $count++;
            } catch (\InvalidArgumentException) {
                // Individual malformed telemetry is UNKNOWN; never coerce.
                $this->store($uuid, 'unknown', $minute);
            }
        }

        // Missing/removed Nodes are UNKNOWN. Do not synthesize healthy recovery.
        $this->unknownForKnownNodes($minute, array_keys($seen));

        return $count;
    }

    public function observe(RemnawaveNodeSummary $node, CarbonImmutable $minute): void
    {
        if (! $this->validUuid($node->uuid) || $node->disabled || ! $node->connected || $node->connecting === true
            || $minute->greaterThan(now()->addMinute()) || $minute->lessThan(now()->subDay())) {
            throw new \InvalidArgumentException('Invalid or ineligible Node snapshot.');
        }

        $uuid = strtolower($node->uuid);
        $evidence = [];
        foreach ([
            'online_users' => $node->usersOnline,
            'rx_bytes_per_sec' => $node->rxBytesPerSec,
            'tx_bytes_per_sec' => $node->txBytesPerSec,
        ] as $field => $value) {
            if ($value !== null) {
                if ($value < 0) {
                    throw new \InvalidArgumentException('Invalid Node traffic counter.');
                }
                $evidence[$field] = $value;
            }
        }

        $history = DB::table('filtering_checks')
            ->where('node_uuid', $uuid)->where('module', 'node')
            ->where('observed_at', '<', $minute)
            ->where('observed_at', '>=', $minute->subMinutes(self::BASELINE_MINUTES))
            ->orderByDesc('observed_at')->limit(self::BASELINE_MINUTES + 2)
            ->get(['observed_at', 'evidence']);

        $last = $history->first();
        $previous = $last === null ? [] : $this->parseEvidence($last->evidence);
        $contiguous = $last !== null
            && $minute->timestamp - CarbonImmutable::parse($last->observed_at)->timestamp <= self::WINDOW_SECONDS;
        $baseline = $history->contains(function ($check): bool {
            $fields = $this->parseEvidence($check->evidence);

            return self::online($fields) === 'positive' || self::traffic($fields) === 'positive';
        });

        $active = $this->foundation->read($uuid)['affected_modules']['node']['causes'] ?? [];
        $causes = is_array($active) ? $active : [];
        foreach (['online_zero' => 'online', 'traffic_zero' => 'traffic'] as $cause => $signal) {
            $currentState = self::$signal($evidence);
            $priorState = self::$signal($previous);
            $streak = $contiguous && $currentState === $priorState;

            if (in_array($cause, $causes, true)) {
                if ($streak && $currentState === 'positive') {
                    $causes = array_values(array_diff($causes, [$cause]));
                }
            } elseif ($baseline && $streak && $currentState === 'zero') {
                $causes[] = $cause;
            }
        }

        sort($causes);
        // Recover exactly the affected signals. An unrelated unavailable
        // counter must not strand an otherwise fully recovered incident.
        $outcome = $causes !== [] ? 'disrupted' : (
            $active !== [] || (
                self::online($evidence) === 'positive' && self::traffic($evidence) === 'positive'
            ) ? 'healthy' : 'unknown'
        );
        if ($causes !== []) {
            $evidence['causes'] = $causes;
        }

        $this->store($uuid, $outcome, $minute, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    private function store(string $uuid, string $outcome, CarbonImmutable $minute, array $evidence = []): void
    {
        // The outer transaction makes the state transition and notification
        // intents commit atomically. Provider HTTP is always outside it.
        DB::transaction(function () use ($uuid, $outcome, $minute, $evidence): void {
            $before = $this->foundation->read($uuid);
            $after = $this->foundation->record($uuid, 'node', $outcome, $minute, $evidence);
            $id = $after['incident_id'] ?? $before['incident_id'];
            if ($id !== null && $outcome !== 'unknown') {
                $this->alerts->sync($id);
                if ($before['incident_id'] === null && $after['incident_id'] !== null) {
                    $this->regional?->queueConfirmation($uuid);
                }
            }
        }, 3);
    }

    /** @param list<string> $present */
    private function unknownForKnownNodes(CarbonImmutable $minute, array $present = []): void
    {
        $known = DB::table('filtering_checks')->where('module', 'node')
            ->where('observed_at', '>=', $minute->subMinutes(self::BASELINE_MINUTES))
            ->distinct()->limit(self::MAX_NODES + 1)->pluck('node_uuid');
        foreach ($known->take(self::MAX_NODES) as $uuid) {
            if (! in_array($uuid, $present, true) && $this->validUuid($uuid)) {
                $this->store($uuid, 'unknown', $minute);
            }
        }
    }

    /** @return array<string, mixed> */
    private function parseEvidence(?string $evidence): array
    {
        if ($evidence === null) {
            return [];
        }
        $data = json_decode($evidence, true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $fields */
    private static function online(array $fields): string
    {
        $count = $fields['online_users'] ?? null;

        return ! is_int($count) || $count < 0 ? 'unknown' : ($count === 0 ? 'zero' : 'positive');
    }

    /** @param array<string, mixed> $fields */
    private static function traffic(array $fields): string
    {
        $rx = $fields['rx_bytes_per_sec'] ?? null;
        $tx = $fields['tx_bytes_per_sec'] ?? null;

        return ! is_int($rx) || ! is_int($tx) || $rx < 0 || $tx < 0
            ? 'unknown' : ($rx === 0 && $tx === 0 ? 'zero' : 'positive');
    }

    private function validUuid(string $uuid): bool
    {
        return preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $uuid) === 1;
    }
}
