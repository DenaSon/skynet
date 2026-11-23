<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudReadClient;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * ARI-02A: trusted-root read projections of Cloud data.
 * Never pass provider envelopes, authentication or arbitrary errors to Telegram.
 * No persistence, write client, scheduler or provider mutations.
 */
final readonly class ReadAdminArianetCloud
{
    private const SERVER_PAGE_SIZE = 8;

    private const ACTION_PAGE_SIZE = 5;

    private const PLAN_PAGE_SIZE = 5;

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ArianetCloudReadClient $cloud,
    ) {}

    /** @return array<string, mixed> */
    public function browse(int $actorId, int $page): array
    {
        $this->access->handle($actorId);
        $this->pageNumber($page);
        $result = $this->page($this->cloud->servers($page, self::SERVER_PAGE_SIZE), $page, self::SERVER_PAGE_SIZE);
        $items = array_map(fn (array $row): array => $this->serverSummary($row), $result['items']);
        $monthly = null;

        // A single-server account can show its complete estimate with one
        // additional GET; never fan out N per-server cost reads on a list page.
        if ($result['pages'] === 1 && count($items) === 1) {
            try {
                $cost = $this->costs($actorId, $items[0]['id']);
                $monthly = $cost['monthlyRials'];
            } catch (Throwable) {
                // Unknown is safer than an incomplete or guessed aggregate.
            }
        }

        return ['items' => $items, 'page' => $page, 'pages' => $result['pages'],
            'total' => $result['total'], 'monthlyRials' => $monthly];
    }

    /** @return array<string, mixed> */
    public function detail(int $actorId, int $serverId): array
    {
        $this->access->handle($actorId);
        $row = $this->object($this->cloud->server($this->positiveId($serverId)), $serverId);
        $summary = $this->serverSummary($row);
        $change = $row['ip_change'] ?? null;
        if (! is_array($change) || array_is_list($change)) {
            $change = [];
        }

        return $summary + [
            'ipv6' => self::ip($row['ipv6'] ?? null, FILTER_FLAG_IPV6),
            'diskGb' => self::nonnegative($row['disk_gb'] ?? null),
            'planId' => self::positive($row['plan_id'] ?? null),
            'changeAllowed' => is_bool($change['allowed'] ?? null) ? $change['allowed'] : null,
            'freeLeft' => self::nonnegative($change['free_left'] ?? null),
            'changeUsed' => self::nonnegative($change['used'] ?? null),
            'pending' => is_bool($change['pending'] ?? null) ? $change['pending'] : null,
            'changeFeeRials' => ($change['currency'] ?? null) === 'IRR'
                ? self::nonnegative($change['fee'] ?? null) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function costs(int $actorId, int $serverId): array
    {
        $this->access->handle($actorId);
        $data = $this->object($this->cloud->costs($this->positiveId($serverId)), $serverId, 'server_id');
        $totals = $data['totals'] ?? null;
        if (! is_array($totals) || ! array_is_list($totals) || count($totals) > 10) {
            throw new RuntimeException('Invalid Cloud cost totals.');
        }
        $hourly = null;
        $monthly = null;
        foreach ($totals as $total) {
            if (! is_array($total) || array_is_list($total)) {
                throw new RuntimeException('Invalid Cloud cost currency.');
            }
            if (($total['currency'] ?? null) === 'IRR') {
                $hourly = self::nonnegative($total['hourly_price'] ?? null);
                $monthly = self::nonnegative($total['monthly_price'] ?? null);
            }
        }

        $lines = $data['lines'] ?? null;
        if (! is_array($lines) || ! array_is_list($lines) || count($lines) > 50) {
            throw new RuntimeException('Invalid Cloud cost line items.');
        }
        $items = [];
        foreach (array_slice($lines, 0, 8) as $line) {
            if (! is_array($line) || array_is_list($line)) {
                throw new RuntimeException('Invalid Cloud cost line.');
            }
            if (($line['currency'] ?? null) !== 'IRR') {
                continue;
            }
            $items[] = [
                'name' => self::short($line['name'] ?? null, 40),
                'type' => self::short($line['type'] ?? null, 24),
                'monthlyRials' => self::nonnegative($line['monthly_price'] ?? null),
            ];
        }

        return [
            'serverId' => $serverId,
            'hourlyRials' => $hourly,
            'monthlyRials' => $monthly,
            'hoursPerMonth' => self::positive($data['hours_per_month'] ?? null),
            'lines' => $items,
            'additionalLines' => max(0, count($lines) - 8),
            'snapshots' => self::nonnegative($data['snapshots']['count'] ?? null),
            'backups' => self::nonnegative($data['kept_backups']['count'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    public function history(int $actorId, int $serverId, int $page): array
    {
        $this->access->handle($actorId);
        $this->positiveId($serverId);
        $this->pageNumber($page);
        $result = $this->page($this->cloud->serverActions($serverId, $page, self::ACTION_PAGE_SIZE),
            $page, self::ACTION_PAGE_SIZE);
        $items = [];
        foreach ($result['items'] as $row) {
            $id = self::positive($row['id'] ?? null);
            if ($id === null) {
                throw new RuntimeException('Invalid Cloud action ID.');
            }
            $items[] = [
                'id' => $id,
                'command' => self::short($row['command'] ?? null, 30),
                'status' => self::short($row['status'] ?? null, 20),
                'startedAt' => self::short($row['started_at'] ?? null, 50),
            ];
        }

        return ['items' => $items, 'page' => $page, 'pages' => $result['pages']];
    }

    /** @return list<array{code:string,name:string}> */
    public function datacenters(int $actorId): array
    {
        $this->access->handle($actorId);
        $data = $this->listData($this->cloud->datacenters(), 'datacenters');
        if (count($data) > 30) {
            throw new RuntimeException('Cloud datacenter list is too long.');
        }
        $items = [];
        foreach ($data as $row) {
            $code = is_string($row) ? $row : (is_array($row) ? ($row['code'] ?? null) : null);
            if (! is_string($code) || preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $code) !== 1) {
                throw new RuntimeException('Invalid Cloud datacenter code.');
            }
            $items[$code] = [
                'code' => $code,
                'name' => is_array($row) ? self::short($row['name'] ?? $code, 35) : $code,
            ];
        }

        return array_values($items);
    }

    /** @return array<string, mixed> */
    public function plans(int $actorId, string $datacenter, int $page): array
    {
        $this->access->handle($actorId);
        $this->pageNumber($page);
        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $datacenter) !== 1) {
            throw new InvalidArgumentException('Invalid Cloud datacenter code.');
        }
        $list = $this->listData($this->cloud->plans($datacenter), 'plans');
        if (count($list) > 200) {
            throw new RuntimeException('Cloud plan catalog exceeds the bounded view.');
        }
        $pages = max(1, (int) ceil(count($list) / self::PLAN_PAGE_SIZE));
        if ($page > $pages) {
            throw new InvalidArgumentException('Cloud plan page out of range.');
        }
        $items = [];
        foreach (array_slice($list, ($page - 1) * self::PLAN_PAGE_SIZE, self::PLAN_PAGE_SIZE) as $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new RuntimeException('Invalid Cloud plan.');
            }
            $id = self::positive($row['id'] ?? null);
            if ($id === null) {
                throw new RuntimeException('Invalid Cloud plan ID.');
            }
            $items[] = [
                'id' => $id,
                'name' => self::short($row['name'] ?? null, 35),
                'cores' => self::positive($row['cores'] ?? null),
                'memoryMb' => self::nonnegative($row['memory_mb'] ?? null),
                'diskGb' => self::nonnegative($row['disk_gb'] ?? null),
                'hourlyRials' => self::planPrice($row, $datacenter, 'hourly'),
                'monthlyRials' => self::planPrice($row, $datacenter, 'monthly'),
            ];
        }

        return ['items' => $items, 'page' => $page, 'pages' => $pages, 'datacenter' => $datacenter];
    }

    /** @return array{items:list<array<string,mixed>>,pages:int,total:int|null} */
    private function page(array $envelope, int $requested, int $size): array
    {
        $items = $this->listData($envelope, 'items');
        $meta = $envelope['meta']['pagination'] ?? null;
        if (! is_array($meta) || ! is_int($meta['page'] ?? null) || $meta['page'] !== $requested
            || ! is_int($meta['last_page'] ?? null) || $meta['last_page'] < 0
            || $meta['last_page'] > 100 || count($items) > $size) {
            throw new RuntimeException('Invalid Cloud pagination.');
        }
        $pages = max(1, $meta['last_page']);
        if ($requested > $pages) {
            throw new RuntimeException('Cloud page is not available.');
        }
        $out = [];
        foreach ($items as $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new RuntimeException('Invalid Cloud list item.');
            }
            $out[] = $row;
        }

        return ['items' => $out, 'pages' => $pages, 'total' => self::nonnegative($meta['total'] ?? null)];
    }

    /** @return list<mixed> */
    private function listData(array $envelope, string $key): array
    {
        $data = $envelope['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException('Invalid Cloud list.');
        }
        $list = array_is_list($data) ? $data : ($data[$key] ?? null);
        if (! is_array($list) || ! array_is_list($list)) {
            throw new RuntimeException('Invalid Cloud list shape.');
        }

        return $list;
    }

    /** @return array<string, mixed> */
    private function object(array $envelope, int $id, string $idField = 'id'): array
    {
        $data = $envelope['data'] ?? null;
        if (! is_array($data) || array_is_list($data) || ($data[$idField] ?? null) !== $id) {
            throw new RuntimeException('Invalid Cloud resource identity.');
        }

        return $data;
    }

    /** @param array<string,mixed> $row
     * @return array{id:int,name:string,status:string,datacenter:string,ipv4:string|null}
     */
    private function serverSummary(array $row): array
    {
        $id = self::positive($row['id'] ?? null);
        if ($id === null) {
            throw new RuntimeException('Invalid Cloud server ID.');
        }

        return [
            'id' => $id,
            'name' => self::short($row['name'] ?? null, 48),
            'status' => self::short($row['status'] ?? null, 25),
            'datacenter' => self::short($row['datacenter'] ?? null, 32),
            'ipv4' => self::ip($row['ipv4'] ?? null, FILTER_FLAG_IPV4),
        ];
    }

    private function positiveId(int $id): int
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Cloud ID must be positive.');
        }

        return $id;
    }

    private function pageNumber(int $page): void
    {
        if ($page < 1 || $page > 100) {
            throw new InvalidArgumentException('Cloud page out of range.');
        }
    }

    private static function positive(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private static function nonnegative(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private static function ip(mixed $value, int $flag): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP, $flag) !== false ? $value : null;
    }

    private static function short(mixed $value, int $limit): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'نامشخص';
        }
        $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value));

        return mb_substr($clean, 0, $limit);
    }

    /** @param array<string,mixed> $plan */
    private static function planPrice(array $plan, string $datacenter, string $period): ?int
    {
        $name = $period === 'hourly' ? 'price_hourly' : 'price_monthly';
        $plain = $plan[$name] ?? null;
        if (is_int($plain) && $plain >= 0 && ($plan['currency'] ?? null) === 'IRR') {
            return $plain;
        }
        $prices = $plan['prices'] ?? null;
        if (is_array($prices) && array_is_list($prices)) {
            foreach ($prices as $price) {
                if (! is_array($price) || ($price['location'] ?? null) !== $datacenter) {
                    continue;
                }
                $value = $price[$name] ?? null;
                if (is_array($value) && ($value['currency'] ?? null) === 'IRR') {
                    return self::nonnegative($value['net'] ?? null);
                }
            }
        }

        return null;
    }
}
