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
        private ManageCloudflareDns $dns,
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
                $state['profileUuid'], [$state['inboundUuid']], port: 2222);
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

    /** @return array{name:string,ip:string,state:string} */
    public function previewDns(int $actorId, string $id): array
    {
        $this->writes();
        $state = $this->installations->context($actorId, $id);
        if (! is_string($state['dnsName'] ?? null) || ($state['dnsStage'] ?? null) !== null) {
            throw new RuntimeException('DNS setup is not pending.');
        }
        $this->connectedNode($actorId, $id);

        return [
            'name' => $state['dnsName'],
            'ip' => $state['ip'],
            'state' => $this->dns->inspectA(
                $actorId, $state['dnsZoneId'], $state['dnsZone'], $state['dnsName'], $state['ip'],
            ),
        ];
    }

    public function ensureDns(int $actorId, string $id): void
    {
        $this->writes();

        Cache::lock('nw03:flow:'.$id, 90)->block(1, function () use ($actorId, $id): void {
            $state = $this->installations->context($actorId, $id);
            if (! is_string($state['dnsName'] ?? null) || ($state['dnsStage'] ?? null) !== null) {
                throw new RuntimeException('DNS setup already attempted or not selected.');
            }
            $this->connectedNode($actorId, $id);
            $this->dns->ensureA(
                $actorId, $state['dnsZoneId'], $state['dnsZone'], $state['dnsName'], $state['ip'],
                fn () => $this->installations->checkpoint($actorId, $id, ['dnsStage' => 'uncertain']),
            );
            $this->installations->checkpoint($actorId, $id, ['dnsStage' => 'verified']);
        });
    }

    /** GET-only recovery for an uncertain Cloudflare write; never repeats POST. */
    public function reconcileDns(int $actorId, string $id): void
    {
        $this->writes();

        Cache::lock('nw03:flow:'.$id, 90)->block(1, function () use ($actorId, $id): void {
            $state = $this->installations->context($actorId, $id);
            if (($state['dnsStage'] ?? null) !== 'uncertain') {
                throw new RuntimeException('No uncertain DNS action to reconcile.');
            }
            if ($this->dns->inspectA(
                $actorId, $state['dnsZoneId'], $state['dnsZone'], $state['dnsName'], $state['ip'],
            ) !== 'existing') {
                throw new RuntimeException('DNS record not yet present; manual review required.');
            }
            $this->installations->checkpoint($actorId, $id, ['dnsStage' => 'verified']);
        });
    }

    private function connectedNode(int $actorId, string $id): void
    {
        $node = $this->nodeStatus($actorId, $id);
        if (! $node['connected'] || $node['disabled']) {
            throw new RuntimeException('Node is not connected and enabled.');
        }
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
        if (isset($state['dnsName']) && ($state['dnsStage'] ?? null) !== 'verified') {
            throw new RuntimeException('DNS record has not been verified.');
        }
        if (isset($state['dnsName']) && $this->dns->inspectA(
            $actorId, $state['dnsZoneId'], $state['dnsZone'], $state['dnsName'], $state['ip'],
        ) !== 'existing') {
            throw new RuntimeException('DNS record changed since confirmation.');
        }
        $address = $state['dnsName'] ?? $state['ip'];
        $remark = $this->remark($state['name']);
        $this->hosts->validateIdentity($actorId, $remark, $address, $port);
        $this->checkHostUnique($actorId, $state, $remark, $address, $port);

        return ['remark' => $remark, 'address' => $address, 'port' => $port];
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
                && $host['address'] === ($state['dnsName'] ?? $state['ip'])
                && $host['disabled'] === true
                && $host['profileUuid'] === $state['profileUuid']
                && $host['inboundUuid'] === $state['inboundUuid'],
        ];
    }

    /** @return array{nodeStage:string,hostStage:string,dnsStage:string,dnsName:string|null} */
    public function progress(int $actorId, string $id): array
    {
        $state = $this->installations->context($actorId, $id);

        return [
            'nodeStage' => is_string($state['nodeStage'] ?? null) ? $state['nodeStage'] : 'ready',
            'hostStage' => is_string($state['hostStage'] ?? null) ? $state['hostStage'] : 'ready',
            'dnsStage' => ! isset($state['dnsName']) ? 'skipped'
                : (is_string($state['dnsStage'] ?? null) ? $state['dnsStage'] : 'ready'),
            'dnsName' => is_string($state['dnsName'] ?? null) ? $state['dnsName'] : null,
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

    private function checkHostUnique(int $actorId, array $state, string $remark, string $address, int $port): void
    {
        foreach ($this->hosts->all($actorId) as $host) {
            if (mb_strtolower($host['remark']) === mb_strtolower($remark)
                || ($host['address'] === $address && $host['port'] === $port
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
