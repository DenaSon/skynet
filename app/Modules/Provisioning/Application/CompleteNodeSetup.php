<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * NW-03: small coordinator over existing NODE-01 and HOST-01 Actions.
 * No new Remnawave client, privileged bypass, automatic retry or host enable.
 */
final readonly class CompleteNodeSetup
{
    public function __construct(
        private ManageNodeInstallation $installations,
        private ManageAdminNodes $nodes,
        private ManageAdminHosts $hosts,
    ) {}

    /** @return array{name:string,address:string,profile:string,inbound:string} */
    public function previewNode(int $actorId, string $id): array
    {
        $this->writes();
        $state = $this->installations->context($actorId, $id);
        if (($state['nodeStage'] ?? null) !== null) {
            throw new RuntimeException('Node registration already attempted.');
        }
        $this->checkSelections($actorId, $state);
        $this->checkNodeUnique($actorId, $state['name']);

        return [
            'name' => $state['name'],
            'address' => $state['ip'],
            'profile' => $state['profileUuid'],
            'inbound' => $state['inboundUuid'],
        ];
    }

    /**
     * One confirmed attempt. Ambiguous provider response is NOT retried:
     * operator must reconcile existing Nodes before any new registration.
     */
    public function registerNode(int $actorId, string $id): string
    {
        $this->writes();

        return Cache::lock('nw03:flow:'.$id, 90)->block(1, function () use ($actorId, $id): string {
            $state = $this->installations->context($actorId, $id);
            if (($state['nodeStage'] ?? null) !== null) {
                throw new RuntimeException('Registration already attempted.');
            }
            $this->checkSelections($actorId, $state);
            $this->checkNodeUnique($actorId, $state['name']);
            // Mark before POST: never repeat a request with uncertain outcome.
            $this->installations->checkpoint($actorId, $id, ['nodeStage' => 'uncertain']);
            $uuid = $this->nodes->create($actorId, $state['name'], $state['ip'],
                $state['profileUuid'], [$state['inboundUuid']]);
            $this->installations->checkpoint($actorId, $id, [
                'nodeUuid' => $uuid,
                'nodeStage' => 'registered',
            ]);

            return $uuid;
        });
    }

    /** @return array{connected:bool,disabled:bool,name:string,uuid:string} */
    public function nodeStatus(int $actorId, string $id): array
    {
        $state = $this->installations->context($actorId, $id);
        if (! is_string($state['nodeUuid'] ?? null)) {
            throw new RuntimeException('Node has not been registered with a confirmed UUID.');
        }
        $node = $this->nodes->one($actorId, $state['nodeUuid']);
        if ($node['name'] !== $state['name']) {
            throw new RuntimeException('Remote Node identity changed.');
        }

        return $node;
    }

    /** @return array{remark:string,address:string,port:int} */
    public function previewHost(int $actorId, string $id, int $port): array
    {
        $this->writes();
        $state = $this->installations->context($actorId, $id);
        if (($state['hostStage'] ?? null) !== null) {
            throw new RuntimeException('Host creation already attempted.');
        }
        $node = $this->nodeStatus($actorId, $id);
        if (! $node['connected'] || $node['disabled']) {
            throw new RuntimeException('Node is not connected and enabled.');
        }
        $this->checkSelections($actorId, $state);
        $remark = $this->remark($state['name']);
        $this->hosts->validateIdentity($actorId, $remark, $state['ip'], $port);
        $this->checkHostUnique($actorId, $state, $remark, $port);

        return ['remark' => $remark, 'address' => $state['ip'], 'port' => $port];
    }

    public function createHost(int $actorId, string $id, int $port): string
    {
        $this->writes();

        return Cache::lock('nw03:flow:'.$id, 90)->block(1, function () use ($actorId, $id, $port): string {
            $state = $this->installations->context($actorId, $id);
            $info = $this->previewHost($actorId, $id, $port);
            // Mark before POST and preserve uncertain outcomes for manual reconciliation.
            $this->installations->checkpoint($actorId, $id, ['hostStage' => 'uncertain']);
            $uuid = $this->hosts->create($actorId, $info['remark'], $info['address'],
                $info['port'], $state['profileUuid'], $state['inboundUuid']);
            $this->installations->checkpoint($actorId, $id, [
                'hostUuid' => $uuid,
                'hostStage' => 'created',
            ]);

            return $uuid;
        });
    }

    /** @return array{uuid:string,remark:string,address:string,port:int,disabled:bool|null,profileUuid:string|null,inboundUuid:string|null,verified:bool} */
    public function hostStatus(int $actorId, string $id): array
    {
        $state = $this->installations->context($actorId, $id);
        if (! is_string($state['hostUuid'] ?? null)) {
            throw new RuntimeException('Host has not been created with a confirmed UUID.');
        }
        $host = $this->hosts->one($actorId, $state['hostUuid']);
        $expected = $this->remark($state['name']);

        return [
            ...$host,
            'verified' => $host['remark'] === $expected
                && $host['address'] === $state['ip']
                && $host['disabled'] === true
                && $host['profileUuid'] === $state['profileUuid']
                && $host['inboundUuid'] === $state['inboundUuid'],
        ];
    }

    /** @return array{nodeStage:string,hostStage:string} */
    public function progress(int $actorId, string $id): array
    {
        $state = $this->installations->context($actorId, $id);

        return [
            'nodeStage' => is_string($state['nodeStage'] ?? null) ? $state['nodeStage'] : 'ready',
            'hostStage' => is_string($state['hostStage'] ?? null) ? $state['hostStage'] : 'ready',
        ];
    }

    private function checkSelections(int $actorId, array $state): void
    {
        $profiles = $this->nodes->profiles($actorId);
        if (! in_array($state['profileUuid'], array_column($profiles, 'uuid'), true)) {
            throw new RuntimeException('Selected profile has changed.');
        }
        $inbounds = $this->nodes->inbounds($actorId, $state['profileUuid']);
        if (! in_array($state['inboundUuid'], array_column($inbounds, 'uuid'), true)) {
            throw new RuntimeException('Selected inbound has changed.');
        }
    }

    private function checkNodeUnique(int $actorId, string $name): void
    {
        foreach ($this->nodes->all($actorId) as $node) {
            if (mb_strtolower($node['name']) === mb_strtolower($name)) {
                throw new RuntimeException('Node already exists: reconcile before proceeding.');
            }
        }
    }

    private function checkHostUnique(int $actorId, array $state, string $remark, int $port): void
    {
        foreach ($this->hosts->all($actorId) as $host) {
            if (mb_strtolower($host['remark']) === mb_strtolower($remark)
                || ($host['address'] === $state['ip'] && $host['port'] === $port
                    && ($host['inboundUuid'] === null || $host['inboundUuid'] === $state['inboundUuid']))) {
                throw new RuntimeException('Host already exists: reconcile before proceeding.');
            }
        }
    }

    private function remark(string $name): string
    {
        return mb_substr($name, 0, 35).'-Host';
    }

    private function writes(): void
    {
        if (config('services.remnawave.node_ssh_setup_enabled') !== true
            || config('services.remnawave.node_ssh_install_enabled') !== true
            || config('services.remnawave.writes_enabled') !== true) {
            throw new RuntimeException('Node completion writes are disabled.');
        }
    }
}
