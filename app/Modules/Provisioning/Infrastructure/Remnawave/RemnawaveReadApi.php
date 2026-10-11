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
