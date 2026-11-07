<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

use App\Modules\Filtering\Infrastructure\CheckHostRegionalProbe;
use App\Modules\Filtering\Jobs\ScanRegionalNodeJob;
use App\Support\Health\NodeTcpProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** FLT-03: independently gated, budgeted Iran TCP scans; no operational writes. */
final readonly class ScanRegionalFiltering
{
    private const MAX_TARGETS_PER_TICK = 5;

    private const WINDOW_SECONDS = 720;

    public function __construct(
        private NodeTcpProbe $targets,
        private CheckHostRegionalProbe $provider,
        private FilteringFoundation $foundation,
        private FilteringIncidentAlerts $alerts,
    ) {}

    /** Run a scheduled ten-minute page or one authorized incident confirmation. */
    public function scan(?string $onlyNodeUuid = null): int
    {
        if (config('filtering.regional_monitor_enabled', false) !== true) {
            return 0;
        }

        try {
            // Strict mode rejects unverified inbound associations, CDN aliases,
            // disabled/disconnected Nodes and every non-public/ambiguous target.
            $targets = $this->targets->targets(true);
        } catch (Throwable) {
            return 0;
        }

        if ($onlyNodeUuid !== null) {
            $targets = array_intersect_key($targets, [strtolower($onlyNodeUuid) => true]);
        } elseif (count($targets) > self::MAX_TARGETS_PER_TICK) {
            // Deterministic round-robin prevents the same five Nodes from
            // monopolizing provider-wide requests as the fleet grows.
            ksort($targets);
            $count = count($targets);
            $cursor = (int) (floor(now()->timestamp / 600) * self::MAX_TARGETS_PER_TICK) % $count;
            $keys = array_keys($targets);
            $selected = [];
            for ($i = 0; $i < self::MAX_TARGETS_PER_TICK; $i++) {
                $key = $keys[($cursor + $i) % $count];
                $selected[$key] = $targets[$key];
            }
            $targets = $selected;
        }
        if ($targets === []) {
            return 0;
        }

        try {
            $nodes = $this->provider->iranNodes();
        } catch (Throwable) {
            $nodes = [];
        }

        $checked = 0;
        foreach ($targets as $uuid => $endpoint) {
            if (DB::table('filtering_checks')->where('module', 'regional')
                ->where('node_uuid', $uuid)->where('observed_at', '>=', now()->subMinutes(2))->exists()) {
                continue;
            }

            $counts = [];
            $round = 'unknown';
            try {
                if (count($nodes) >= 4) {
                    $counts = $this->provider->tcp($endpoint['ip'], $endpoint['port'], $nodes);
                    $round = RegionalRoundClassifier::classify($counts);
                }
            } catch (Throwable) {
                // Provider outage/429/timeout/malformed metadata are UNKNOWN,
                // never a completed failed probe.
            }

            $observed = CarbonImmutable::instance(now())->utc()->startOfMinute();
            $previous = DB::table('filtering_checks')
                ->where('node_uuid', $uuid)->where('module', 'regional')
                ->where('observed_at', '<', $observed)
                ->orderByDesc('observed_at')->first(['observed_at', 'outcome', 'evidence']);
            $elapsed = $previous === null ? PHP_INT_MAX
                : $observed->timestamp - CarbonImmutable::parse($previous->observed_at)->timestamp;
            $prior = $previous === null ? 'unknown'
                : RegionalRoundClassifier::classify($this->decode($previous->evidence));
            $consecutive = $elapsed > 0 && $elapsed <= self::WINDOW_SECONDS
                && $previous !== null && $previous->outcome !== 'unknown';
            $current = $this->foundation->read($uuid)['affected_modules']['regional']['causes'] ?? [];
            $current = is_array($current) ? $current : [];

            $outcome = 'unknown';
            $causes = [];
            if ($round === 'possible' || $round === 'likely') {
                // Preserve a confirmed LIKELY incident through weaker/pending
                // rounds; recovery is only ever two qualified CLEAR rounds.
                if (in_array('regional_likely', $current, true)) {
                    $causes = ['regional_likely'];
                    $outcome = 'disrupted';
                } else {
                    $causes = ['regional_possible'];
                    $outcome = 'suspicious';
                    if ($round === 'likely' && $consecutive && $prior === 'likely') {
                        try {
                            // A separate control TCP target from the *same Iran
                            // vantage points* distinguishes broad reachability
                            // failures from the Node-specific service issue.
                            $control = $this->provider->tcp('1.1.1.1', 443, $nodes);
                            if (RegionalRoundClassifier::externalControlHealthy($control)) {
                                $causes = ['regional_likely'];
                                $outcome = 'disrupted';
                            } else {
                                $outcome = 'unknown';
                                $causes = [];
                            }
                        } catch (Throwable) {
                            $outcome = 'unknown';
                            $causes = [];
                        }
                    }
                }
            } elseif ($round === 'clear') {
                if ($current === [] || ($consecutive && $prior === 'clear')) {
                    $outcome = 'healthy';
                } else {
                    // First good round does not resolve a prior incident.
                    $causes = $current;
                    $outcome = in_array('regional_likely', $causes, true) ? 'disrupted' : 'suspicious';
                }
            }

            $evidence = array_intersect_key($counts, array_fill_keys([
                'completed_probes', 'failed_probes', 'pending_probes', 'asn_count',
                'city_count', 'failed_asn_count', 'failed_city_count',
            ], true));
            if ($causes !== []) {
                $evidence['causes'] = $causes;
            }

            DB::transaction(function () use ($uuid, $outcome, $observed, $evidence): void {
                $before = $this->foundation->read($uuid);
                $after = $this->foundation->record($uuid, 'regional', $outcome, $observed, $evidence);
                $incident = $after['incident_id'] ?? $before['incident_id'];
                if ($incident !== null && $outcome !== 'unknown') {
                    $this->alerts->sync($incident);
                }
            }, 3);
            $checked++;
        }

        return $checked;
    }

    /** Emergency confirmation: one per Node / 15 min and one provider-wide / 5 min. */
    public function queueConfirmation(string $nodeUuid): bool
    {
        if (config('filtering.regional_monitor_enabled', false) !== true
            || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $nodeUuid)) {
            return false;
        }
        try {
            $bucket = (int) floor(now()->timestamp / 300);
            if (! Cache::add('filtering:regional:extra:global:'.$bucket, true, now()->addMinutes(5))) {
                return false;
            }
            if (! Cache::add('filtering:regional:extra:node:'.strtolower($nodeUuid), true, now()->addMinutes(15))) {
                return false;
            }
            DB::afterCommit(static function () use ($nodeUuid): void {
                ScanRegionalNodeJob::dispatch(strtolower($nodeUuid));
            });

            return true;
        } catch (Throwable) {
            // A dead cache or queue must not affect Node incident persistence.
            return false;
        }
    }

    /** @return array<string, int> */
    private function decode(?string $raw): array
    {
        $json = $raw === null ? null : json_decode($raw, true);
        if (! is_array($json)) {
            return [];
        }
        $out = [];
        foreach (['completed_probes', 'failed_probes', 'asn_count', 'city_count',
            'failed_asn_count', 'failed_city_count'] as $key) {
            if (is_int($json[$key] ?? null) && $json[$key] >= 0) {
                $out[$key] = $json[$key];
            }
        }

        return $out;
    }
}
