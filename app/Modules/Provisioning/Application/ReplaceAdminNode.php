<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

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

        $hostInventory = $this->hosts->replacementInventory($actorId);
        /** @var list<array<string,mixed>> $assignedHosts */
        $assignedHosts = [];
        /** @var array<string,array{zoneId:string,zone:string,record:array<string,mixed>}> $inferredDns */
        $inferredDns = [];

        foreach ($hostInventory as $host) {
            if (in_array($current['uuid'], $host['nodes'], true)) {
                $assignedHosts[] = [...$host, 'association' => 'node_membership'];

                continue;
            }

            // Older Remnawave Host responses can legitimately have nodes: [].
            // Infer only when both the active profile/inbound and current public
            // endpoint match. Anything ambiguous stops the replacement.
            if ($host['nodes'] !== []
                || $host['profileUuid'] !== $current['profileUuid']
                || ! in_array($host['inboundUuid'], $current['inboundUuids'], true)) {
                continue;
            }

            if (filter_var($host['address'], FILTER_VALIDATE_IP) !== false) {
                if ($host['address'] === $oldIp) {
                    $assignedHosts[] = [...$host, 'association' => 'inbound_address'];
                }

                continue;
            }

            try {
                $resolved = $host['address'] === $current['address'] && $nodeDns !== null
                    ? $nodeDns
                    : $this->dns->replacementRecord($actorId, $host['address']);
            } catch (\Throwable $exception) {
                throw new RuntimeException(
                    'A Host with empty Node membership has an ambiguous address; manual review required.',
                    0,
                    $exception,
                );
            }

            if ($resolved['record']['content'] === $oldIp) {
                $inferredDns[strtolower($host['address'])] = $resolved;
                $assignedHosts[] = [...$host, 'association' => 'inbound_address'];
            }
        }

        usort($assignedHosts, static fn (array $a, array $b): int => strcmp($a['uuid'], $b['uuid']));

        $hostnames = [];
        $directHosts = [];
        foreach ($assignedHosts as $host) {
            if ($host['nodes'] !== [] && $host['nodes'] !== [$current['uuid']]) {
                throw new RuntimeException('A Host is shared with another Node. Resolve shared Host membership before replacement.');
            }
            if (filter_var($host['address'], FILTER_VALIDATE_IP) !== false) {
                if ($host['address'] !== $oldIp) {
                    throw new RuntimeException('A direct-IP Host does not match the current Node IP; manual review required.');
                }
                $directHosts[] = $host;

                continue;
            }
            $hostnames[strtolower($host['address'])] = true;
        }

        if ($nodeDns !== null) {
            $hostnames[strtolower($current['address'])] = true;
        }

        if (count($hostnames) > 10 || count($directHosts) > 10) {
            throw new RuntimeException('The Node has too many Host addresses for a bounded replacement.');
        }

        // A hostname used by a Host outside this Node is shared even when the
        // provider's nodes[] membership is incomplete. Never redirect it silently.
        $targetHostIds = array_fill_keys(array_column($assignedHosts, 'uuid'), true);
        $targetHostnames = array_fill_keys(array_map('strtolower', array_keys($hostnames)), true);
        foreach ($this->nodes->replacementAddresses($actorId) as $peerNode) {
            if ($peerNode['uuid'] !== $current['uuid'] && isset($targetHostnames[strtolower($peerNode['address'])])) {
                throw new RuntimeException('A hostname being transferred is also used as another Node management address; manual review required.');
            }
        }
        foreach ($hostInventory as $host) {
            if (isset($targetHostnames[strtolower($host['address'])]) && ! isset($targetHostIds[$host['uuid']])) {
                throw new RuntimeException('A Node/Host hostname is also used by another or unassigned Host; manual review required.');
            }
            if ($host['address'] === $oldIp && ! isset($targetHostIds[$host['uuid']])) {
                throw new RuntimeException('Another Host uses the current server IP outside this Node; manual review required.');
            }
        }

        $dnsRecords = [];
        $names = array_keys($hostnames);
        sort($names, SORT_STRING);
        foreach ($names as $hostname) {
            $resolved = $hostname === strtolower($current['address']) && $nodeDns !== null
                ? $nodeDns
                : ($inferredDns[$hostname] ?? $this->dns->replacementRecord($actorId, $hostname));
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

        $hostRows = array_map(static fn (array $host): array => [
            'uuid' => $host['uuid'],
            'remark' => $host['remark'],
            'address' => $host['address'],
            'port' => $host['port'],
            'disabled' => $host['disabled'],
            'profileUuid' => $host['profileUuid'],
            'inboundUuid' => $host['inboundUuid'],
            'nodes' => $host['nodes'],
            'association' => $host['association'],
        ], $assignedHosts);

        $directHostRows = array_map(static fn (array $host): array => [
            'uuid' => $host['uuid'],
            'remark' => $host['remark'],
            'address' => $host['address'],
            'port' => $host['port'],
            'disabled' => $host['disabled'],
            'profileUuid' => $host['profileUuid'],
            'inboundUuid' => $host['inboundUuid'],
            'nodes' => $host['nodes'],
            'association' => $host['association'],
        ], $directHosts);

        return [
            'node' => $current,
            'oldIp' => $oldIp,
            'newIp' => $newIp,
            'nodeAddressMode' => $nodeAddressIsIp ? 'ip' : 'domain',
            'dnsRecords' => $dnsRecords,
            'hosts' => $hostRows,
            'hostInventory' => $hostInventory,
            'directHosts' => $directHostRows,
        ];
    }

    /**
     * One confirmed, non-retryable transfer. Each external write is checkpointed
     * as uncertain before dispatch and verified with a fresh GET afterwards.
     *
     * @param  array<string,mixed>  $expected
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
                'hosts' => $current['hosts'],
                'hostInventory' => $current['hostInventory'],
                'directHosts' => array_map(static fn (array $host): array => [...$host, 'stage' => 'ready'], $current['directHosts']),
                'stage' => 'applying',
            ];
            $this->save($identifier, $state);

            foreach ($state['dnsRecords'] as $index => $entry) {
                $this->assertNodeSnapshot($actorId, $state['node'] ?? null);
                $this->assertHostInventory($actorId, $state['hostInventory'] ?? null);
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
                $this->assertNodeSnapshot($actorId, $state['node'] ?? null);
                $this->assertHostInventory($actorId, $state['hostInventory'] ?? null);
                $state['directHosts'][$index]['stage'] = 'uncertain';
                $this->save($identifier, $state);
                $this->hosts->updateReplacementAddress($actorId, $host, $state['newIp']);
                $this->markHostAddress($state, $host['uuid'], $state['newIp']);
                $state['directHosts'][$index]['stage'] = 'verified';
                $this->save($identifier, $state);
            }

            if ($state['nodeAddressMode'] === 'ip') {
                $this->assertNodeSnapshot($actorId, $state['node'] ?? null);
                $this->assertHostInventory($actorId, $state['hostInventory'] ?? null);
                $this->assertDnsRecordsAtNewIp($actorId, $state['dnsRecords'] ?? null, $state['newIp'] ?? null);
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

            $state['stage'] = 'awaiting_client_test';
            $this->save($identifier, $state);
        });
    }

    /** @return array<string,mixed> */
    public function progress(int $actorId, string $identifier): array
    {
        $stored = Cache::get($this->key($identifier));
        if ($stored === null) {
            return ['stage' => 'ready'];
        }
        $state = $this->state($actorId, $identifier);
        $node = $this->nodes->replacementSnapshot($actorId, $state['node']['uuid']);
        $expectedAddresses = [$state['nodeAddressMode'] === 'ip' ? $state['newIp'] : $state['node']['address']];
        if ($state['stage'] === 'applying' && $state['nodeAddressMode'] === 'ip'
            && $state['nodeAddressStage'] === 'ready') {
            $expectedAddresses = [$state['node']['address']];
        } elseif ($state['stage'] === 'applying' && $state['nodeAddressMode'] === 'ip'
            && $state['nodeAddressStage'] === 'uncertain') {
            $expectedAddresses[] = $state['node']['address'];
        }
        if (! in_array($node['address'], $expectedAddresses, true)
            || $this->nodeIdentity($node, includeAddress: false) !== $this->nodeIdentity($state['node'], includeAddress: false)) {
            throw new RuntimeException('Node identity or address changed during replacement.');
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

    public function acceptClientTest(int $actorId, string $identifier): void
    {
        $this->key($identifier);
        Cache::lock('nw05:replace:'.$identifier, 300)->block(2, function () use ($actorId, $identifier): void {
            $state = $this->state($actorId, $identifier);
            if ($state['stage'] !== 'awaiting_client_test') {
                throw new RuntimeException('The replacement is not awaiting a client test.');
            }
            $node = $this->nodes->replacementSnapshot($actorId, $state['node']['uuid']);
            $expectedAddress = $state['nodeAddressMode'] === 'ip' ? $state['newIp'] : $state['node']['address'];
            if (! $node['connected'] || $node['disabled'] || $node['address'] !== $expectedAddress) {
                throw new RuntimeException('Node connection changed before client-test acceptance.');
            }
            $state['stage'] = 'completed';
            $state['clientTestAcceptedAt'] = now()->toIso8601String();
            $state['clientTestAcceptedBy'] = $actorId;
            $this->save($identifier, $state);
        });
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
                if ($this->nodeIdentity($node, includeAddress: false) !== $this->nodeIdentity($state['node'], includeAddress: false)) {
                    throw new RuntimeException('Node identity changed during uncertain replacement; manual review required.');
                }
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
                $expectedHost = array_intersect_key($host, array_flip(['uuid', 'remark', 'address', 'port', 'disabled', 'profileUuid', 'inboundUuid']));
                $expectedHost['address'] = $state['newIp'];
                if ($current === $expectedHost) {
                    $this->markHostAddress($state, $host['uuid'], $state['newIp']);
                    $state['directHosts'][$index]['stage'] = 'verified';
                    $this->save($identifier, $state);

                    continue;
                }
                throw new RuntimeException('An uncertain Host update is not verified. Inspect Remnawave manually.');
            }

            $this->assertHostInventory($actorId, $state['hostInventory'] ?? null);

            if (($state['nodeAddressMode'] === 'ip' && $state['nodeAddressStage'] !== 'verified')
                || $state['nodeAddressStage'] === 'uncertain'
                || collect($state['dnsRecords'])->contains(static fn (array $item): bool => $item['stage'] !== 'verified')
                || collect($state['directHosts'])->contains(static fn (array $item): bool => $item['stage'] !== 'verified')) {
                throw new RuntimeException('Some replacement operations remain unresolved.');
            }

            $state['stage'] = 'awaiting_client_test';
            $this->save($identifier, $state);
        });
    }

    /**
     * Re-read every transferred DNS record before changing a direct-IP Node address.
     * This keeps a stale/changed DNS target from being followed by a Node PATCH.
     */
    private function assertDnsRecordsAtNewIp(int $actorId, mixed $entries, mixed $newIp): void
    {
        if (! is_array($entries) || ! is_string($newIp)) {
            throw new RuntimeException('Replacement DNS checkpoint is unavailable.');
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)
                || ($entry['stage'] ?? null) !== 'verified'
                || ! is_string($entry['hostname'] ?? null)
                || ! is_string($entry['zoneId'] ?? null)
                || ! is_string($entry['zone'] ?? null)
                || ! is_array($entry['record'] ?? null)
                || ! is_string($entry['record']['id'] ?? null)
                || ! is_bool($entry['record']['proxied'] ?? null)) {
                throw new RuntimeException('A DNS replacement checkpoint is incomplete.');
            }

            $record = $this->dns->oneUniqueA(
                $actorId,
                $entry['zoneId'],
                $entry['zone'],
                $entry['record']['id'],
            );
            if ($record['name'] !== $entry['hostname']
                || $record['content'] !== $newIp
                || $record['proxied'] !== $entry['record']['proxied']) {
                throw new RuntimeException('A transferred DNS record changed before the Node address update.');
            }
        }
    }

    /**
     * Re-read the Node identity immediately before each replacement write.
     */
    private function assertNodeSnapshot(int $actorId, mixed $expected): void
    {
        if (! is_array($expected) || ! is_string($expected['uuid'] ?? null)) {
            throw new RuntimeException('Replacement Node checkpoint is unavailable.');
        }

        $current = $this->nodes->replacementSnapshot($actorId, $expected['uuid']);
        if ($this->nodeIdentity($current) !== $this->nodeIdentity($expected)) {
            throw new RuntimeException('Node identity changed during replacement; stop and review before continuing.');
        }
    }

    /**
     * Re-read the complete Host inventory immediately before each replacement write.
     */
    private function assertHostInventory(int $actorId, mixed $expected): void
    {
        if (! is_array($expected) || $this->hosts->replacementInventory($actorId) !== $expected) {
            throw new RuntimeException('Host inventory changed during replacement; stop and review before continuing.');
        }
    }

    /** @param array<string,mixed> $state */
    private function markHostAddress(array &$state, string $uuid, string $address): void
    {
        foreach (['hostInventory', 'hosts', 'directHosts'] as $collection) {
            $rows = $state[$collection] ?? null;
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $index => $host) {
                if (is_array($host) && ($host['uuid'] ?? null) === $uuid) {
                    $state[$collection][$index]['address'] = $address;
                }
            }
        }
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
