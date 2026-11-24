<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudReadClient;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudWriteClient;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** Single-use, durable Cloud operations; provider outcomes are reconciled by GET only. */
final readonly class ManageAdminArianetOperations
{
    private const TABLE = 'arianet_cloud_operations';

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ArianetCloudReadClient $read,
        private ArianetCloudWriteClient $write,
        private ManageAdminArianetCloudLinks $links,
        private ManageAdminNodes $nodes,
        private ManageAdminHosts $hosts,
        private ManageCloudflareDns $dns,
        private PreviewAdminArianetPurchase $catalog,
        private ReadAdminArianetCloud $inventory,
    ) {}

    public function enabled(): bool
    {
        return config('services.arianet_cloud.operations_enabled') === true
            && filled(config('services.arianet_cloud.read_token'));
    }

    public function maintenanceEnabled(): bool
    {
        return $this->enabled() && config('services.arianet_cloud.maintenance_enabled') === true;
    }

    /** @return list<array{value:string,label:string}> */
    public function choices(int $actor, int $serverId, string $operation): array
    {
        $this->access->handle($actor);
        if (! $this->maintenanceEnabled() || ! in_array($operation, ['rebuild', 'change_plan'], true)) {
            throw new RuntimeException('Cloud maintenance is disabled.');
        }
        $server = $this->read->server($serverId)['data'];
        if (($server['id'] ?? null) !== $serverId || ! is_string($server['datacenter'] ?? null)
            || ! is_int($server['plan_id'] ?? null)) {
            throw new RuntimeException('Cloud server identity cannot be verified.');
        }
        if ($operation === 'rebuild') {
            $images = $this->catalog->images($actor, $server['datacenter'], $server['plan_id']);

            return array_map(static fn (array $image): array => ['value' => $image['image'], 'label' => $image['name']], $images);
        }
        if (! in_array($server['status'] ?? null, ['stopped', 'powered_off', 'poweredoff'], true)) {
            throw new RuntimeException('Stop the server before changing its plan.');
        }
        $current = $this->catalog->plan($actor, $server['datacenter'], $server['plan_id']);
        $rows = $this->read->plans($server['datacenter'])['data'];
        if (! array_is_list($rows) || count($rows) > 200) {
            throw new RuntimeException('Cloud plan catalog is unverified.');
        }
        $choices = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] < 1 || $row['id'] === $current['id']
                || ($row['datacenter'] ?? null) !== $server['datacenter']
                || ($row['is_active'] ?? null) !== true || ($row['availability']['available'] ?? null) !== true
                || ($row['currency'] ?? null) !== 'IRR'
                || ! is_int($row['vcpu'] ?? null) || ! is_int($row['ram_mb'] ?? null) || ! is_int($row['disk_gb'] ?? null)
                || $row['vcpu'] < $current['cores'] || $row['ram_mb'] < $current['memoryMb'] || $row['disk_gb'] < $current['diskGb']
                || ! is_int($server['disk_gb'] ?? null) || $row['disk_gb'] < $server['disk_gb']
                || ! is_int($row['hourly_price'] ?? null) || $row['hourly_price'] < $current['hourlyRials']
                || ! is_int($row['monthly_price'] ?? null) || $row['monthly_price'] < $current['monthlyRials']) {
                continue;
            }
            $choices[] = [
                'value' => (string) $row['id'],
                'label' => $row['vcpu'].' vCPU · '.$row['ram_mb'].' MB RAM · '.$row['disk_gb'].' GB Disk',
            ];
        }

        return $choices;
    }

    /** @return list<string> */
    public static function available(string $state): array
    {
        return match ($state) {
            'running' => ['shutdown', 'reboot'],
            'stopped', 'powered_off', 'poweredoff' => ['poweron'],
            default => [],
        };
    }

    /** Prevent IPv4 changes even when operations are switched off after a write. */
    public static function assertNoActive(int $serverId): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            if (config('services.arianet_cloud.operations_enabled') === true) {
                throw new RuntimeException('Cloud operations migration is missing.');
            }

            return;
        }

        if (DB::table(self::TABLE)->where('active_server_id', $serverId)
            ->where(function (Builder $query): void {
                $query->where('status', '!=', 'prepared')->orWhere('expires_at', '>=', now());
            })->exists()) {
            throw new RuntimeException('A Cloud server operation is awaiting review.');
        }
    }

    /** @return array{token:string,snapshot:array<string,mixed>} */
    public function prepare(int $actor, int $serverId, string $operation, string $target = ''): array
    {
        $this->access->handle($actor);
        $this->guard();

        return Cache::lock('arianet:ipv4:'.$serverId, 600)->block(2, function () use ($actor, $serverId, $operation, $target): array {
            $snapshot = $this->snapshot($actor, $serverId, $operation, $target);
            $token = bin2hex(random_bytes(12));
            DB::transaction(function () use ($actor, $serverId, $operation, $snapshot, $token, $target): void {
                $active = DB::table(self::TABLE)->where('active_server_id', $serverId)->lockForUpdate()->first();
                if ($active !== null) {
                    if ($active->status !== 'prepared' || now()->lessThanOrEqualTo($active->expires_at)) {
                        throw new RuntimeException('A previous operation requires review.');
                    }
                    $this->finish((int) $active->id, 'expired');
                }

                DB::table(self::TABLE)->insert([
                    'server_id' => $serverId,
                    'active_server_id' => $serverId,
                    'actor_user_id' => $actor,
                    'operation' => $operation,
                    'target' => $target === '' ? null : $target,
                    'token_hash' => hash('sha256', $token),
                    'snapshot_hash' => self::fingerprint($snapshot),
                    'status' => 'prepared',
                    'expires_at' => now()->addMinutes(10),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            return ['token' => $token, 'snapshot' => $snapshot];
        });
    }

    public function confirm(int $actor, int $serverId, string $token): void
    {
        $this->access->handle($actor);
        $this->guard();

        Cache::lock('arianet:cloud-node-links:mutation', 600)->block(2, function () use ($actor, $serverId, $token): void {
            $this->confirmLocked($actor, $serverId, $token);
        });
    }

    private function confirmLocked(int $actor, int $serverId, string $token): void
    {
        Cache::lock('arianet:ipv4:'.$serverId, 600)->block(2, function () use ($actor, $serverId, $token): void {
            $row = $this->prepared($actor, $serverId, $token);
            if (now()->greaterThan($row->expires_at)
                || $row->snapshot_hash !== self::fingerprint($this->snapshot($actor, $serverId, $row->operation, $row->target ?? ''))) {
                $this->finish((int) $row->id, 'expired');
                throw new RuntimeException('Cloud operation confirmation expired or changed.');
            }
            $this->access->handle($actor);
            $this->guard();
            if (DB::table(self::TABLE)->where('id', $row->id)->where('status', 'prepared')
                ->update(['status' => 'reserved', 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Confirmation already consumed.');
            }

            try {
                $receipt = in_array($row->operation, ['rebuild', 'change_plan', 'delete'], true)
                    ? $this->write->maintenanceAction($serverId, $row->operation, $row->target ?? '')
                    : $this->write->powerAction($serverId, $row->operation);
                DB::table(self::TABLE)->where('id', $row->id)->update([
                    'status' => 'accepted', 'action_id' => $receipt['action_id'], 'updated_at' => now(),
                ]);
            } catch (Throwable) {
                // A consumed confirmation is never sent again, even after an apparent rejection.
                DB::table(self::TABLE)->where('id', $row->id)->update([
                    'status' => 'uncertain', 'updated_at' => now(),
                ]);
            }
        });
    }

    public function cancel(int $actor, int $serverId, string $token): void
    {
        $this->access->handle($actor);
        Cache::lock('arianet:ipv4:'.$serverId, 600)->block(2, function () use ($actor, $serverId, $token): void {
            $row = $this->prepared($actor, $serverId, $token);
            $this->finish((int) $row->id, 'cancelled');
        });
    }

    /** @return array{operation:string,status:string,actionId:int|null}|null */
    public function status(int $actor, int $serverId): ?array
    {
        $this->access->handle($actor);
        $row = DB::table(self::TABLE)->where('server_id', $serverId)->latest('id')->first();

        return $row === null ? null : [
            'operation' => $row->operation,
            'status' => $row->status,
            'actionId' => $row->action_id === null ? null : (int) $row->action_id,
        ];
    }

    public function reconcile(int $actor, int $serverId): void
    {
        $this->access->handle($actor);
        Cache::lock('arianet:ipv4:'.$serverId, 600)->block(2, function () use ($serverId): void {
            $row = DB::table(self::TABLE)->where('active_server_id', $serverId)->first();
            if ($row === null) {
                return;
            }
            if ($row->status === 'prepared' && now()->greaterThan($row->expires_at)) {
                $this->finish((int) $row->id, 'expired');

                return;
            }
            if ($row->status !== 'accepted' || $row->action_id === null) {
                return;
            }
            $action = $this->read->action((int) $row->action_id)['data'];
            if (($action['id'] ?? null) !== (int) $row->action_id
                || ($action['resource']['id'] ?? null) !== $serverId
                || ($action['resource']['type'] ?? null) !== 'server'
                || ($action['command'] ?? null) !== $row->operation) {
                throw new RuntimeException('Cloud action identity is unverified.');
            }
            if (($action['status'] ?? null) === 'error') {
                $this->finish((int) $row->id, 'failed');
            } elseif (($action['status'] ?? null) === 'success') {
                if ($row->operation === 'delete') {
                    if ($this->read->serverMissing($serverId)) {
                        $this->finish((int) $row->id, 'completed');
                    }

                    return;
                }
                $server = $this->read->server($serverId)['data'];
                if ($row->operation === 'change_plan') {
                    if (($server['id'] ?? null) === $serverId && ($server['plan_id'] ?? null) === (int) $row->target) {
                        $this->finish((int) $row->id, 'completed');
                    }

                    return;
                }
                if ($row->operation === 'rebuild') {
                    if (($server['id'] ?? null) === $serverId
                        && ($server['image'] ?? null) === $row->target
                        && in_array($server['status'] ?? null, ['running', 'stopped', 'powered_off', 'poweredoff'], true)) {
                        $this->finish((int) $row->id, 'completed');
                    }

                    return;
                }
                $status = $server['status'] ?? null;
                $expected = $row->operation === 'shutdown'
                    ? ['stopped', 'powered_off', 'poweredoff'] : ['running'];
                if (($server['id'] ?? null) === $serverId && in_array($status, $expected, true)) {
                    $this->finish((int) $row->id, 'completed');
                }
            }
        });
    }

    /** @return array<string,mixed> */
    private function snapshot(int $actor, int $serverId, string $operation, string $target): array
    {
        if ($serverId < 1 || DB::table('arianet_cloud_ipv4_changes')->where('active_server_id', $serverId)
            ->where(function (Builder $query): void {
                $query->where('status', '!=', 'prepared')->orWhere('expires_at', '>=', now());
            })->exists()) {
            throw new RuntimeException('Cloud IPv4 operation is pending.');
        }
        if (DB::table('arianet_cloud_purchases')->where('server_id', $serverId)->where('active_slot', 1)->exists()) {
            throw new RuntimeException('Cloud purchase is pending.');
        }
        $server = $this->read->server($serverId)['data'];
        $maintenance = in_array($operation, ['rebuild', 'change_plan', 'delete'], true);
        if (($server['id'] ?? null) !== $serverId || ! is_string($server['name'] ?? null)
            || ! is_string($server['status'] ?? null)
            || (! $maintenance && (! in_array($operation, self::available($server['status']), true) || $target !== ''))
            || ($server['ip_change']['pending'] ?? false) !== false
            || ! is_string($server['ipv4'] ?? null)) {
            throw new RuntimeException('Cloud server state cannot be verified.');
        }
        $actions = $this->read->serverActions($serverId, 1, 25)['data'];
        if (! array_is_list($actions) || count($actions) > 25) {
            throw new RuntimeException('Cloud action list cannot be verified.');
        }
        foreach ($actions as $action) {
            if (! is_array($action) || ! in_array($action['status'] ?? null, ['success', 'error', 'completed', 'failed'], true)) {
                throw new RuntimeException('Cloud server has an unfinished action.');
            }
        }

        $link = $this->links->linked($actor, $serverId);
        $extra = [];
        if ($maintenance) {
            if (! $this->maintenanceEnabled() || ($server['locked'] ?? null) !== false
                || ($server['rescue_enabled'] ?? null) !== false
                || ! in_array($server['status'], ['running', 'stopped', 'powered_off', 'poweredoff'], true)) {
                throw new RuntimeException('Cloud server maintenance state cannot be verified.');
            }
            if ($operation === 'delete' || $operation === 'rebuild') {
                $protection = $operation === 'delete' ? 'protection_delete' : 'protection_rebuild';
                if (($server[$protection] ?? null) !== false || $link !== null) {
                    throw new RuntimeException('Server protection or Node link blocks this operation.');
                }
                $this->assertUnused($actor, $server);
            }
            if ($operation === 'change_plan') {
                if (! in_array($server['status'], ['stopped', 'powered_off', 'poweredoff'], true)
                    || ! in_array($target, array_column($this->choices($actor, $serverId, $operation), 'value'), true)
                    || ! is_string($server['datacenter'] ?? null) || ! is_int($server['plan_id'] ?? null)) {
                    throw new RuntimeException('Cloud upgrade cannot be verified.');
                }
                $old = $this->catalog->plan($actor, $server['datacenter'], $server['plan_id']);
                $new = $this->catalog->plan($actor, $server['datacenter'], (int) $target);
                if ($new['cores'] < $old['cores'] || $new['memoryMb'] < $old['memoryMb']
                    || $new['diskGb'] < $server['disk_gb'] || $new['diskGb'] < $old['diskGb']
                    || $new['hourlyRials'] < $old['hourlyRials'] || $new['monthlyRials'] < $old['monthlyRials']) {
                    throw new RuntimeException('Cloud plan is no longer an eligible upgrade.');
                }
                $extra = ['oldPlan' => $old, 'newPlan' => $new];
            } elseif ($operation === 'rebuild') {
                if (! in_array($target, array_column($this->choices($actor, $serverId, $operation), 'value'), true)) {
                    throw new RuntimeException('Cloud system image cannot be verified.');
                }
                $extra = ['image' => $target];
            } else {
                if ($target !== '') {
                    throw new RuntimeException('Invalid Cloud deletion.');
                }
                $cost = $this->inventory->costs($actor, $serverId);
                if ($cost['hourlyRials'] === null || $cost['monthlyRials'] === null) {
                    throw new RuntimeException('Cloud cost cannot be verified.');
                }
                $extra = ['hourlyRials' => $cost['hourlyRials'], 'monthlyRials' => $cost['monthlyRials']];
            }
        }

        return [
            ...$extra,
            'serverId' => $serverId,
            'name' => $server['name'],
            'status' => $server['status'],
            'ipv4' => $server['ipv4'],
            'operation' => $operation,
            'link' => $link,
            'target' => $target,
        ];
    }

    /**
     * Require no managed Node, Host or direct DNS dependency. External
     * resources outside the approved managed inventory remain operator responsibility.
     *
     * @param array<string,mixed> $server
     */
    private function assertUnused(int $actor, array $server): void
    {
        $ip = $server['ipv4'] ?? null;
        $ipv6 = $server['ipv6'] ?? null;
        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) === false
            || ! array_key_exists('ipv6', $server)
            || ($ipv6 !== null && (! is_string($ipv6) || filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false))) {
            throw new RuntimeException('Cloud public addresses cannot be verified.');
        }
        $addresses = array_unique([
            ...array_column($this->nodes->replacementAddresses($actor), 'address'),
            ...array_column($this->hosts->replacementInventory($actor), 'address'),
        ]);
        if (count($addresses) > 20) {
            throw new RuntimeException('Dependency inventory exceeds the safe synchronous audit.');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                if (inet_pton($address) === inet_pton($ip) || ($ipv6 !== null && inet_pton($address) === inet_pton($ipv6))) {
                    throw new RuntimeException('A Node or Host still uses this server address.');
                }
            } else {
                $record = $this->dns->replacementRecord($actor, $address);
                if ($record['record']['content'] === $ip) {
                    throw new RuntimeException('A Node or Host still resolves to the server.');
                }
            }
        }
        $this->dns->assertNoIpReferences($actor, $ip);
        if ($ipv6 !== null) {
            $this->dns->assertNoIpReferences($actor, $ipv6);
        }
    }

    private function prepared(int $actor, int $serverId, string $token): object
    {
        if (preg_match('/\A[a-f0-9]{24}\z/D', $token) !== 1) {
            throw new RuntimeException('Invalid Cloud confirmation.');
        }
        $row = DB::table(self::TABLE)->where('active_server_id', $serverId)
            ->where('actor_user_id', $actor)->where('status', 'prepared')
            ->where('token_hash', hash('sha256', $token))->first();
        if ($row === null) {
            throw new RuntimeException('Cloud confirmation expired or already used.');
        }

        return $row;
    }

    private function guard(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Cloud operations are disabled.');
        }
    }

    private function finish(int $id, string $status): void
    {
        DB::table(self::TABLE)->where('id', $id)->update([
            'status' => $status, 'active_server_id' => null, 'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $snapshot */
    private static function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
