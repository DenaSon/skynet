<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Remnawave;

use UnexpectedValueException;

/**
 * Minimal read-only integration for validating a real panel before provisioning.
 * Does not export private host/config data or mutate external resources.
 */
final readonly class RemnawaveReadApi
{
    public function __construct(private RemnawaveApiClient $client) {}

    public function healthInstanceCount(): int
    {
        $health = $this->objectResponse('SystemController_getRemnawaveHealth');

        return count($this->list($health['runtimeMetrics'] ?? null, 'runtimeMetrics'));
    }

    /**
     * @return array{cpuCores:int, memoryTotal:int, memoryUsed:int, memoryFree:int, uptimeSeconds:float, totalUsers:int, onlineUsers:int}
     */
    public function systemStats(): array
    {
        $stats = $this->objectResponse('SystemController_getStats');
        $cpu = $stats['cpu'] ?? null;
        $memory = $stats['memory'] ?? null;
        $users = $stats['users'] ?? null;
        $online = $stats['onlineStats'] ?? null;

        if (! is_array($cpu) || ! is_array($memory)
            || ! is_array($users) || ! is_array($online)) {
            throw new UnexpectedValueException('Malformed Remnawave system stats.');
        }

        $cores = $this->nonNegativeInteger($cpu['cores'] ?? null);
        $total = $this->nonNegativeInteger($memory['total'] ?? null);
        $used = $this->nonNegativeInteger($memory['used'] ?? null);
        $free = $this->nonNegativeInteger($memory['free'] ?? null);
        $totalUsers = $this->nonNegativeInteger($users['totalUsers'] ?? null);
        $onlineUsers = $this->nonNegativeInteger($online['onlineNow'] ?? null);
        $uptime = $stats['uptime'] ?? null;

        if ($cores === 0 || $total === 0 || $used > $total || $free > $total
            || (! is_int($uptime) && ! is_float($uptime))
            || ! is_finite((float) $uptime) || $uptime < 0) {
            throw new UnexpectedValueException('Invalid Remnawave system stats.');
        }

        return [
            'cpuCores' => $cores,
            'memoryTotal' => $total,
            'memoryUsed' => $used,
            'memoryFree' => $free,
            'uptimeSeconds' => (float) $uptime,
            'totalUsers' => $totalUsers,
            'onlineUsers' => $onlineUsers,
        ];
    }

    private function nonNegativeInteger(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_float($value) && is_finite($value)
            && $value >= 0 && $value < PHP_INT_MAX && floor($value) === $value) {
            return (int) $value;
        }

        throw new UnexpectedValueException('Invalid Remnawave numeric stat.');
    }

    public function inventory(): RemnawaveDiscoverySnapshot
    {
        $remoteNodes = $this->list(
            $this->response('NodesController_getNodes'),
            'nodes',
        );

        $nodes = [];

        foreach ($remoteNodes as $remoteNode) {
            if (! is_array($remoteNode)
                || ! is_string($remoteNode['uuid'] ?? null)
                || ! is_string($remoteNode['name'] ?? null)
                || ! is_bool($remoteNode['isConnected'] ?? null)
                || ! is_bool($remoteNode['isDisabled'] ?? null)) {
                throw new UnexpectedValueException('Malformed Remnawave nodes response.');
            }

            $nodes[] = new RemnawaveNodeSummary(
                uuid: $remoteNode['uuid'],
                name: $remoteNode['name'],
                connected: $remoteNode['isConnected'],
                disabled: $remoteNode['isDisabled'],
            );
        }

        $hosts = $this->list($this->response('HostsController_getHosts'), 'hosts');
        $squads = $this->objectResponse('InternalSquadController_getInternalSquads');
        $profiles = $this->objectResponse('ConfigProfileController_getConfigProfiles');

        // Validate the collection shapes without retaining raw secrets/configs.
        $this->list($squads['internalSquads'] ?? null, 'internalSquads');
        $this->list($profiles['configProfiles'] ?? null, 'configProfiles');

        return new RemnawaveDiscoverySnapshot(
            nodes: $nodes,
            hosts: count($hosts),
            internalSquads: $this->total($squads, 'internalSquads'),
            configProfiles: $this->total($profiles, 'configProfiles'),
        );
    }

    /**
     * @return array<mixed>
     */
    private function response(string $operation): array
    {
        $data = $this->client->call($operation);

        if (! is_array($data) || ! isset($data['response']) || ! is_array($data['response'])) {
            throw new UnexpectedValueException('Malformed Remnawave API response envelope.');
        }

        return $data['response'];
    }

    /**
     * @return array<string, mixed>
     */
    private function objectResponse(string $operation): array
    {
        $data = $this->response($operation);

        if (array_is_list($data) && $data !== []) {
            throw new UnexpectedValueException('Expected Remnawave response object.');
        }

        return $data;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException("Invalid Remnawave {$label} collection.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $collection
     */
    private function total(array $collection, string $label): int
    {
        $total = $collection['total'] ?? null;

        if (! is_int($total) || $total < 0) {
            throw new UnexpectedValueException("Invalid Remnawave {$label} total.");
        }

        return $total;
    }
}
