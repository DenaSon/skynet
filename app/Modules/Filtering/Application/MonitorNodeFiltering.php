<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodeSummary;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveReadApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

/**
 * FLT-02: a single read-only provider GET per minute, with SQL-authoritative
 * two-sample signal decisions. No polling/HTTP in Telegram webhooks.
 */
final readonly class MonitorNodeFiltering
{
    private const WINDOW_SECONDS = 180;

    private const BASELINE_MINUTES = 30;

    private const RECOVERY_SECONDS = 600;

    private const MAX_NODES = 100;

    private const MIN_BASELINE_SAMPLES = 6;

    private const MIN_ONLINE_BASELINE = 5;

    private const MIN_TRAFFIC_BASELINE = 32768; // 32 KiB/s, measured on the Node interface.

    private const ONLINE_DROP_PERCENT = 40;

    private const TRAFFIC_DROP_PERCENT = 50;

    private const COMBINED_DROP_PERCENT = 30;

    private const RECOVERY_PERCENT_OF_BASELINE = 80;

    public function __construct(
        private RemnawaveApiClientFactory $clients,
        private FilteringFoundation $foundation,
        private FilteringIncidentAlerts $alerts,
        private ScanRegionalFiltering $regional,
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

        // Reuse the authenticated GET snapshot for optional Admin Bot labels.
        // A cache failure must never prevent metric sampling or incident recording.
        try {
            $names = [];
            foreach ($nodes as $node) {
                $name = trim($node->name);
                if ($name !== '' && mb_strlen($name) <= 80
                    && preg_match('/[\x00-\x1F\x7F]/u', $name) === 0) {
                    $names[strtolower($node->uuid)] = $name;
                }
            }
            // Cache only a fully validated, successful RW inventory. The Admin UI
            // uses this snapshot to exclude retired Nodes without Provider calls.
            Cache::put(FilteringFoundation::NODE_INVENTORY_CACHE_KEY, array_keys($seen), now()->addDay());
            Cache::put(FilteringFoundation::NODE_NAME_CACHE_KEY, $names, now()->addDay());
        } catch (Throwable) {
            // Numeric health observations remain authoritative.
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

        $nodeIncident = $this->foundation->read($uuid)['affected_modules']['node'] ?? [];
        $nodeIncident = is_array($nodeIncident) ? $nodeIncident : [];
        $active = $nodeIncident['causes'] ?? [];
        $causes = is_array($active) ? $active : [];
        foreach (['online_zero' => 'online', 'traffic_zero' => 'traffic'] as $cause => $signal) {
            $currentState = self::$signal($evidence);
            $priorState = self::$signal($previous);
            $streak = $contiguous && $currentState === $priorState;

            if (in_array($cause, $causes, true)) {
                if ($streak && $currentState === 'positive'
                    && $this->hasSustainedPositive($history, $signal, $minute)) {
                    $causes = array_values(array_diff($causes, [$cause]));
                }
            } elseif ($baseline && $streak && $currentState === 'zero') {
                $causes[] = $cause;
            }
        }

        $causes = $this->activityDrops($evidence, $history, $previous, $contiguous, $causes, $minute, $nodeIncident);
        sort($causes);
        // Recover exactly the affected signals. An unrelated unavailable
        // counter must not strand an otherwise fully recovered incident.
        // Positive online users establish activity even when upstream RX/TX is missing.
        // Confirmed zero-traffic or zero-online incidents still take precedence.
        $outcome = $causes !== []
            ? (array_intersect(['online_zero', 'traffic_zero'], $causes) !== [] ? 'disrupted' : 'suspicious')
            : ($active !== [] || self::online($evidence) === 'positive' ? 'healthy' : 'unknown');
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
                $this->alerts->sync($id, 'node');
                // A new Node signal may join an already-open regional
                // incident. Confirm that signal too, not only first opens.
                if (! isset($before['affected_modules']['node'])
                    && isset($after['affected_modules']['node'])) {
                    $this->regional->queueConfirmation($uuid);
                }
            }
        }, 3);
    }

    /** @param list<string> $present */
    private function unknownForKnownNodes(CarbonImmutable $minute, array $present = []): void
    {
        // UNKNOWN must not refresh a removed Node's eligibility forever.
        // Only an actual non-UNKNOWN Node observation from the last 30 minutes
        // can justify recording the absence of a formerly known Node.
        $known = DB::table('filtering_checks')->where('module', 'node')
            ->where('outcome', '!=', 'unknown')
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

    /**
     * Compare against this Node's recent median, never against other Nodes or
     * a fixed time-of-day profile. Retain the trigger baseline across later
     * observations so a prolonged drop cannot normalize itself away.
     *
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $previous
     * @param  list<string>  $causes
     * @param  array<string, mixed>  $nodeIncident
     * @return list<string>
     */
    private function activityDrops(
        array &$evidence,
        Collection $history,
        array $previous,
        bool $contiguous,
        array $causes,
        CarbonImmutable $minute,
        array $nodeIncident,
    ): array {
        $onlineActive = in_array('online_drop', $causes, true);
        $trafficActive = in_array('traffic_drop', $causes, true);
        $onlineReference = $onlineActive
            ? $this->savedReference($history, $nodeIncident, 'online_reference', self::MIN_ONLINE_BASELINE)
            : $this->medianReference($history, 'online', self::MIN_ONLINE_BASELINE);
        $trafficReference = $trafficActive
            ? $this->savedReference($history, $nodeIncident, 'traffic_reference', self::MIN_TRAFFIC_BASELINE)
            : $this->medianReference($history, 'traffic', self::MIN_TRAFFIC_BASELINE);

        $onNow = self::activityValue($evidence, 'online');
        $onBefore = self::activityValue($previous, 'online');
        $rateNow = self::activityValue($evidence, 'traffic');
        $rateBefore = self::activityValue($previous, 'traffic');
        $onlinePct = self::decline($onlineReference, $onNow);
        $priorOnlinePct = self::decline($onlineReference, $onBefore);
        $trafficPct = self::decline($trafficReference, $rateNow);
        $priorTrafficPct = self::decline($trafficReference, $rateBefore);
        $joint = $contiguous && $onNow !== null && $onNow > 0 && $onBefore !== null && $onBefore > 0
            && $rateNow !== null && $rateNow > 0 && $rateBefore !== null && $rateBefore > 0
            && $onlinePct !== null && $priorOnlinePct !== null
            && $trafficPct !== null && $priorTrafficPct !== null
            && min($onlinePct, $priorOnlinePct, $trafficPct, $priorTrafficPct) >= self::COMBINED_DROP_PERCENT;

        foreach ([
            'online_drop' => ['online', $onlineReference, $onlinePct, $priorOnlinePct, self::ONLINE_DROP_PERCENT],
            'traffic_drop' => ['traffic', $trafficReference, $trafficPct, $priorTrafficPct, self::TRAFFIC_DROP_PERCENT],
        ] as $cause => [$signal, $reference, $currentPct, $previousPct, $threshold]) {
            $active = in_array($cause, $causes, true);
            if ($active) {
                // A relative traffic decline alone is not evidence that a
                // connection has failed. Retire previously opened traffic-only
                // signals after two valid, positive observations, or when a
                // stronger zero signal is already present. Never use missing
                // telemetry to claim recovery.
                if ($cause === 'traffic_drop' && ! in_array('online_drop', $causes, true)) {
                    $stronger = in_array('online_zero', $causes, true)
                        || in_array('traffic_zero', $causes, true);
                    $positive = $contiguous && $onNow !== null && $onNow > 0
                        && $onBefore !== null && $onBefore > 0
                        && $rateNow !== null && $rateNow > 0
                        && $rateBefore !== null && $rateBefore > 0;
                    if ($stronger || $positive) {
                        $causes = array_values(array_diff($causes, ['traffic_drop']));
                    } elseif ($reference !== null) {
                        $evidence['traffic_reference'] = $reference;
                    }

                    continue;
                }

                $observed = self::activityValue($evidence, $signal);
                if ($reference !== null && $observed !== null
                    && $observed >= (int) ceil($reference * self::RECOVERY_PERCENT_OF_BASELINE / 100)
                    && $this->hasSustainedRecovery($history, $signal, $minute, $reference)) {
                    $causes = array_values(array_diff($causes, [$cause]));
                } elseif ($reference !== null) {
                    $evidence[$signal === 'online' ? 'online_reference' : 'traffic_reference'] = $reference;
                }

                continue;
            }

            $currentValue = self::activityValue($evidence, $signal);
            $previousValue = self::activityValue($previous, $signal);
            // The independent existing zero signals own complete outages.
            if ($reference !== null && $contiguous && $currentValue !== null && $currentValue > 0
                && $previousValue !== null && $previousValue > 0
                && $currentPct !== null && $previousPct !== null
                && (($currentPct >= $threshold && $previousPct >= $threshold) || $joint)) {
                $evidence[$signal === 'online' ? 'online_reference' : 'traffic_reference'] = $reference;
                if ($cause === 'traffic_drop') {
                    // Keep a measurable signal in the 24-hour check history,
                    // without opening a noisy traffic-only incident.
                    $evidence['traffic_drop_percent'] = $currentPct;
                    if (! in_array('online_drop', $causes, true)) {
                        continue;
                    }
                }
                $causes[] = $cause;
            }
        }

        return $causes;
    }

    /** @param array<string,mixed> $fields */
    private static function activityValue(array $fields, string $signal): ?int
    {
        if ($signal === 'online') {
            $value = $fields['online_users'] ?? null;

            return is_int($value) && $value >= 0 ? $value : null;
        }

        $rx = $fields['rx_bytes_per_sec'] ?? null;
        $tx = $fields['tx_bytes_per_sec'] ?? null;

        return is_int($rx) && is_int($tx) && $rx >= 0 && $tx >= 0 ? max($rx, $tx) : null;
    }

    private static function decline(?int $reference, ?int $value): ?int
    {
        if ($reference === null || $reference <= 0 || $value === null) {
            return null;
        }

        return max(0, (int) floor(100 * (1 - $value / $reference)));
    }

    private function medianReference(Collection $history, string $signal, int $minimum): ?int
    {
        $values = [];
        foreach ($history as $check) {
            $value = self::activityValue($this->parseEvidence($check->evidence), $signal);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        if (count($values) < self::MIN_BASELINE_SAMPLES) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $median = $values[intdiv(count($values), 2)];

        return $median >= $minimum ? $median : null;
    }

    /** @param  array<string, mixed>  $nodeIncident */
    private function savedReference(Collection $history, array $nodeIncident, string $name, int $minimum): ?int
    {
        $saved = $nodeIncident[$name] ?? null;
        if (is_int($saved) && $saved >= $minimum) {
            return $saved;
        }

        foreach ($history as $check) {
            $fields = $this->parseEvidence($check->evidence);
            $value = $fields[$name] ?? null;
            if (is_int($value) && $value >= $minimum) {
                return $value;
            }
        }

        return null; // UNKNOWN must never manufacture a recovered baseline.
    }

    private function hasSustainedRecovery(
        Collection $history,
        string $signal,
        CarbonImmutable $minute,
        int $reference,
    ): bool {
        if ($signal === 'traffic') {
            return $this->hasMostlyRecoveredTraffic($history, $minute, $reference);
        }

        $cursor = $minute;
        $minimum = (int) ceil($reference * self::RECOVERY_PERCENT_OF_BASELINE / 100);
        foreach ($history as $check) {
            $observed = CarbonImmutable::parse($check->observed_at);
            $gap = $cursor->timestamp - $observed->timestamp;
            $value = self::activityValue($this->parseEvidence($check->evidence), $signal);
            if ($gap <= 0 || $gap > self::WINDOW_SECONDS || $value === null || $value < $minimum) {
                return false;
            }
            if ($minute->timestamp - $observed->timestamp >= self::RECOVERY_SECONDS) {
                return true;
            }
            $cursor = $observed;
        }

        return false;
    }

    /**
     * Traffic rates fluctuate even on usable Nodes: require six valid,
     * contiguous samples spanning ten minutes, at least five at >=80% of
     * the saved reference and the newest two above the threshold.
     * The caller has already verified the current observation is above it.
     * Missing, invalid or stale observations never count as recovery.
     */
    private function hasMostlyRecoveredTraffic(
        Collection $history,
        CarbonImmutable $minute,
        int $reference,
    ): bool {
        $cursor = $minute;
        $minimum = (int) ceil($reference * self::RECOVERY_PERCENT_OF_BASELINE / 100);
        $samples = 1; // The current valid, above-threshold observation.
        $above = 1;

        foreach ($history as $check) {
            $observed = CarbonImmutable::parse($check->observed_at);
            $gap = $cursor->timestamp - $observed->timestamp;
            $value = self::activityValue($this->parseEvidence($check->evidence), 'traffic');
            if ($gap <= 0 || $gap > self::WINDOW_SECONDS || $value === null
                || ($samples === 1 && $value < $minimum)) {
                return false;
            }

            $samples++;
            $above += (int) ($value >= $minimum);
            if ($samples === 6) {
                return $above >= 5
                    && $minute->timestamp - $observed->timestamp >= self::RECOVERY_SECONDS;
            }

            $cursor = $observed;
        }

        return false;
    }

    /**
     * Recover an affected signal only after ten uninterrupted minutes of
     * valid positive samples. An unknown/zero sample or stale gap resets the
     * sequence; existing 30-minute bounded history is sufficient.
     */
    private function hasSustainedPositive(
        Collection $history,
        string $signal,
        CarbonImmutable $minute,
    ): bool {
        $cursor = $minute;
        foreach ($history as $check) {
            $observed = CarbonImmutable::parse($check->observed_at);
            $gap = $cursor->timestamp - $observed->timestamp;
            if ($gap <= 0 || $gap > self::WINDOW_SECONDS
                || self::$signal($this->parseEvidence($check->evidence)) !== 'positive') {
                return false;
            }
            if ($minute->timestamp - $observed->timestamp >= self::RECOVERY_SECONDS) {
                return true;
            }
            $cursor = $observed;
        }

        return false;
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
