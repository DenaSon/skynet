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
        private ManageAdminNodeOperations $nodeOperations,
    ) {}

    /** @return array{name:string,address:string,port:int,profile:string,inbound:string} */
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
            'port' => 2222,
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

    /** @return array{name:string,ip:string,state:string,mode:string,previousIp:string|null,proxied:bool,record:array<string,mixed>|null} */
    public function previewDns(int $actorId, string $id): array
    {
        $this->writes();
        $state = $this->installations->context($actorId, $id);
        if (! is_string($state['dnsName'] ?? null) || ($state['dnsStage'] ?? null) !== null) {
            throw new RuntimeException('DNS setup is not pending.');
        }
        $this->connectedNode($actorId, $id);

        $selected = $state['dnsRecordExpected'] ?? null;
        if (is_array($selected)) {
            $recordId = $selected['id'] ?? null;
            if (! is_string($recordId)) {
                throw new RuntimeException('Selected DNS record is invalid.');
            }
            $record = $this->dns->oneUniqueA(
                $actorId, $state['dnsZoneId'], $state['dnsZone'], $recordId,
            );
            if ($record['id'] !== $recordId || $record['name'] !== $state['dnsName']) {
                throw new RuntimeException('Selected DNS record identity changed.');
            }

            return [
                'name' => $state['dnsName'],
                'ip' => $state['ip'],
                'state' => 'existing',
                'mode' => 'update',
                'previousIp' => $record['content'],
                'proxied' => $record['proxied'],
                'record' => $record,
            ];
        }

        return [
            'name' => $state['dnsName'],
            'ip' => $state['ip'],
            'state' => $this->dns->inspectA(
                $actorId, $state['dnsZoneId'], $state['dnsZone'], $state['dnsName'], $state['ip'],
            ),
            'mode' => 'create',
            'previousIp' => null,
            'proxied' => false,
            'record' => null,
        ];
    }

    public function ensureDns(int $actorId, string $id, ?array $expectedRecord = null): void
    {
        $this->writes();

        Cache::lock('nw03:flow:'.$id, 90)->block(1, function () use ($actorId, $id, $expectedRecord): void {
            $state = $this->installations->context($actorId, $id);
            if (! is_string($state['dnsName'] ?? null) || ($state['dnsStage'] ?? null) !== null) {
                throw new RuntimeException('DNS setup already attempted or not selected.');
            }
            $this->connectedNode($actorId, $id);

            $selected = $state['dnsRecordExpected'] ?? null;
            if (is_array($selected)) {
                $recordId = $selected['id'] ?? null;
                $proxied = $expectedRecord['proxied'] ?? null;
                if (! is_string($recordId) || ! is_array($expectedRecord)
                    || ($expectedRecord['id'] ?? null) !== $recordId
                    || ($expectedRecord['name'] ?? null) !== $state['dnsName']
                    || ! is_bool($proxied)) {
                    throw new RuntimeException('Selected DNS confirmation is invalid.');
                }
                $current = $this->dns->oneUniqueA(
                    $actorId, $state['dnsZoneId'], $state['dnsZone'], $recordId,
                );
                if ($current !== $expectedRecord) {
                    throw new RuntimeException('DNS record changed after confirmation; review it again.');
                }
                $this->installations->checkpoint($actorId, $id, [
                    'dnsRecordExpected' => $expectedRecord,
                ]);
                $this->dns->changeA(
                    $actorId,
                    $state['dnsZoneId'],
                    $state['dnsZone'],
                    $expectedRecord,
                    $state['ip'],
                    $proxied,
                    fn () => $this->installations->checkpoint($actorId, $id, ['dnsStage' => 'uncertain']),
                    requireUniqueHostname: true,
                );
            } else {
                if ($expectedRecord !== null) {
                    throw new RuntimeException('Unexpected DNS record confirmation.');
                }
                $this->dns->ensureA(
                    $actorId, $state['dnsZoneId'], $state['dnsZone'], $state['dnsName'], $state['ip'],
                    fn () => $this->installations->checkpoint($actorId, $id, ['dnsStage' => 'uncertain']),
                );
            }

            $this->installations->checkpoint($actorId, $id, ['dnsStage' => 'verified']);
        });
    }

    /** GET-only recovery for an uncertain Cloudflare write; never repeats POST/PATCH. */
    public function reconcileDns(int $actorId, string $id): void
    {
        $this->writes();

        Cache::lock('nw03:flow:'.$id, 90)->block(1, function () use ($actorId, $id): void {
            $state = $this->installations->context($actorId, $id);
            if (($state['dnsStage'] ?? null) !== 'uncertain') {
                throw new RuntimeException('No uncertain DNS action to reconcile.');
            }

            $expected = $state['dnsRecordExpected'] ?? null;
            if (is_array($expected)) {
                $recordId = $expected['id'] ?? null;
                $proxied = $expected['proxied'] ?? null;
                if (! is_string($recordId) || ! is_bool($proxied)) {
                    throw new RuntimeException('Selected DNS record is invalid.');
                }
                $record = $this->dns->oneUniqueA(
                    $actorId, $state['dnsZoneId'], $state['dnsZone'], $recordId,
                );
                if ($record['name'] !== $state['dnsName'] || $record['content'] !== $state['ip']
                    || $record['proxied'] !== $proxied) {
                    throw new RuntimeException('Updated DNS record not verified; manual review required.');
                }
            } elseif ($this->dns->inspectA(
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
        if (isset($state['dnsName'])) {
            $expected = $state['dnsRecordExpected'] ?? null;
            if (is_array($expected)) {
                $recordId = $expected['id'] ?? null;
                $proxied = $expected['proxied'] ?? null;
                if (! is_string($recordId) || ! is_bool($proxied)) {
                    throw new RuntimeException('Selected DNS record is invalid.');
                }
                $record = $this->dns->oneUniqueA(
                    $actorId, $state['dnsZoneId'], $state['dnsZone'], $recordId,
                );
                if ($record['name'] !== $state['dnsName'] || $record['content'] !== $state['ip']
                    || $record['proxied'] !== $proxied) {
                    throw new RuntimeException('DNS record changed since confirmation.');
                }
            } elseif ($this->dns->inspectA(
                $actorId, $state['dnsZoneId'], $state['dnsZone'], $state['dnsName'], $state['ip'],
            ) !== 'existing') {
                throw new RuntimeException('DNS record changed since confirmation.');
            }
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

    /** @return array<string,mixed> */
    public function previewReplacementNode(int $actorId, string $id): array
    {
        $this->writes();
        $state = $this->installations->context($actorId, $id);
        $expected = $state['replacementNode'] ?? null;
        if (! is_array($expected) || ($state['state'] ?? null) !== 'running'
            || ($state['replacementNodeStage'] ?? 'ready') !== 'ready') {
            throw new RuntimeException('Node replacement is not ready for confirmation.');
        }
        $current = $this->nodes->replacementSnapshot($actorId, $expected['uuid']);
        if ($current !== $expected || ! $current['connected'] || $current['disabled']
            || $state['ip'] === $current['address']) {
            throw new RuntimeException('The existing Node changed or the new server is not distinct.');
        }
        $this->nodes->validateReplacementAddress($actorId, $current['uuid'], $state['ip'], $current['port']);
        // Resolve every linked Host and DNS record BEFORE changing the live Node address.
        $hostPlan = $this->buildReplacementHostPlan($actorId, $state, $current, false);
        $this->installations->checkpoint($actorId, $id, ['replacementHosts' => $hostPlan]);

        return [
            'name' => $current['name'],
            'uuid' => $current['uuid'],
            'oldIp' => $current['address'],
            'newIp' => $state['ip'],
            'port' => $current['port'],
            'profileUuid' => $current['profileUuid'],
            'inboundCount' => count($current['inboundUuids']),
            'hostCount' => count($hostPlan['hosts']),
            'dnsCount' => count($hostPlan['dnsUpdates']),
            'expected' => $current,
        ];
    }

    public function replaceNodeAddress(int $actorId, string $id, array $expected): void
    {
        $this->writes();
        Cache::lock('nw03:replacement-flow:'.$id, 600)->block(2, function () use ($actorId, $id, $expected): void {
            $state = $this->installations->context($actorId, $id);
            $original = $state['replacementNode'] ?? null;
            if (! is_array($original) || $expected !== $original
                || ($state['replacementNodeStage'] ?? 'ready') !== 'ready') {
                throw new RuntimeException('Replacement confirmation is stale or already used.');
            }
            $current = $this->nodes->replacementSnapshot($actorId, $original['uuid']);
            if ($current !== $original || ! $current['connected'] || $current['disabled']) {
                throw new RuntimeException('Existing Node changed before replacement.');
            }
            $this->nodes->validateReplacementAddress($actorId, $original['uuid'], $state['ip'], 2222);
            $this->installations->checkpoint($actorId, $id, ['replacementNodeStage' => 'uncertain']);
            $this->nodeOperations->replaceAddress($actorId, $original, $state['ip']);
            $updated = $this->nodes->replacementSnapshot($actorId, $original['uuid']);
            if ($updated['address'] !== $state['ip']
                || $updated['uuid'] !== $original['uuid']
                || $updated['name'] !== $original['name']
                || $updated['port'] !== $original['port']
                || $updated['profileUuid'] !== $original['profileUuid']
                || $updated['inboundUuids'] !== $original['inboundUuids']) {
                throw new RuntimeException('Updated Node identity or bindings could not be verified.');
            }
            $this->installations->checkpoint($actorId, $id, ['replacementNodeStage' => 'updated']);
        });
    }

    /** GET-only recovery; an old address permits a fresh explicit confirmation, never an automatic retry. */
    public function reconcileReplacementNode(int $actorId, string $id): void
    {
        $this->writes();
        Cache::lock('nw03:replacement-flow:'.$id, 600)->block(2, function () use ($actorId, $id): void {
            $state = $this->installations->context($actorId, $id);
            $original = $state['replacementNode'] ?? null;
            if (! is_array($original) || ($state['replacementNodeStage'] ?? null) !== 'uncertain') {
                throw new RuntimeException('No uncertain Node replacement to reconcile.');
            }
            $current = $this->nodes->replacementSnapshot($actorId, $original['uuid']);
            if ($current['uuid'] !== $original['uuid']
                || $current['name'] !== $original['name']
                || $current['port'] !== $original['port']
                || $current['profileUuid'] !== $original['profileUuid']
                || $current['inboundUuids'] !== $original['inboundUuids']) {
                throw new RuntimeException('Node identity or bindings changed; manual review required.');
            }
            if ($current['address'] === $state['ip']) {
                $this->installations->checkpoint($actorId, $id, ['replacementNodeStage' => 'updated']);

                return;
            }
            if ($current['address'] === $original['address']) {
                $this->installations->checkpoint($actorId, $id, ['replacementNodeStage' => 'ready']);

                return;
            }
            throw new RuntimeException('Node address is neither the old nor the requested IP; manual review required.');
        });
    }

    /** @return array<string,mixed> */
    public function replacementProgress(int $actorId, string $id): array
    {
        $state = $this->installations->context($actorId, $id);
        $original = $state['replacementNode'] ?? null;
        if (! is_array($original)) {
            throw new RuntimeException('This installation is not a Node replacement.');
        }
        $current = $this->nodes->replacementSnapshot($actorId, $original['uuid']);
        $nodeAtTarget = $current['address'] === $state['ip']
            && $current['uuid'] === $original['uuid']
            && $current['name'] === $original['name']
            && $current['port'] === $original['port']
            && $current['profileUuid'] === $original['profileUuid']
            && $current['inboundUuids'] === $original['inboundUuids'];
        $plan = is_array($state['replacementHosts'] ?? null) ? $state['replacementHosts'] : null;
        $operations = [
            ...(is_array($plan['hostUpdates'] ?? null) ? $plan['hostUpdates'] : []),
            ...(is_array($plan['dnsUpdates'] ?? null) ? $plan['dnsUpdates'] : []),
        ];
        $uncertain = count(array_filter($operations, static fn (mixed $item): bool => is_array($item) && ($item['stage'] ?? null) === 'uncertain'));
        $pending = count(array_filter($operations, static fn (mixed $item): bool => is_array($item) && ($item['stage'] ?? null) === 'pending'));
        $verified = count(array_filter($operations, static fn (mixed $item): bool => is_array($item) && ($item['stage'] ?? null) === 'verified'));

        return [
            'nodeStage' => $state['replacementNodeStage'] ?? 'ready',
            'nodeAtTarget' => $nodeAtTarget,
            'nodeConnected' => $nodeAtTarget && $current['connected'] && ! $current['disabled'],
            'oldIp' => $original['address'],
            'newIp' => $state['ip'],
            'hosts' => is_array($plan['hosts'] ?? null) ? count($plan['hosts']) : null,
            'operations' => count($operations),
            'verified' => $verified,
            'pending' => $pending,
            'uncertain' => $uncertain,
            'hasPlan' => $plan !== null,
            'complete' => $plan !== null && $pending === 0 && $uncertain === 0 && $verified === count($operations),
        ];
    }

    /** Build a fresh, bounded plan from the Node's explicit Host associations and Cloudflare records. */
    public function previewReplacementHosts(int $actorId, string $id): array
    {
        $this->writes();
        $state = $this->installations->context($actorId, $id);
        $original = $state['replacementNode'] ?? null;
        if (! is_array($original) || ($state['replacementNodeStage'] ?? null) !== 'updated') {
            throw new RuntimeException('Node address replacement is not verified yet.');
        }
        $existingPlan = $state['replacementHosts'] ?? null;
        if (is_array($existingPlan)) {
            $operations = [
                ...(is_array($existingPlan['hostUpdates'] ?? null) ? $existingPlan['hostUpdates'] : []),
                ...(is_array($existingPlan['dnsUpdates'] ?? null) ? $existingPlan['dnsUpdates'] : []),
            ];
            if (count(array_filter($operations, static fn (mixed $item): bool => is_array($item) && ($item['stage'] ?? null) === 'uncertain')) > 0) {
                throw new RuntimeException('Reconcile the previous Host/DNS operation before preparing another confirmation.');
            }
        }

        $plan = $this->buildReplacementHostPlan($actorId, $state, $original, true);
        $this->installations->checkpoint($actorId, $id, ['replacementHosts' => $plan]);

        return $plan;
    }

    /**
     * @param array<string,mixed> $state
     * @param array{uuid:string,name:string,address:string,port:int,connected:bool,disabled:bool,profileUuid:string,inboundUuids:list<string>} $original
     * @return array{hosts:list<array<string,mixed>>,hostUpdates:list<array<string,mixed>>,dnsUpdates:list<array<string,mixed>>}
     */
    private function buildReplacementHostPlan(int $actorId, array $state, array $original, bool $nodeAtTarget): array
    {
        $node = $this->nodes->replacementSnapshot($actorId, $original['uuid']);
        $expectedAddress = $nodeAtTarget ? $state['ip'] : $original['address'];
        if ($node['address'] !== $expectedAddress || ! $node['connected'] || $node['disabled']
            || $node['profileUuid'] !== $original['profileUuid']
            || $node['inboundUuids'] !== $original['inboundUuids']) {
            throw new RuntimeException('Node is not connected with the expected original bindings and address.');
        }

        $linkedHosts = $this->hosts->forNode($actorId, $original['uuid']);
        if (count($linkedHosts) > 20) {
            throw new RuntimeException('More than 20 Hosts are linked to this Node; manual review is required.');
        }

        $hostUpdates = [];
        $dnsUpdates = [];
        $displayHosts = [];
        foreach ($linkedHosts as $host) {
            if ($host['nodes'] !== [$original['uuid']]) {
                throw new RuntimeException('A Host is shared with another Node; automatic replacement is blocked.');
            }
            $addressIsIp = filter_var($host['address'], FILTER_VALIDATE_IP) !== false;
            if ($addressIsIp) {
                if (! in_array($host['address'], [$original['address'], $state['ip']], true)) {
                    throw new RuntimeException('A linked Host points to an unexpected IP; manual review is required.');
                }
                $stage = $host['address'] === $state['ip'] ? 'verified' : 'pending';
                $hostUpdates[] = [
                    'expected' => $host,
                    'target' => $state['ip'],
                    'stage' => $stage,
                ];
                $displayHosts[] = [
                    'uuid' => $host['uuid'],
                    'remark' => $host['remark'], 'address' => $host['address'],
                    'port' => $host['port'], 'kind' => 'ip',
                    'currentIp' => $host['address'], 'proxied' => null, 'stage' => $stage,
                ];
                continue;
            }

            $dns = $this->dns->uniqueAForHostname($actorId, $host['address']);
            $record = $dns['record'];
            if (! in_array($record['content'], [$original['address'], $state['ip']], true)) {
                throw new RuntimeException('A linked Host DNS record points to an unexpected IP; manual review is required.');
            }
            $key = $dns['zoneId'].':'.$record['id'];
            $stage = $record['content'] === $state['ip'] ? 'verified' : 'pending';
            if (! isset($dnsUpdates[$key])) {
                $dnsUpdates[$key] = [
                    'zoneId' => $dns['zoneId'],
                    'zone' => $dns['zone'],
                    'expected' => $record,
                    'target' => $state['ip'],
                    'stage' => $stage,
                ];
            } elseif ($dnsUpdates[$key]['expected'] !== $record) {
                throw new RuntimeException('A shared Host DNS record changed during preview.');
            }
            $displayHosts[] = [
                'uuid' => $host['uuid'],
                'remark' => $host['remark'], 'address' => $host['address'],
                'port' => $host['port'], 'kind' => 'dns',
                'currentIp' => $record['content'], 'proxied' => $record['proxied'], 'stage' => $stage,
            ];
        }

        return [
            'hosts' => $displayHosts,
            'hostSnapshots' => $linkedHosts,
            'hostUpdates' => $hostUpdates,
            'dnsUpdates' => array_values($dnsUpdates),
        ];
    }

    /** One confirmation for the complete bounded transfer; every external write is still independently guarded and checkpointed. */
    public function transferReplacementHosts(int $actorId, string $id, array $expectedPlan): void
    {
        $this->writes();
        Cache::lock('nw03:replacement-flow:'.$id, 600)->block(2, function () use ($actorId, $id, $expectedPlan): void {
            $state = $this->installations->context($actorId, $id);
            $original = $state['replacementNode'] ?? null;
            $plan = $state['replacementHosts'] ?? null;
            if (! is_array($original) || ! is_array($plan) || $plan !== $expectedPlan
                || ($state['replacementNodeStage'] ?? null) !== 'updated') {
                throw new RuntimeException('Host/DNS replacement confirmation is stale.');
            }
            $node = $this->nodes->replacementSnapshot($actorId, $original['uuid']);
            if ($node['address'] !== $state['ip'] || ! $node['connected'] || $node['disabled']) {
                throw new RuntimeException('New Node is not connected and enabled.');
            }

            foreach ($plan['hostUpdates'] as $index => $operation) {
                if (($operation['stage'] ?? null) === 'verified') {
                    continue;
                }
                $expected = $operation['expected'];
                $currentHosts = $this->hosts->forNode($actorId, $original['uuid']);
                $current = collect($currentHosts)->firstWhere('uuid', $expected['uuid']);
                $target = $operation['target'];
                $expectedAfter = [...$expected, 'address' => $target];
                if ($current === $expectedAfter) {
                    $plan['hostUpdates'][$index]['stage'] = 'verified';
                    $this->installations->checkpoint($actorId, $id, ['replacementHosts' => $plan]);
                    continue;
                }
                if ($current !== $expected || $expected['address'] === $target) {
                    throw new RuntimeException('A linked Host changed since confirmation.');
                }

                $plan['hostUpdates'][$index]['stage'] = 'uncertain';
                $this->installations->checkpoint($actorId, $id, ['replacementHosts' => $plan]);
                $this->hosts->change(
                    $actorId,
                    $expected['uuid'],
                    'edit',
                    array_diff_key($expected, ['nodes' => true]),
                    ['remark' => $expected['remark'], 'address' => $target, 'port' => $expected['port']],
                );
                $updatedHosts = $this->hosts->forNode($actorId, $original['uuid']);
                $updated = collect($updatedHosts)->firstWhere('uuid', $expected['uuid']);
                if ($updated !== $expectedAfter) {
                    throw new RuntimeException('Host address update could not be verified.');
                }
                $plan['hostUpdates'][$index]['stage'] = 'verified';
                $this->installations->checkpoint($actorId, $id, ['replacementHosts' => $plan]);
            }

            foreach ($plan['dnsUpdates'] as $index => $operation) {
                if (($operation['stage'] ?? null) === 'verified') {
                    continue;
                }
                $record = $operation['expected'];
                $current = $this->dns->oneUniqueA($actorId, $operation['zoneId'], $operation['zone'], $record['id']);
                if ($current['content'] === $operation['target'] && $current['proxied'] === $record['proxied']) {
                    $plan['dnsUpdates'][$index]['stage'] = 'verified';
                    $this->installations->checkpoint($actorId, $id, ['replacementHosts' => $plan]);
                    continue;
                }
                if ($current !== $record) {
                    throw new RuntimeException('A linked DNS record changed since confirmation.');
                }

                $this->dns->changeA(
                    $actorId,
                    $operation['zoneId'],
                    $operation['zone'],
                    $record,
                    $operation['target'],
                    $record['proxied'],
                    function () use ($actorId, $id, &$plan, $index): void {
                        $plan['dnsUpdates'][$index]['stage'] = 'uncertain';
                        $this->installations->checkpoint($actorId, $id, ['replacementHosts' => $plan]);
                    },
                    requireUniqueHostname: true,
                );
                $plan['dnsUpdates'][$index]['stage'] = 'verified';
                $this->installations->checkpoint($actorId, $id, ['replacementHosts' => $plan]);
            }
        });
    }

    /** GET-only recovery. Unchanged old endpoints become pending for a new confirmation; changed endpoints are never replayed blindly. */
    public function reconcileReplacementHosts(int $actorId, string $id): void
    {
        $this->writes();
        Cache::lock('nw03:replacement-flow:'.$id, 600)->block(2, function () use ($actorId, $id): void {
            $state = $this->installations->context($actorId, $id);
            $original = $state['replacementNode'] ?? null;
            $plan = $state['replacementHosts'] ?? null;
            if (! is_array($original) || ! is_array($plan)) {
                throw new RuntimeException('No Host/DNS replacement checkpoint exists.');
            }

            foreach ($plan['hostUpdates'] as $index => $operation) {
                if (($operation['stage'] ?? null) !== 'uncertain') {
                    continue;
                }
                $expected = $operation['expected'];
                $currentHosts = $this->hosts->forNode($actorId, $original['uuid']);
                $current = collect($currentHosts)->firstWhere('uuid', $expected['uuid']);
                if ($current === [...$expected, 'address' => $operation['target']]) {
                    $plan['hostUpdates'][$index]['stage'] = 'verified';
                } elseif ($current === $expected) {
                    $plan['hostUpdates'][$index]['stage'] = 'pending';
                } else {
                    throw new RuntimeException('Host state is neither old nor target; manual review required.');
                }
                $this->installations->checkpoint($actorId, $id, ['replacementHosts' => $plan]);
            }

            foreach ($plan['dnsUpdates'] as $index => $operation) {
                if (($operation['stage'] ?? null) !== 'uncertain') {
                    continue;
                }
                $expected = $operation['expected'];
                $current = $this->dns->oneUniqueA($actorId, $operation['zoneId'], $operation['zone'], $expected['id']);
                if ($current['content'] === $operation['target'] && $current['proxied'] === $expected['proxied']) {
                    $plan['dnsUpdates'][$index]['stage'] = 'verified';
                } elseif ($current === $expected) {
                    $plan['dnsUpdates'][$index]['stage'] = 'pending';
                } else {
                    throw new RuntimeException('DNS state is neither old nor target; manual review required.');
                }
                $this->installations->checkpoint($actorId, $id, ['replacementHosts' => $plan]);
            }
        });
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
