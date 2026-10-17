<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Remnawave;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Read-only node metrics and traffic queries. This adapter never mutates
 * users/nodes, and callers must not expose raw telemetry to customer UI.
 */
final readonly class RemnawaveNodeTelemetry
{
    public function __construct(private RemnawaveApiClient $client) {}

    /**
     * @return list<array{uuid: string, name: string, usersOnline: int}>
     */
    public function onlineUsers(): array
    {
        $payload = $this->response('SystemController_getNodesMetrics');
        $nodes = $payload['nodes'] ?? null;

        if (! is_array($nodes) || ! array_is_list($nodes)) {
            throw new UnexpectedValueException('Invalid node metrics list.');
        }

        $summary = [];

        foreach ($nodes as $node) {
            if (! is_array($node)
                || ! is_string($node['nodeUuid'] ?? null)
                || ! is_string($node['nodeName'] ?? null)
                || (! is_int($node['usersOnline'] ?? null) && ! is_float($node['usersOnline'] ?? null))
                || $node['usersOnline'] < 0
                || $node['usersOnline'] > PHP_INT_MAX
                || floor((float) $node['usersOnline']) !== (float) $node['usersOnline']) {
                throw new UnexpectedValueException('Invalid node metrics item.');
            }

            $summary[] = [
                'uuid' => $node['nodeUuid'],
                'name' => $node['nodeName'],
                'usersOnline' => (int) $node['usersOnline'],
            ];
        }

        return $summary;
    }

    /**
     * @return list<array{nodeName: string, date: string, totalBytes: string}>
     */
    public function lastSevenDays(): array
    {
        $payload = $this->response('SystemController_getNodesStatistics');
        $records = $payload['lastSevenDays'] ?? null;

        if (! is_array($records) || ! array_is_list($records)) {
            throw new UnexpectedValueException('Invalid node statistics list.');
        }

        $summary = [];

        foreach ($records as $row) {
            if (! is_array($row)
                || ! is_string($row['nodeName'] ?? null)
                || ! is_string($row['date'] ?? null)
                || ! is_string($row['totalBytes'] ?? null)) {
                throw new UnexpectedValueException('Invalid node statistics item.');
            }

            $summary[] = [
                'nodeName' => $row['nodeName'],
                'date' => $row['date'],
                'totalBytes' => $row['totalBytes'],
            ];
        }

        return $summary;
    }

    /**
     * Returns total node traffic analytics in the schema provided by the panel.
     * Business consumers must map/authorize these before publishing.
     *
     * @return array<string, mixed>
     */
    public function usageHistory(string $start, string $end): array
    {
        if (! self::isCalendarDate($start) || ! self::isCalendarDate($end) || strcmp($start, $end) > 0) {
            throw new InvalidArgumentException('Valid YYYY-MM-DD start/end dates are required (start may equal end).');
        }

        $data = $this->response('NodesUsageHistoryController_getStatsNodesUsage', query: [
            'start' => $start,
            'end' => $end,
        ]);

        foreach (['categories', 'sparklineData', 'topNodes', 'series'] as $field) {
            if (! is_array($data[$field] ?? null) || ! array_is_list($data[$field])) {
                throw new UnexpectedValueException("Invalid node usage field: {$field}");
            }
        }

        return $data;
    }

    /**
     * @param  array<mixed>  $nodeUuids
     * @return array<string, mixed>
     */
    public function usageForNodes(array $nodeUuids, string $start, string $end): array
    {
        if ($nodeUuids === [] || ! array_is_list($nodeUuids)
            || ! self::isCalendarDate($start) || ! self::isCalendarDate($end) || strcmp($start, $end) > 0) {
            throw new InvalidArgumentException('Nodes and valid YYYY-MM-DD date range are required (start may equal end).');
        }

        foreach ($nodeUuids as $uuid) {
            if (! is_string($uuid) || ! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid)) {
                throw new InvalidArgumentException('Invalid node UUID.');
            }
        }

        $data = $this->response('BandwidthStatsNodesController_getNodeUsage', query: [
            'start' => $start,
            'end' => $end,
        ], body: ['nodesUuids' => $nodeUuids]);

        if (! is_array($data['nodes'] ?? null) || ! array_is_list($data['nodes'])) {
            throw new UnexpectedValueException('Invalid node usage response collection.');
        }

        return $data;
    }

    /**
     * Ranked per-node users for an inclusive UTC calendar-date range.
     * Only username and provider-reported byte total leave this adapter.
     *
     * @return list<array{username:string,total:int}>
     */
    public function topUsers(string $nodeUuid, string $start, string $end): array
    {
        if (! preg_match('/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D', $nodeUuid)
            || ! self::isCalendarDate($start) || ! self::isCalendarDate($end) || $start > $end) {
            throw new InvalidArgumentException('Invalid node or reporting interval.');
        }

        $data = $this->response('BandwidthStatsNodesController_getStatsNodeUsersUsage', [
            'start' => $start, 'end' => $end, 'topUsersLimit' => 10,
        ], path: ['uuid' => strtolower($nodeUuid)]);

        if (! is_array($data['topUsers'] ?? null) || ! array_is_list($data['topUsers'])
            || count($data['topUsers']) > 10) {
            throw new UnexpectedValueException('Invalid top users list.');
        }

        $users = [];
        $seen = [];
        foreach ($data['topUsers'] as $row) {
            if (! is_array($row) || ! is_string($row['username'] ?? null)
                || ! self::safeLabel($row['username']) || isset($seen[$row['username']])
                || ($total = self::nonNegativeBytes($row['total'] ?? null)) === null) {
                throw new UnexpectedValueException('Invalid top user row.');
            }
            $seen[$row['username']] = true;
            $users[] = ['username' => $row['username'], 'total' => $total];
        }

        return $users;
    }

    public static function nonNegativeBytes(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value)) {
            return is_finite($value) && $value >= 0 && $value < PHP_INT_MAX && floor($value) === $value
                ? (int) $value : null;
        }

        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            return null;
        }

        return (int) $value;
    }

    private static function safeLabel(string $label): bool
    {
        return trim($label) !== '' && mb_strlen($label) <= 80
            && preg_match('/[\x00-\x1F\x7F]/u', $label) !== 1;
    }

    private static function isCalendarDate(string $value): bool
    {
        // v3.4.4 bandwidth operations accept a calendar date, not a timestamp.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function response(string $operation, array $query = [], ?array $body = null, array $path = []): array
    {
        $result = $this->client->call($operation, pathParameters: $path, query: $query, body: $body);

        if (! is_array($result)
            || ! array_key_exists('response', $result)
            || ! is_array($result['response'])
            || (array_is_list($result['response']) && $result['response'] !== [])) {
            throw new UnexpectedValueException('Invalid Remnawave node telemetry response.');
        }

        return $result['response'];
    }
}
