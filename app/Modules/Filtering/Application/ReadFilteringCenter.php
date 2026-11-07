<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Illuminate\Support\Facades\DB;

/**
 * FLT-04: authorized, bounded SQL read model. No Remnawave, Check-Host,
 * network or user-controlled target evaluation in Telegram callbacks.
 */
final readonly class ReadFilteringCenter
{
    private const PAGE_SIZE = 6;

    private const MAX_NODES = 100;

    private const MAX_PAGE = 20;

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ScanRegionalFiltering $regional,
    ) {}

    /** @return array<string,mixed> */
    public function overview(int $userId): array
    {
        $this->access->handle($userId);
        $nodes = $this->snapshots();
        $counts = array_fill_keys(['healthy', 'suspicious', 'disrupted', 'unknown'], 0);
        $nodeAt = null;
        $regionAt = null;
        foreach ($nodes as $node) {
            $counts[$node['state']]++;
            $nodeAt = self::newer($nodeAt, $node['node_at']);
            $regionAt = self::newer($regionAt, $node['regional_at']);
        }

        return [
            'counts' => $counts,
            'total' => count($nodes),
            'node_enabled' => config('filtering.node_monitor_enabled', false) === true,
            'regional_enabled' => config('filtering.regional_monitor_enabled', false) === true,
            'node_at' => $nodeAt,
            'regional_at' => $regionAt,
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,page:int,pages:int,total:int} */
    public function nodes(int $userId, int $page = 1): array
    {
        $this->access->handle($userId);

        return $this->page($this->snapshots(), $page);
    }

    /** @return array<string,mixed>|null */
    public function node(int $userId, string $uuid): ?array
    {
        $this->access->handle($userId);
        if (! self::validUuid($uuid)) {
            return null;
        }

        foreach ($this->snapshots() as $node) {
            if ($node['uuid'] === strtolower($uuid)) {
                return $node;
            }
        }

        return null;
    }

    /** @return array{rows:list<array<string,mixed>>,page:int,pages:int,total:int} */
    public function history(int $userId, int $page = 1): array
    {
        $this->access->handle($userId);

        $names = $this->nodeNames();
        $query = DB::table('filtering_incidents')->where(static function ($q): void {
            $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', now()->subDay());
        });
        $total = (clone $query)->count();
        $page = self::pageNumber($page, $total);
        $rows = $query->orderByDesc('opened_at')->orderByDesc('id')
            ->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE)
            ->get(['id', 'node_uuid', 'state', 'opened_at', 'last_observed_at', 'resolved_at', 'affected_modules'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'uuid' => (string) $row->node_uuid,
                'label' => $names[(string) $row->node_uuid] ?? strtoupper(substr((string) $row->node_uuid, 0, 8)),
                'state' => (string) $row->state,
                'opened_at' => (string) $row->opened_at,
                'last_at' => (string) $row->last_observed_at,
                'resolved_at' => $row->resolved_at === null ? null : (string) $row->resolved_at,
                'causes' => self::causes(self::decode($row->affected_modules)),
            ])->all();

        return ['rows' => $rows, 'page' => $page, 'pages' => self::pages($total), 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function incident(int $userId, int $id): ?array
    {
        $this->access->handle($userId);
        if ($id < 1) {
            return null;
        }
        $row = DB::table('filtering_incidents')->where('id', $id)
            ->where(static function ($q): void {
                $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', now()->subDay());
            })
            ->first(['id', 'node_uuid', 'state', 'opened_at', 'last_observed_at', 'resolved_at', 'affected_modules']);
        if ($row === null) {
            return null;
        }

        $names = $this->nodeNames();

        return [
            'id' => (int) $row->id,
            'uuid' => (string) $row->node_uuid,
            'label' => $names[(string) $row->node_uuid] ?? strtoupper(substr((string) $row->node_uuid, 0, 8)),
            'state' => (string) $row->state,
            'opened_at' => (string) $row->opened_at,
            'last_at' => (string) $row->last_observed_at,
            'resolved_at' => $row->resolved_at === null ? null : (string) $row->resolved_at,
            'causes' => self::causes(self::decode($row->affected_modules)),
        ];
    }

    /**
     * Only a known Node can be requested. The existing FLT-03 queue gate
     * revalidates current RW direct targets in the worker.
     *
     * @return 'queued'|'disabled'|'missing'|'cooldown'
     */
    public function recheck(int $userId, string $uuid): string
    {
        $this->access->handle($userId);
        if (! self::validUuid($uuid) || $this->node($userId, $uuid) === null) {
            return 'missing';
        }
        if (config('filtering.regional_monitor_enabled', false) !== true) {
            return 'disabled';
        }

        return $this->regional->queueConfirmation(strtolower($uuid)) ? 'queued' : 'cooldown';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function snapshots(): array
    {
        $activeUuids = DB::table('filtering_incidents')->whereNull('resolved_at')
            ->orderByDesc('last_observed_at')->limit(self::MAX_NODES)
            ->pluck('node_uuid')->all();
        $recentUuids = DB::table('filtering_checks')->where('observed_at', '>=', now()->subDay())
            ->distinct()->limit(self::MAX_NODES)->pluck('node_uuid')->all();
        $uuids = array_slice(array_values(array_unique([...$activeUuids, ...$recentUuids])), 0, self::MAX_NODES);
        if ($uuids === []) {
            return [];
        }
        sort($uuids);
        $names = $this->nodeNames();

        // One indexed max(timestamp) per Node/module; no unbounded raw history
        // read or N+1 provider calls. MySQL and SQLite support joinSub.
        $max = DB::table('filtering_checks')->select('node_uuid', 'module')
            ->selectRaw('MAX(observed_at) AS last_at')
            ->whereIn('node_uuid', $uuids)->where('observed_at', '>=', now()->subDay())
            ->groupBy('node_uuid', 'module');
        $checks = DB::table('filtering_checks as c')
            ->joinSub($max, 'latest', static function (JoinClause $join): void {
                $join->on('c.node_uuid', '=', 'latest.node_uuid')
                    ->on('c.module', '=', 'latest.module')
                    ->on('c.observed_at', '=', 'latest.last_at');
            })
            ->get(['c.node_uuid', 'c.module', 'c.outcome', 'c.observed_at', 'c.evidence']);

        $latest = [];
        foreach ($checks as $check) {
            $latest[(string) $check->node_uuid][(string) $check->module] = [
                'outcome' => (string) $check->outcome,
                'at' => (string) $check->observed_at,
                'evidence' => self::decode($check->evidence),
            ];
        }

        $incidents = DB::table('filtering_incidents')->whereIn('active_node_uuid', $uuids)
            ->get(['node_uuid', 'state', 'affected_modules'])->keyBy('node_uuid');
        $items = [];
        foreach ($uuids as $uuid) {
            $node = $latest[$uuid]['node'] ?? null;
            $region = $latest[$uuid]['regional'] ?? null;
            $incident = $incidents->get($uuid);
            $active = $incident === null ? [] : self::decode($incident->affected_modules);
            $state = $incident === null ? self::observedState($node, $region) : (string) $incident->state;
            $items[] = [
                'uuid' => $uuid,
                'label' => $names[$uuid] ?? strtoupper(substr($uuid, 0, 8)),
                'state' => $state,
                'causes' => self::causes($active),
                'node_at' => $node['at'] ?? null,
                'regional_at' => $region['at'] ?? null,
                'online' => $node['evidence']['online_users'] ?? null,
                'rx' => $node['evidence']['rx_bytes_per_sec'] ?? null,
                'tx' => $node['evidence']['tx_bytes_per_sec'] ?? null,
                'node_outcome' => $node['outcome'] ?? 'unknown',
                'regional_outcome' => $region['outcome'] ?? 'unknown',
                'regional' => $region['evidence'] ?? [],
                'node_fresh' => self::fresh($node['at'] ?? null, 300),
                'regional_fresh' => self::fresh($region['at'] ?? null, 1320),
            ];
        }

        return $items;
    }

    /** @param array<string,mixed>|null $node
     * @param  array<string,mixed>|null  $region
     */
    private static function observedState(?array $node, ?array $region): string
    {
        // Node activity is the primary overall status when enabled. Regional
        // reachability remains explicitly UNKNOWN until verified; a confirmed
        // regional disruption still takes precedence via the active incident.
        if (config('filtering.node_monitor_enabled', false) === true) {
            return $node !== null && $node['outcome'] === 'healthy'
                && self::fresh($node['at'], 300) ? 'healthy' : 'unknown';
        }
        if (config('filtering.regional_monitor_enabled', false) === true) {
            return $region !== null && $region['outcome'] === 'healthy'
                && self::fresh($region['at'], 1320) ? 'healthy' : 'unknown';
        }

        return 'unknown';
    }

    private static function fresh(?string $at, int $seconds): bool
    {
        if ($at === null) {
            return false;
        }
        $age = now()->timestamp - CarbonImmutable::parse($at, 'UTC')->timestamp;

        return $age >= 0 && $age <= $seconds;
    }

    private static function newer(?string $old, ?string $new): ?string
    {
        return $new !== null && ($old === null || $new > $old) ? $new : $old;
    }

    /** @return array<string,string> */
    private function nodeNames(): array
    {
        try {
            $names = Cache::get(FilteringFoundation::NODE_NAME_CACHE_KEY);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($names) || count($names) > self::MAX_NODES) {
            return [];
        }

        $safe = [];
        foreach ($names as $uuid => $name) {
            if (! is_string($uuid) || ! self::validUuid($uuid)
                || ! is_string($name) || trim($name) !== $name
                || $name === '' || mb_strlen($name) > 80
                || preg_match('/[\x00-\x1F\x7F]/u', $name) !== 0) {
                continue;
            }
            $safe[$uuid] = $name;
        }

        return $safe;
    }

    /** @return array<string,mixed> */
    private static function decode(mixed $json): array
    {
        if (! is_string($json)) {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $modules
     * @return list<string>
     */
    private static function causes(array $modules): array
    {
        $allowed = ['online_zero', 'traffic_zero', 'regional_possible', 'regional_likely'];
        $causes = [];
        foreach ($modules as $module) {
            if (! is_array($module) || ! is_array($module['causes'] ?? null)) {
                continue;
            }
            foreach ($module['causes'] as $cause) {
                if (is_string($cause) && in_array($cause, $allowed, true)) {
                    $causes[$cause] = true;
                }
            }
        }

        return array_keys($causes);
    }

    /** @param list<array<string,mixed>> $items
     * @return array{rows:list<array<string,mixed>>,page:int,pages:int,total:int}
     */
    private function page(array $items, int $requested): array
    {
        $total = count($items);
        $page = self::pageNumber($requested, $total);

        return [
            'rows' => array_slice($items, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            'page' => $page,
            'pages' => self::pages($total),
            'total' => $total,
        ];
    }

    private static function pages(int $total): int
    {
        return min(self::MAX_PAGE, max(1, (int) ceil($total / self::PAGE_SIZE)));
    }

    private static function pageNumber(int $page, int $total): int
    {
        return max(1, min(self::pages($total), $page));
    }

    private static function validUuid(string $uuid): bool
    {
        return preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $uuid) === 1;
    }
}
