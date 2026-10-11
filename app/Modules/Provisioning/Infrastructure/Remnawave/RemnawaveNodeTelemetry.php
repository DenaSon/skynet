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
        if (! self::isUtcDateTime($start) || ! self::isUtcDateTime($end) || strcmp($start, $end) >= 0) {
            throw new InvalidArgumentException('Valid increasing UTC start/end dates are required.');
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
     * @param  list<string>  $nodeUuids
     * @return array<string, mixed>
     */
    public function usageForNodes(array $nodeUuids, string $start, string $end): array
    {
        if ($nodeUuids === [] || ! array_is_list($nodeUuids)
            || ! self::isUtcDateTime($start) || ! self::isUtcDateTime($end) || strcmp($start, $end) >= 0) {
            throw new InvalidArgumentException('Nodes and increasing UTC date range are required.');
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

    private static function isUtcDateTime(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function response(string $operation, array $query = [], ?array $body = null): array
    {
        $result = $this->client->call($operation, query: $query, body: $body);

        if (! is_array($result)
            || ! array_key_exists('response', $result)
            || ! is_array($result['response'])
            || (array_is_list($result['response']) && $result['response'] !== [])) {
            throw new UnexpectedValueException('Invalid Remnawave node telemetry response.');
        }

        return $result['response'];
    }
}
