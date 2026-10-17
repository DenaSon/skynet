<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodeTelemetry;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveReadApi;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use UnexpectedValueException;

/**
 * MET-01: live, read-only Super Admin snapshot. Optional data sources fail
 * independently; a missing counter must never be rendered as zero.
 */
final readonly class ReadAdminNodeMetrics
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ManageAdminNodes $nodes,
        private RemnawaveApiClientFactory $factory,
    ) {}

    /**
     * @return array{state:string,nodes:list<array{name:string,connected:bool,disabled:bool,online:int|null}>,total:int,connected:int,enabled:int,onlineTotal:int|null,todayBytes:int|null,weekBytes:int|null,trafficDate:string,receivedAt:string|null}
     */
    public function handle(int $actorId): array
    {
        $this->access->handle($actorId);
        $today = gmdate('Y-m-d');
        $empty = [
            'state' => 'unavailable', 'nodes' => [], 'total' => 0, 'connected' => 0,
            'enabled' => 0, 'onlineTotal' => null, 'todayBytes' => null,
            'weekBytes' => null, 'trafficDate' => $today, 'receivedAt' => null,
        ];

        try {
            $nodes = $this->nodes->all($actorId);
            $client = $this->factory->make();
        } catch (Throwable) {
            return $empty;
        }

        $onlineByUuid = [];
        $telemetry = new RemnawaveNodeTelemetry($client);
        try {
            foreach ($telemetry->onlineUsers() as $row) {
                // Never use remote display names here. Node list is the trusted view.
                $uuid = strtolower($row['uuid']);
                if (isset($onlineByUuid[$uuid])) {
                    throw new UnexpectedValueException('Duplicate Node metrics identifier.');
                }
                $onlineByUuid[$uuid] = $row['usersOnline'];
            }
        } catch (Throwable) {
            $onlineByUuid = [];
        }

        $onlineTotal = null;
        try {
            $onlineTotal = (new RemnawaveReadApi($client))->systemStats()['onlineUsers'];
        } catch (Throwable) {
            // Global unique online count is not equivalent to sum of per-node connections.
        }

        $todayBytes = null;
        $weekBytes = null;
        try {
            $traffic = $telemetry->lastSevenDays();
            $summary = self::trafficSummary($traffic, $today);
            $todayBytes = $summary['today'];
            $weekBytes = $summary['week'];
        } catch (Throwable) {
            // Read-only snapshot remains useful when traffic analytics are unavailable.
        }

        $list = [];
        $connected = 0;
        $enabled = 0;
        foreach ($nodes as $node) {
            if (! $node['disabled']) {
                $enabled++;
                if ($node['connected']) {
                    $connected++;
                }
            }
            $list[] = [
                'name' => $node['name'],
                'connected' => $node['connected'],
                'disabled' => $node['disabled'],
                'online' => $onlineByUuid[$node['uuid']] ?? null,
            ];
        }

        return [
            'state' => 'available',
            'nodes' => $list,
            'total' => count($nodes),
            'connected' => $connected,
            'enabled' => $enabled,
            'onlineTotal' => $onlineTotal,
            'todayBytes' => $todayBytes,
            'weekBytes' => $weekBytes,
            'trafficDate' => $today,
            'receivedAt' => now()->timezone('Asia/Tehran')->format('Y-m-d H:i'),
        ];
    }

    /**
     * The pinned endpoint returns decimal byte strings by Node and calendar day.
     * Never guess at units, reinterpret duplicate records, or overflow PHP integers.
     *
     * @param list<array{nodeName:string,date:string,totalBytes:string}> $rows
     * @return array{today:int|null,week:int|null}
     */
    private static function trafficSummary(array $rows, string $today): array
    {
        if ($rows === [] || count($rows) > 2500) {
            return ['today' => null, 'week' => null];
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $today, new DateTimeZone('UTC'));
        if ($day === false) {
            throw new UnexpectedValueException('Invalid report date.');
        }

        $from = $day->modify('-6 days')->format('Y-m-d');
        $week = 0;
        $todayTotal = 0;
        $todaySeen = false;
        $seen = [];

        foreach ($rows as $row) {
            $date = $row['date'];
            $bytes = $row['totalBytes'];
            if (! preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $bytes)
                || strlen($bytes) > strlen((string) PHP_INT_MAX)
                || (strlen($bytes) === strlen((string) PHP_INT_MAX) && strcmp($bytes, (string) PHP_INT_MAX) > 0)) {
                throw new UnexpectedValueException('Invalid traffic byte count.');
            }

            if ($date < $from || $date > $today) {
                continue;
            }
            $identifier = $row['nodeName']."\0".$date;
            if (isset($seen[$identifier])) {
                throw new UnexpectedValueException('Duplicate Node traffic record.');
            }
            $seen[$identifier] = true;
            $value = (int) $bytes;
            if ($value > PHP_INT_MAX - $week) {
                throw new UnexpectedValueException('Traffic counter overflow.');
            }
            $week += $value;
            if ($date === $today) {
                $todaySeen = true;
                if ($value > PHP_INT_MAX - $todayTotal) {
                    throw new UnexpectedValueException('Daily traffic counter overflow.');
                }
                $todayTotal += $value;
            }
        }

        return ['today' => $todaySeen ? $todayTotal : null, 'week' => $seen === [] ? null : $week];
    }
}
