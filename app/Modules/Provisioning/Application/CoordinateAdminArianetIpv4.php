<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * ARI-03D: DNS-only continuation after a confirmed Cloud IPv4 change.
 * Each PATCH is journaled first; ambiguous outcomes use GET-only recovery.
 */
final readonly class CoordinateAdminArianetIpv4
{
    private const TABLE = 'arianet_cloud_ipv4_changes';

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private InspectAdminArianetIpv4Dependencies $impact,
        private ChangeAdminArianetIpv4 $changes,
        private ReadAdminArianetCloud $cloud,
        private PreviewAdminArianetIpv4Change $preview,
        private ManageAdminNodes $nodes,
        private ManageAdminHosts $hosts,
        private ManageCloudflareDns $dns,
    ) {}

    public function enabled(): bool
    {
        return $this->dns->wizardEnabled();
    }

    /**
     * Read-only status. Does not disclose raw provider snapshots or credentials.
     *
     * @return array<string,mixed>|null
     */
    public function status(int $actorId, int $serverId): ?array
    {
        $this->access->handle($actorId);
        $operation = $this->operation($serverId);
        $state = $operation === null ? null : $this->decode($operation->coordination_state);
        if ($state === null) {
            return null;
        }

        return [
            'stage' => $state['stage'],
            'oldIp' => $state['oldIp'],
            'newIp' => $state['newIp'],
            'node' => $state['node']['name'],
            'owner' => $state['owner'] === $actorId,
            'steps' => array_map(static fn (array $step): array => [
                'kind' => $step['kind'],
                'name' => $step['name'],
                'state' => $step['state'],
            ], $state['steps']),
            'next' => $this->next($state),
        ];
    }

    /** @return array<string,mixed> */
    public function prepare(int $actorId, int $serverId): array
    {
        $this->access->handle($actorId);
        $this->assertEnabled();

        return $this->lock($serverId, function () use ($actorId, $serverId): array {
            $this->access->handle($actorId);
            $row = $this->active($serverId);
            if ((int) $row->actor_user_id !== $actorId
                || $row->status !== 'awaiting_coordination' || $row->coordination_state !== null) {
                throw new RuntimeException('IPv4 coordination is unavailable or already registered.');
            }

            // The existing complete impact audit is the ownership gate. Never
            // infer that a Host belonging to a different VPS is safe to move.
            $report = $this->impact->handle($actorId, $serverId);
            if ($report['ready'] !== true || $report['issues'] !== []
                || ! is_string($report['newIp']) || $report['newIp'] !== $row->new_ipv4
                || $report['oldIp'] !== $row->old_ipv4 || count($report['hosts']) > 10
                || count($report['dns']) > 10) {
                throw new RuntimeException('Host/Node/DNS ownership is not fully verified; review the impact report.');
            }
            $old = $row->old_ipv4;
            $new = $row->new_ipv4;
            if (! self::publicIp($old) || ! self::publicIp($new) || $old === $new) {
                throw new RuntimeException('Cloud old or new public IPv4 is unverified.');
            }

            $cloud = $this->cloud->detail($actorId, $serverId);
            $identity = $this->preview->handle($actorId, $serverId);
            if ($cloud['ipv4'] !== $new || $identity['nodeUuid'] === null) {
                throw new RuntimeException('Cloud IPv4 or linked Node changed.');
            }

            $node = $this->nodes->replacementSnapshot($actorId, $identity['nodeUuid']);
            $inventory = $this->hosts->replacementInventory($actorId);
            $peers = $this->nodes->replacementAddresses($actorId);
            $hostIndex = array_column($inventory, null, 'uuid');
            $steps = [];

            foreach ($report['dns'] as $entry) {
                $snapshot = $this->dns->replacementRecord($actorId, $entry['hostname']);
                $record = $snapshot['record'];
                if ($record['content'] !== $old || $record['name'] !== $entry['hostname']
                    || $record['proxied'] !== $entry['proxied'] || $record['editable'] !== true
                    || ! $entry['matched']) {
                    throw new RuntimeException('DNS record changed after dependency review.');
                }
                $steps[] = [
                    'kind' => 'dns', 'name' => $record['name'], 'state' => 'ready',
                    'zoneId' => $snapshot['zoneId'], 'zone' => $snapshot['zone'],
                    'record' => $record,
                ];
            }

            foreach ($report['hosts'] as $host) {
                $current = $hostIndex[$host['uuid']] ?? null;
                if ($current === null || $current['nodes'] !== [$node['uuid']]
                    || $host['shared'] || $host['unverified'] || $host['type'] !== 'hostname'
                    || $current['address'] !== $host['address']) {
                    throw new RuntimeException('Host ownership changed after dependency review.');
                }
            }
            // Existing Node/Host domains and UUIDs are never modified.
            if ($steps === [] || count($steps) > 10) {
                throw new RuntimeException('No bounded DNS coordination steps are available.');
            }

            $state = [
                'version' => 1, 'stage' => 'ready', 'owner' => $actorId,
                'oldIp' => $old, 'newIp' => $new, 'serverId' => $serverId,
                'node' => $node, 'inventory' => $inventory, 'peers' => $peers,
                'steps' => $steps,
            ];
            $updated = DB::table(self::TABLE)->where('id', $row->id)
                ->where('status', 'awaiting_coordination')
                ->whereNull('coordination_state')
                ->update(['coordination_state' => self::encode($state), 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new RuntimeException('Coordination plan changed while saving.');
            }

            return $this->status($actorId, $serverId)
                ?? throw new RuntimeException('Coordination plan was not saved.');
        });
    }

    /** One explicit operator-confirmed, non-repeatable provider mutation. */
    public function executeNext(int $actorId, int $serverId, int $index): void
    {
        $this->access->handle($actorId);
        $this->assertEnabled();

        $this->lock($serverId, function () use ($actorId, $serverId, $index): void {
            $this->access->handle($actorId);
            [$row, $state] = $this->load($serverId, $actorId);
            if (! in_array($state['stage'], ['ready', 'applying'], true)
                || $this->next($state) !== $index || ($state['steps'][$index]['state'] ?? null) !== 'ready') {
                throw new RuntimeException('This coordination confirmation was already used or is not next.');
            }
            $this->assertCloudAndInventory($actorId, $serverId, $state);
            $this->assertCurrentStep($actorId, $state, $index);
            // Checkpoint BEFORE sending any external write. Even if PHP exits
            // or an upstream timeout occurs, the step cannot be sent again.
            $state['stage'] = 'applying';
            $state['steps'][$index]['state'] = 'uncertain';
            $this->save((int) $row->id, $state);

            try {
                $step = $state['steps'][$index];
                if ($step['kind'] !== 'dns') {
                    throw new RuntimeException('Only DNS A records may be modified.');
                }
                $this->dns->changeA($actorId, $step['zoneId'], $step['zone'],
                    $step['record'], $state['newIp'], $step['record']['proxied'],
                    requireUniqueHostname: true);
                $this->assertStepAtNewIp($actorId, $state, $index);
                $this->completeStep($row->id, $state, $index);
            } catch (Throwable) {
                // No replay, even on a seemingly definite provider error:
                // the durable state remains uncertain until GET reconciliation.
                throw new RuntimeException('Provider outcome requires manual GET reconciliation; do not repeat.');
            }
        });
    }

    /** Continue verified DNS steps without any additional approval. */
    public function continueDns(int $actorId, int $serverId): void
    {
        $this->access->handle($actorId);
        $this->assertEnabled();
        $state = $this->status($actorId, $serverId) ?? $this->prepare($actorId, $serverId);
        while ($state['next'] !== null && $state['steps'][$state['next']]['state'] === 'ready') {
            $this->executeNext($actorId, $serverId, $state['next']);
            $state = $this->status($actorId, $serverId)
                ?? throw new RuntimeException('DNS operation state is missing.');
        }
    }

    /** Read-only provider reconciliation; may update only the local journal. */
    public function reconcile(int $actorId, int $serverId): void
    {
        $this->access->handle($actorId);
        $this->assertEnabled();

        $this->lock($serverId, function () use ($actorId, $serverId): void {
            $this->access->handle($actorId);
            [$row, $state] = $this->load($serverId, $actorId);
            $index = $this->next($state);
            if ($state['stage'] !== 'applying' || $index === null
                || ($state['steps'][$index]['state'] ?? null) !== 'uncertain') {
                throw new RuntimeException('No ambiguous step is awaiting GET reconciliation.');
            }

            $this->assertCloud($actorId, $serverId, $state);
            $this->assertStepAtNewIp($actorId, $state, $index);
            $this->completeStep($row->id, $state, $index);
        });
    }

    /**
     * An independent human must test real client connectivity. A green Node
     * flag alone is not considered proof; all endpoints are re-read first.
     */
    public function acceptClientTest(int $actorId, int $serverId): void
    {
        $this->access->handle($actorId);
        $this->assertEnabled();

        $this->lock($serverId, function () use ($actorId, $serverId): void {
            $this->access->handle($actorId);
            [$row, $state] = $this->load($serverId, $actorId);
            if ($state['stage'] !== 'awaiting_client_test' || $this->next($state) !== null) {
                throw new RuntimeException('Coordination still has unverified steps.');
            }
            $this->assertCloudAndInventory($actorId, $serverId, $state);
            $node = $this->nodes->replacementSnapshot($actorId, $state['node']['uuid']);
            if ($node['disabled'] || ! $node['connected']
                || self::nodeIdentity($node) !== self::nodeIdentity($state['node'])) {
                throw new RuntimeException('Node is disconnected or changed; client test cannot be accepted.');
            }
            foreach (array_keys($state['steps']) as $index) {
                $this->assertStepAtNewIp($actorId, $state, $index);
            }
            $state['stage'] = 'completed';
            $state['clientTestAcceptedBy'] = $actorId;
            $state['clientTestAcceptedAt'] = now()->toIso8601String();
            $this->save((int) $row->id, $state);

            // Release only after a human confirms actual client use and
            // every provider endpoint passes its independent GET.
            DB::table(self::TABLE)->where('id', $row->id)
                ->where('status', 'awaiting_coordination')
                ->where('active_server_id', $serverId)
                ->update([
                    'status' => 'completed', 'active_server_id' => null, 'updated_at' => now(),
                ]);
        });
    }

    /** @param array<string,mixed> $state */
    private function assertCloudAndInventory(int $actorId, int $serverId, array $state): void
    {
        $this->assertCloud($actorId, $serverId, $state);
        $node = $this->nodes->replacementSnapshot($actorId, $state['node']['uuid']);
        if (self::nodeIdentity($node) !== self::nodeIdentity($state['node'])) {
            throw new RuntimeException('Node identity changed during DNS coordination.');
        }
        // Unrelated Hosts can change without interrupting the selected Node.
        $relevant = static fn (array $items): array => array_values(array_filter(
            $items,
            static fn (array $host): bool => in_array($state['node']['uuid'], $host['nodes'], true)
                || strtolower($host['address']) === strtolower($state['node']['address']),
        ));
        if ($relevant($this->hosts->replacementInventory($actorId)) !== $relevant($state['inventory'])) {
            throw new RuntimeException('Related Host assignments changed.');
        }
        foreach ($this->nodes->replacementAddresses($actorId) as $peer) {
            if ($peer['uuid'] !== $state['node']['uuid']
                && in_array($peer['address'], [$state['node']['address'], $state['oldIp'], $state['newIp']], true)) {
                throw new RuntimeException('A peer Node shares this Cloud endpoint.');
            }
        }
    }

    /** @param array<string,mixed> $state */
    private function assertCloud(int $actorId, int $serverId, array $state): void
    {
        $detail = $this->cloud->detail($actorId, $serverId);
        if ($detail['ipv4'] !== $state['newIp']
            || ! self::publicIp($detail['ipv4'])
            || $this->changes->status($actorId, $serverId)['status'] !== 'awaiting_coordination') {
            throw new RuntimeException('Cloud IPv4 or operation status changed.');
        }
        $link = $this->preview->handle($actorId, $serverId);
        if ($link['nodeUuid'] !== $state['node']['uuid']) {
            throw new RuntimeException('Cloud Node mapping changed.');
        }
    }

    /** @param array<string,mixed> $state */
    private function assertCurrentStep(int $actorId, array $state, int $index): void
    {
        $step = $state['steps'][$index];
        if ($step['kind'] !== 'dns') {
            throw new RuntimeException('Only managed DNS A records may be coordinated.');
        }
        $current = $this->dns->oneUniqueA($actorId, $step['zoneId'], $step['zone'], $step['record']['id']);
        if ($current !== $step['record']) {
            throw new RuntimeException('DNS record changed since the confirmed preview.');
        }
    }

    /** @param array<string,mixed> $state */
    private function assertStepAtNewIp(int $actorId, array $state, int $index): void
    {
        $step = $state['steps'][$index];
        if ($step['kind'] !== 'dns') {
            throw new RuntimeException('Only managed DNS A records may be coordinated.');
        }
        $current = $this->dns->oneUniqueA($actorId, $step['zoneId'], $step['zone'], $step['record']['id']);
        if ($current['name'] !== $step['record']['name']
            || $current['content'] !== $state['newIp']
            || $current['proxied'] !== $step['record']['proxied']) {
            throw new RuntimeException('DNS update has not been verified.');
        }
    }

    /** @param array<string,mixed> $state */
    private function completeStep(int $rowId, array $state, int $index): void
    {
        $state['steps'][$index]['state'] = 'verified';
        $state['stage'] = $this->next($state) === null ? 'awaiting_client_test' : 'applying';
        $this->save($rowId, $state);
    }

    /** @param array<string,mixed> $state */
    private function next(array $state): ?int
    {
        foreach ($state['steps'] as $index => $step) {
            if ($step['state'] !== 'verified') {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    private static function nodeIdentity(array $state): array
    {
        // Connection status is observational and can change after DNS
        // propagation. Preserve UUID, address, profile, inbound and flags.
        return array_intersect_key($state, array_flip([
            'uuid', 'name', 'address', 'port', 'disabled', 'profileUuid', 'inboundUuids',
        ]));
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Cloud coordination writes are disabled in this environment.');
        }
    }

    private function operation(int $serverId): ?object
    {
        if ($serverId < 1) {
            throw new RuntimeException('Invalid Cloud server ID.');
        }

        return DB::table(self::TABLE)->where('cloud_server_id', $serverId)->latest('id')->first();
    }

    private function active(int $serverId): object
    {
        $row = DB::table(self::TABLE)->where('active_server_id', $serverId)->first();
        if ($row === null) {
            throw new RuntimeException('No active Cloud IP change exists.');
        }

        return $row;
    }

    /** @return array{0:object,1:array<string,mixed>} */
    private function load(int $serverId, int $actorId): array
    {
        $row = $this->active($serverId);
        $state = $this->decode($row->coordination_state);
        if ($row->status !== 'awaiting_coordination' || $state === null
            || $state['owner'] !== $actorId || $state['serverId'] !== $serverId
            || $state['oldIp'] !== $row->old_ipv4 || $state['newIp'] !== $row->new_ipv4) {
            throw new RuntimeException('Operation belongs to another actor or has changed.');
        }

        return [$row, $state];
    }

    /** @return array<string,mixed>|null */
    private function decode(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }
        $state = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($state) || ($state['version'] ?? null) !== 1
            || ! is_array($state['steps'] ?? null) || ! is_array($state['node'] ?? null)
            || ! is_array($state['inventory'] ?? null) || ! is_array($state['peers'] ?? null)
            || ! is_string($state['stage'] ?? null)) {
            throw new RuntimeException('Coordination checkpoint is invalid.');
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function save(int $rowId, array $state): void
    {
        DB::table(self::TABLE)->where('id', $rowId)->update([
            'coordination_state' => self::encode($state), 'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $state */
    private static function encode(array $state): string
    {
        return json_encode($state, JSON_THROW_ON_ERROR);
    }

    private static function publicIp(mixed $ip): bool
    {
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @template T
     * @param  callable():T  $callback
     * @return T
     */
    private function lock(int $serverId, callable $callback): mixed
    {
        return Cache::lock('arianet:ipv4:'.$serverId, 300)->block(2, $callback);
    }
}
