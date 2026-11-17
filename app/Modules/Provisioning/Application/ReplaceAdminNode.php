<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * NW-05: replace the VPS behind an existing Remnawave Node.
 *
 * The Node UUID, profile, inbounds and Host objects are preserved. Domain
 * addresses move only through their existing Cloudflare A records.
 */
final readonly class ReplaceAdminNode
{
    public function __construct(
        private ManageNodeInstallation $installations,
        private ManageAdminNodes $nodes,
        private ManageAdminHosts $hosts,
        private ManageCloudflareDns $dns,
    ) {}

    /** @return array<string,mixed> */
    public function preview(int $actorId, string $identifier): array
    {
        $setup = $this->installations->context($actorId, $identifier);
        $target = $setup['replacement'] ?? null;
        if (! is_array($target) || ! is_string($target['uuid'] ?? null)) {
            throw new RuntimeException('This installation is not a Node replacement.');
        }

        $current = $this->nodes->replacementSnapshot($actorId, $target['uuid']);
        if ($this->nodeIdentity($current) !== $this->nodeIdentity($target)) {
            throw new RuntimeException('The existing Node changed during installation; replacement stopped.');
        }
        if ($current['port'] !== 2222) {
            throw new RuntimeException('This replacement flow supports Nodes using the current fixed management port 2222 only.');
        }
        if ($current['disabled']) {
            throw new RuntimeException('Enable the existing Node before replacing its server.');
        }

        $newIp = $setup['ip'] ?? null;
        if (! is_string($newIp)
            || filter_var($newIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('The new server IP is invalid.');
        }

        $nodeAddressIsIp = filter_var($current['address'], FILTER_VALIDATE_IP) !== false;
        $nodeDns = null;
        if ($nodeAddressIsIp) {
            $oldIp = $current['address'];
        } else {
            $nodeDns = $this->dns->replacementRecord($actorId, $current['address']);
            $oldIp = $nodeDns['record']['content'];
            if ($nodeDns['record']['proxied'] !== false) {
                throw new RuntimeException('The Node management hostname must remain DNS Only on port 2222.');
            }
        }
        if ($newIp === $oldIp) {
            throw new RuntimeException('The new server IP is the same as the current server IP.');
        }

        $assignedHosts = $this->hosts->assignedToNode($actorId, $current['uuid']);
        $hostnames = [];
        $directHosts = [];
        foreach ($assignedHosts as $host) {
            if ($host['nodes'] !== [$current['uuid']]) {
                throw new RuntimeException('A Host is shared with another Node. Resolve shared Host membership before replacement.');
            }
            if (filter_var($host['address'], FILTER_VALIDATE_IP) !== false) {
                if ($host['address'] !== $oldIp) {
                    throw new RuntimeException('A direct-IP Host does not match the current Node IP; manual review required.');
                }
                $directHosts[] = $host;
                continue;
            }
            $hostnames[$host['address']] = true;
        }

        if ($nodeDns !== null) {
            $hostnames[$current['address']] = true;
        }

        if (count($hostnames) > 10 || count($directHosts) > 10) {
            throw new RuntimeException('The Node has too many Host addresses for a bounded replacement.');
        }

        $dnsRecords = [];
        $names = array_keys($hostnames);
        sort($names, SORT_STRING);
        foreach ($names as $hostname) {
            $resolved = $hostname === $current['address'] && $nodeDns !== null
                ? $nodeDns
                : $this->dns->replacementRecord($actorId, $hostname);
            $record = $resolved['record'];
            if ($record['content'] !== $oldIp) {
                throw new RuntimeException('A Node/Host DNS record does not point to the current server IP.');
            }
            $dnsRecords[] = [
                'hostname' => $hostname,
                'zoneId' => $resolved['zoneId'],
                'zone' => $resolved['zone'],
                'record' => $record,
            ];
        }

        $directHostRows = array_map(static fn (array $host): array => [
            'uuid' => $host['uuid'],
            'remark' => $host['remark'],
            'address' => $host['address'],
            'port' => $host['port'],
            'disabled' => $host['disabled'],
            'profileUuid' => $host['profileUuid'],
            'inboundUuid' => $host['inboundUuid'],
            'nodes' => $host['nodes'],
        ], $directHosts);

        return [
            'node' => $current,
            'oldIp' => $oldIp,
            'newIp' => $newIp,
            'nodeAddressMode' => $nodeAddressIsIp ? 'ip' : 'domain',
            'dnsRecords' => $dnsRecords,
            'directHosts' => $directHostRows,
        ];
    }

    /**
     * One confirmed, non-retryable transfer. Each external write is checkpointed
     * as uncertain before dispatch and verified with a fresh GET afterwards.
     *
     * @param array<string,mixed> $expected
     */
    public function apply(int $actorId, string $identifier, array $expected): void
    {
        $this->key($identifier);
        if (config('services.remnawave.writes_enabled') !== true
            || ($expected['dnsRecords'] !== [] && config('services.cloudflare.dns_writes_enabled') !== true)) {
            throw new RuntimeException('Required provider write gates are disabled.');
        }

        Cache::lock('nw05:replace:'.$identifier, 300)->block(2, function () use ($actorId, $identifier, $expected): void {
            if (Cache::has($this->key($identifier))) {
                throw new RuntimeException('Replacement already started; reconcile its status instead of applying again.');
            }
            $current = $this->preview($actorId, $identifier);
            if ($current !== $expected) {
                throw new RuntimeException('Replacement inventory changed after confirmation; review it again.');
            }

            $state = [
                'owner' => $actorId,
                'node' => $current['node'],
                'oldIp' => $current['oldIp'],
                'newIp' => $current['newIp'],
                'nodeAddressMode' => $current['nodeAddressMode'],
                'nodeAddressStage' => $current['nodeAddressMode'] === 'ip' ? 'ready' : 'skipped',
                'dnsRecords' => array_map(static fn (array $record): array => [...$record, 'stage' => 'ready'], $current['dnsRecords']),
                'directHosts' => array_map(static fn (array $host): array => [...$host, 'stage' => 'ready'], $current['directHosts']),
                'stage' => 'applying',
            ];
            $this->save($identifier, $state);

            if ($state['nodeAddressMode'] === 'ip') {
                $state['nodeAddressStage'] = 'uncertain';
                $this->save($identifier, $state);
                $updated = $this->nodes->updateReplacementAddress($actorId, $state['node'], $state['newIp']);
                if ($updated['address'] !== $state['newIp']
                    || $this->nodeIdentity($updated, includeAddress: false) !== $this->nodeIdentity($state['node'], includeAddress: false)) {
                    throw new RuntimeException('Node address update could not be verified.');
                }
                $state['nodeAddressStage'] = 'verified';
                $this->save($identifier, $state);
            }

            foreach ($state['dnsRecords'] as $index => $entry) {
                $state['dnsRecords'][$index]['stage'] = 'uncertain';
                $this->save($identifier, $state);
                $this->dns->changeA(
                    $actorId,
                    $entry['zoneId'],
                    $entry['zone'],
                    $entry['record'],
                    $state['newIp'],
                    $entry['record']['proxied'],
                    requireUniqueHostname: true,
                );
                $verified = $this->dns->oneUniqueA($actorId, $entry['zoneId'], $entry['zone'], $entry['record']['id']);
                if ($verified['name'] !== $entry['hostname']
                    || $verified['content'] !== $state['newIp']
                    || $verified['proxied'] !== $entry['record']['proxied']) {
                    throw new RuntimeException('Cloudflare DNS replacement could not be verified.');
                }
                $state['dnsRecords'][$index]['stage'] = 'verified';
                $this->save($identifier, $state);
            }

            foreach ($state['directHosts'] as $index => $host) {
                $state['directHosts'][$index]['stage'] = 'uncertain';
                $this->save($identifier, $state);
                $this->hosts->updateReplacementAddress($actorId, $host, $state['newIp']);
                $state['directHosts'][$index]['stage'] = 'verified';
                $this->save($identifier, $state);
            }

            $state['stage'] = 'awaiting_connection';
            $this->save($identifier, $state);
        });
    }

    /** @return array<string,mixed> */
    public function progress(int $actorId, string $identifier): array
    {
        $stored = Cache::get($this->key($identifier));
        if ($stored === null) return ['stage' => 'ready'];
        $state = $this->state($actorId, $identifier);
        $node = $this->nodes->replacementSnapshot($actorId, $state['node']['uuid']);
        $expectedAddress = $state['nodeAddressMode'] === 'ip' ? $state['newIp'] : $state['node']['address'];
        if ($node['address'] !== $expectedAddress
            || $this->nodeIdentity($node, includeAddress: false) !== $this->nodeIdentity($state['node'], includeAddress: false)) {
            throw new RuntimeException('Node identity or address changed during replacement.');
        }

        if ($state['stage'] === 'awaiting_connection' && $node['connected'] && ! $node['disabled']) {
            $state['stage'] = 'completed';
            $this->save($identifier, $state);
        }

        return [
            'stage' => $state['stage'],
            'connected' => $node['connected'],
            'disabled' => $node['disabled'],
            'oldIp' => $state['oldIp'],
            'newIp' => $state['newIp'],
            'nodeName' => $node['name'],
            'dnsRecords' => array_map(static fn (array $item): array => [
                'hostname' => $item['hostname'],
                'stage' => $item['stage'],
            ], $state['dnsRecords']),
            'directHosts' => array_map(static fn (array $item): array => [
                'remark' => $item['remark'],
                'stage' => $item['stage'],
            ], $state['directHosts']),
        ];
    }

    /** GET-only recovery; uncertain writes are never repeated automatically. */
    public function reconcile(int $actorId, string $identifier): void
    {
        $this->key($identifier);
        Cache::lock('nw05:replace:'.$identifier, 300)->block(2, function () use ($actorId, $identifier): void {
            $state = $this->state($actorId, $identifier);
            if ($state['stage'] !== 'applying') {
                throw new RuntimeException('There is no uncertain replacement write to reconcile.');
            }

            if ($state['nodeAddressStage'] === 'uncertain') {
                $node = $this->nodes->replacementSnapshot($actorId, $state['node']['uuid']);
                if ($node['address'] === $state['newIp']) {
                    $state['nodeAddressStage'] = 'verified';
                } elseif ($node['address'] !== $state['node']['address']) {
                    throw new RuntimeException('Node address has an unexpected value; manual review required.');
                }
                $this->save($identifier, $state);
            }

            foreach ($state['dnsRecords'] as $index => $entry) {
                if ($entry['stage'] !== 'uncertain') {
                    continue;
                }
                $record = $this->dns->oneUniqueA($actorId, $entry['zoneId'], $entry['zone'], $entry['record']['id']);
                if ($record['name'] === $entry['hostname']
                    && $record['content'] === $state['newIp']
                    && $record['proxied'] === $entry['record']['proxied']) {
                    $state['dnsRecords'][$index]['stage'] = 'verified';
                    $this->save($identifier, $state);
                    continue;
                }
                throw new RuntimeException('An uncertain DNS write is not verified. Do not retry it; inspect Cloudflare manually.');
            }

            foreach ($state['directHosts'] as $index => $host) {
                if ($host['stage'] !== 'uncertain') {
                    continue;
                }
                $current = $this->hosts->one($actorId, $host['uuid']);
                if ($current['address'] === $state['newIp']) {
                    $state['directHosts'][$index]['stage'] = 'verified';
                    $this->save($identifier, $state);
                    continue;
                }
                throw new RuntimeException('An uncertain Host update is not verified. Inspect Remnawave manually.');
            }

            if ($state['nodeAddressStage'] === 'uncertain'
                || collect($state['dnsRecords'])->contains(static fn (array $item): bool => $item['stage'] !== 'verified')
                || collect($state['directHosts'])->contains(static fn (array $item): bool => $item['stage'] !== 'verified')) {
                throw new RuntimeException('Some replacement operations remain unresolved.');
            }

            $state['stage'] = 'awaiting_connection';
            $this->save($identifier, $state);
        });
    }

    /** @return array<string,mixed> */
    private function state(int $actorId, string $identifier): array
    {
        $state = Cache::get($this->key($identifier));
        if (! is_array($state) || ($state['owner'] ?? null) !== $actorId) {
            throw new RuntimeException('Replacement state expired or belongs to another operator.');
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function save(string $identifier, array $state): void
    {
        Cache::put($this->key($identifier), $state, now()->addHours(3));
    }

    private function key(string $identifier): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $identifier) !== 1) {
            throw new RuntimeException('Invalid replacement identifier.');
        }

        return 'nw05:state:'.$identifier;
    }

    /** @param array<string,mixed> $node */
    private function nodeIdentity(array $node, bool $includeAddress = true): array
    {
        $identity = [
            'uuid' => $node['uuid'] ?? null,
            'name' => $node['name'] ?? null,
            'port' => $node['port'] ?? null,
            'disabled' => $node['disabled'] ?? null,
            'profileUuid' => $node['profileUuid'] ?? null,
            'inboundUuids' => $node['inboundUuids'] ?? null,
        ];
        if ($includeAddress) {
            $identity['address'] = $node['address'] ?? null;
        }

        return $identity;
    }
}
