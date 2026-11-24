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
    ) {}

    public function enabled(): bool
    {
        return config('services.arianet_cloud.operations_enabled') === true
            && filled(config('services.arianet_cloud.read_token'));
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
    public function prepare(int $actor, int $serverId, string $operation): array
    {
        $this->access->handle($actor);
        $this->guard();

        return Cache::lock('arianet:ipv4:'.$serverId, 120)->block(2, function () use ($actor, $serverId, $operation): array {
            $snapshot = $this->snapshot($actor, $serverId, $operation);
            $token = bin2hex(random_bytes(12));
            DB::transaction(function () use ($actor, $serverId, $operation, $snapshot, $token): void {
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

        Cache::lock('arianet:ipv4:'.$serverId, 120)->block(2, function () use ($actor, $serverId, $token): void {
            $row = $this->prepared($actor, $serverId, $token);
            if (now()->greaterThan($row->expires_at)
                || $row->snapshot_hash !== self::fingerprint($this->snapshot($actor, $serverId, $row->operation))) {
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
                $receipt = $this->write->powerAction($serverId, $row->operation);
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
        Cache::lock('arianet:ipv4:'.$serverId, 120)->block(2, function () use ($actor, $serverId, $token): void {
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
        Cache::lock('arianet:ipv4:'.$serverId, 120)->block(2, function () use ($serverId): void {
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
                $server = $this->read->server($serverId)['data'];
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
    private function snapshot(int $actor, int $serverId, string $operation): array
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
        if (($server['id'] ?? null) !== $serverId || ! is_string($server['name'] ?? null)
            || ! is_string($server['status'] ?? null) || ! in_array($operation, self::available($server['status']), true)
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

        return [
            'serverId' => $serverId,
            'name' => $server['name'],
            'status' => $server['status'],
            'ipv4' => $server['ipv4'],
            'operation' => $operation,
            'link' => $this->links->linked($actor, $serverId),
        ];
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
