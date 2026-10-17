<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodeTelemetry;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnexpectedValueException;

/** MET-02: narrow, on-demand traffic reporting; no persistent analytics store. */
final readonly class ReadAdminTrafficReport
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ManageAdminNodes $nodes,
        private RemnawaveApiClientFactory $factory,
    ) {}

    /**
     * @return array{state:string,days:int,start:string,end:string,trend:list<array{date:string,total:int}>,nodes:list<array{uuid:string,name:string,total:int|null}>,receivedAt:string|null}
     */
    public function summary(int $actorId, string $requestedDays): array
    {
        $this->access->handle($actorId);
        $days = self::days($requestedDays);
        [$start, $end] = self::dates($days);

        $empty = [
            'state' => 'unavailable', 'days' => $days, 'start' => $start, 'end' => $end,
            'trend' => [], 'nodes' => [], 'receivedAt' => null,
        ];

        try {
            $nodes = $this->nodes->all($actorId);
            $telemetry = new RemnawaveNodeTelemetry($this->factory->make());
            $history = $telemetry->usageHistory($start, $end);
            $trend = self::trend($history, $start, $end);
            $perNode = self::perNode($history, $nodes);

            return [
                'state' => 'available', 'days' => $days, 'start' => $start, 'end' => $end,
                'trend' => $trend, 'nodes' => $perNode,
                'receivedAt' => now()->timezone('Asia/Tehran')->format('Y-m-d H:i'),
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * @return array{state:string,days:int,start:string,end:string,name:string|null,users:list<array{username:string,total:int}>,receivedAt:string|null}
     */
    public function nodeUsers(int $actorId, string $requestedDays, string $uuid): array
    {
        $this->access->handle($actorId);
        $days = self::days($requestedDays);
        [$start, $end] = self::dates($days);
        if (preg_match('/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D', $uuid) !== 1) {
            throw ValidationException::withMessages(['node' => 'Invalid node identifier.']);
        }
        $empty = [
            'state' => 'unavailable', 'days' => $days, 'start' => $start, 'end' => $end,
            'name' => null, 'users' => [], 'receivedAt' => null,
        ];

        try {
            $nodes = $this->nodes->all($actorId);
            $matches = array_values(array_filter($nodes, static fn (array $node): bool => $node['uuid'] === strtolower($uuid)));
            if (count($matches) !== 1) {
                // Unknown or deleted Node must never be used as an arbitrary provider target.
                return $empty;
            }
            $users = (new RemnawaveNodeTelemetry($this->factory->make()))
                ->topUsers($matches[0]['uuid'], $start, $end);

            return [
                'state' => 'available', 'days' => $days, 'start' => $start, 'end' => $end,
                'name' => $matches[0]['name'], 'users' => $users,
                'receivedAt' => now()->timezone('Asia/Tehran')->format('Y-m-d H:i'),
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    private static function days(string $raw): int
    {
        if (! in_array($raw, ['7', '30'], true)) {
            throw ValidationException::withMessages(['range' => 'Unsupported metrics range.']);
        }

        return (int) $raw;
    }

    /** @return array{string,string} */
    private static function dates(int $days): array
    {
        $end = new DateTimeImmutable('today', new DateTimeZone('UTC'));

        return [$end->modify('-'.($days - 1).' days')->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /**
     * @param  array<string,mixed>  $history
     * @return list<array{date:string,total:int}>
     */
    private static function trend(array $history, string $start, string $end): array
    {
        $categories = $history['categories'];
        $points = $history['sparklineData'];
        if (count($categories) !== count($points) || count($points) > 30) {
            throw new UnexpectedValueException('Inconsistent traffic series.');
        }

        $out = [];
        $seen = [];
        foreach ($categories as $i => $date) {
            if (! is_string($date) || ! self::calendarDate($date) || $date < $start || $date > $end
                || isset($seen[$date]) || ($total = RemnawaveNodeTelemetry::nonNegativeBytes($points[$i])) === null) {
                throw new UnexpectedValueException('Invalid traffic daily observation.');
            }
            $seen[$date] = true;
            $out[] = ['date' => $date, 'total' => $total];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $out;
    }

    /**
     * Per-node aggregates are shown only when the schema identifies a Node
     * by its unique current name and each daily point has a verified byte value.
     *
     * @param  array<string,mixed>  $history
     * @param  list<array{uuid:string,name:string,connected:bool,disabled:bool}>  $nodes
     * @return list<array{uuid:string,name:string,total:int|null}>
     */
    private static function perNode(array $history, array $nodes): array
    {
        $series = $history['series'];
        if (count($series) > 200) {
            throw new UnexpectedValueException('Oversized Node traffic series.');
        }

        $byName = [];
        foreach ($series as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || ! is_array($entry['data'] ?? null)
                || ! array_is_list($entry['data']) || count($entry['data']) > 30 || isset($byName[$entry['name']])) {
                // Schema variants must not be interpreted as zero usage.
                throw new UnexpectedValueException('Invalid Node traffic series.');
            }
            $sum = 0;
            foreach ($entry['data'] as $value) {
                $bytes = RemnawaveNodeTelemetry::nonNegativeBytes($value);
                if ($bytes === null || $bytes > PHP_INT_MAX - $sum) {
                    throw new UnexpectedValueException('Invalid Node usage bytes.');
                }
                $sum += $bytes;
            }
            $byName[$entry['name']] = $entry['data'] === [] ? null : $sum;
        }

        $nameCounts = array_count_values(array_column($nodes, 'name'));
        $out = [];
        foreach ($nodes as $node) {
            $out[] = [
                'uuid' => $node['uuid'], 'name' => $node['name'],
                'total' => $nameCounts[$node['name']] === 1 ? ($byName[$node['name']] ?? null) : null,
            ];
        }

        return $out;
    }

    private static function calendarDate(string $raw): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $raw;
    }
}
