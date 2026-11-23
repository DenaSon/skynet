<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudReadClient;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudWriteClient;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudWriteUncertain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * ARI-03B: durable, one-shot IPv4 change in an isolated NON-production environment.
 * Never attempts DNS or Remnawave writes. Completion means the Cloud IP changed,
 * not that clients work; the active server remains blocked pending ARI-03C.
 */
final readonly class ChangeAdminArianetIpv4
{
    private const TABLE = 'arianet_cloud_ipv4_changes';

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private PreviewAdminArianetIpv4Change $previews,
        private ArianetCloudWriteClient $write,
        private ArianetCloudReadClient $read,
    ) {}

    public function enabled(): bool
    {
        $token = config('services.arianet_cloud.read_token');

        return ! app()->environment('production')
            && config('services.arianet_cloud.writes_enabled') === true
            && is_string($token) && trim($token) !== '';
    }

    /**
     * Reserve a short-lived, actor-bound confirmation and prevent competing
     * operations for the same Cloud server. Returns only an opaque token.
     *
     * @return array{token:string,preview:array<string,mixed>}
     */
    public function prepare(int $actorId, int $serverId): array
    {
        $this->access->handle($actorId);
        $this->assertEnabled();
        $preview = $this->verifiedPreview($actorId, $serverId);
        $token = bin2hex(random_bytes(12));

        Cache::lock('arianet:ipv4:'.$serverId, 90)->block(2, function () use ($actorId, $serverId, $preview, $token): void {
            DB::transaction(function () use ($actorId, $serverId, $preview, $token): void {
                $active = DB::table(self::TABLE)->where('active_server_id', $serverId)->lockForUpdate()->first();
                if ($active !== null) {
                    if ($active->status !== 'prepared' || now()->lessThanOrEqualTo($active->expires_at)) {
                        throw new RuntimeException('An IPv4 operation already exists; inspect its status.');
                    }
                    DB::table(self::TABLE)->where('id', $active->id)->update([
                        'status' => 'expired', 'active_server_id' => null, 'updated_at' => now(),
                    ]);
                }

                DB::table(self::TABLE)->insert([
                    'cloud_server_id' => $serverId,
                    'active_server_id' => $serverId,
                    'actor_user_id' => $actorId,
                    'token_hash' => hash('sha256', $token),
                    'old_ipv4' => $preview['ipv4'],
                    'fee_rials' => self::fee($preview),
                    'free_left' => $preview['freeLeft'],
                    'snapshot_hash' => self::fingerprint($preview),
                    'status' => 'prepared',
                    'expires_at' => now()->addMinutes(10),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        });

        return ['token' => $token, 'preview' => $preview];
    }

    /**
     * Consume one trusted preview. Store "reserved" durably BEFORE the only
     * external POST; timeout/5xx/invalid receipt becomes uncertain forever,
     * until separate manual reconciliation. Never automatically retry.
     *
     * @return array{status:string,actionId:int|null}
     */
    public function apply(int $actorId, int $serverId, string $token): array
    {
        $this->access->handle($actorId);
        $this->assertEnabled();
        if (! preg_match('/\A[a-f0-9]{24}\z/D', $token)) {
            throw new RuntimeException('Invalid IPv4 confirmation.');
        }

        return Cache::lock('arianet:ipv4:'.$serverId, 90)->block(2, function () use ($actorId, $serverId, $token): array {
            $row = DB::table(self::TABLE)->where('cloud_server_id', $serverId)
                ->where('actor_user_id', $actorId)
                ->where('token_hash', hash('sha256', $token))
                ->where('status', 'prepared')->first();

            if ($row === null) {
                throw new RuntimeException('This IPv4 confirmation has expired or already been used.');
            }

            if (now()->greaterThan($row->expires_at)) {
                DB::table(self::TABLE)->where('id', $row->id)->where('status', 'prepared')->update([
                    'status' => 'expired', 'active_server_id' => null, 'updated_at' => now(),
                ]);
                throw new RuntimeException('IPv4 confirmation expired; start again.');
            }

            // Re-read the complete remote view before reserving the write.
            // Any new fee, quota, IP, Node mapping or DNS address invalidates it.
            $fresh = $this->verifiedPreview($actorId, $serverId);
            if ($row->old_ipv4 !== $fresh['ipv4'] || $row->snapshot_hash !== self::fingerprint($fresh)
                || (int) $row->fee_rials !== self::fee($fresh)) {
                DB::table(self::TABLE)->where('id', $row->id)->where('status', 'prepared')->update([
                    'status' => 'expired', 'active_server_id' => null, 'updated_at' => now(),
                ]);
                throw new RuntimeException('Cloud or related records changed; review a new preview.');
            }

            $this->access->handle($actorId);
            $reserved = DB::table(self::TABLE)->where('id', $row->id)
                ->where('status', 'prepared')->where('active_server_id', $serverId)
                ->update(['status' => 'reserved', 'confirmed_at' => now(), 'updated_at' => now()]);
            if ($reserved !== 1) {
                throw new RuntimeException('IPv4 confirmation was already consumed.');
            }

            try {
                $receipt = $this->write->changeIpv4($serverId, (int) $row->free_left === 0);
                $this->updateStatus((int) $row->id, 'accepted', $receipt['action_id']);

                return ['status' => 'accepted', 'actionId' => $receipt['action_id']];
            } catch (ArianetCloudWriteUncertain) {
                $this->updateStatus((int) $row->id, 'uncertain');

                return ['status' => 'uncertain', 'actionId' => null];
            } catch (Throwable) {
                // Fail closed after reservation. Even a definite rejection is
                // kept for operator review; no automated attempt is allowed.
                $this->updateStatus((int) $row->id, 'needs_review');

                return ['status' => 'needs_review', 'actionId' => null];
            }
        });
    }

    /**
     * A deliberate cancellation may release only a never-dispatched preview.
     * Any reserved/accepted/uncertain operation remains blocked for review.
     */
    public function cancel(int $actorId, int $serverId, string $token): void
    {
        $this->access->handle($actorId);
        if (! preg_match('/\A[a-f0-9]{24}\z/D', $token) || $serverId < 1) {
            throw new RuntimeException('Invalid IPv4 confirmation.');
        }

        Cache::lock('arianet:ipv4:'.$serverId, 90)->block(2, function () use ($actorId, $serverId, $token): void {
            $updated = DB::table(self::TABLE)->where('cloud_server_id', $serverId)
                ->where('active_server_id', $serverId)
                ->where('actor_user_id', $actorId)
                ->where('token_hash', hash('sha256', $token))
                ->where('status', 'prepared')->update([
                    'status' => 'cancelled', 'active_server_id' => null, 'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('The confirmation was already consumed or cannot be cancelled.');
            }
        });
    }

    /** @return array<string,mixed>|null */
    public function status(int $actorId, int $serverId): ?array
    {
        $this->access->handle($actorId);
        if ($serverId < 1) {
            throw new RuntimeException('Invalid Cloud server ID.');
        }

        $row = DB::table(self::TABLE)->where('cloud_server_id', $serverId)->latest('id')->first();
        if ($row === null) {
            return null;
        }

        return self::safeStatus($row);
    }

    /**
     * GET-only, operator-triggered reconciliation of the exact action receipt.
     * A successful Cloud operation is marked awaiting_coordination, never a
     * client-connectivity success, and never releases the active server slot.
     *
     * @return array<string,mixed>
     */
    public function reconcile(int $actorId, int $serverId): array
    {
        $this->access->handle($actorId);
        $row = DB::table(self::TABLE)->where('active_server_id', $serverId)->first();
        if ($row === null || $row->status !== 'accepted' || ! is_int($row->action_id)
            || $row->action_id < 1) {
            throw new RuntimeException('No accepted Cloud action is available for automatic status refresh.');
        }

        return Cache::lock('arianet:ipv4:'.$serverId, 90)->block(2, function () use ($actorId, $serverId, $row): array {
            $this->access->handle($actorId);
            $freshRow = DB::table(self::TABLE)->where('id', $row->id)->first();
            if ($freshRow === null || $freshRow->status !== 'accepted') {
                throw new RuntimeException('IPv4 operation status changed; reload it.');
            }

            $envelope = $this->read->action((int) $freshRow->action_id);
            $action = $envelope['data'] ?? null;
            $resource = is_array($action) ? ($action['resource'] ?? null) : null;
            if (! is_array($action) || array_is_list($action)
                || ($action['id'] ?? null) !== (int) $freshRow->action_id
                || ($action['command'] ?? null) !== 'change_ipv4'
                || ! is_array($resource) || ($resource['id'] ?? null) !== $serverId) {
                throw new RuntimeException('Cloud action identity is unverified; manual review required.');
            }

            if (($action['status'] ?? null) === 'success') {
                $new = $this->read->server($serverId)['data'] ?? null;
                $newIp = is_array($new) && ($new['id'] ?? null) === $serverId
                    ? ($new['ipv4'] ?? null) : null;
                if (! is_string($newIp) || filter_var($newIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                    || $newIp === $freshRow->old_ipv4) {
                    throw new RuntimeException('New IPv4 is not independently verified.');
                }

                DB::table(self::TABLE)->where('id', $freshRow->id)->where('status', 'accepted')->update([
                    'status' => 'awaiting_coordination', 'new_ipv4' => $newIp, 'updated_at' => now(),
                ]);
            } elseif (($action['status'] ?? null) === 'error') {
                $this->updateStatus((int) $freshRow->id, 'needs_review');
            } elseif (! in_array($action['status'] ?? null, ['running', 'pending', 'queued'], true)) {
                throw new RuntimeException('Cloud action state is unknown; manual review required.');
            }

            return $this->status($actorId, $serverId) ?? throw new RuntimeException('Cloud operation disappeared.');
        });
    }

    /** @return array<string,mixed> */
    private function verifiedPreview(int $actorId, int $serverId): array
    {
        $preview = $this->previews->handle($actorId, $serverId);
        $ip = $preview['ipv4'];
        if ($preview['status'] !== 'running' || ! is_string($ip)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || $preview['changeAllowed'] !== true || $preview['pending'] !== false
            || ! is_int($preview['freeLeft']) || $preview['freeLeft'] < 0
            || ($preview['freeLeft'] === 0 && (! is_int($preview['feeRials']) || $preview['feeRials'] < 1))
            || ! is_string($preview['nodeUuid'])
            || ! in_array($preview['nodeState'], ['ip_match', 'hostname'], true)
            || ($preview['nodeState'] === 'hostname' && ($preview['dns']['state'] !== 'match'
                || $preview['dns']['proxied'] !== false))) {
            throw new RuntimeException('Cloud/Node/DNS or price readiness could not be verified.');
        }

        return $preview;
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Cloud IPv4 writes are disabled in this environment.');
        }
    }

    /** @param array<string,mixed> $preview */
    private static function fee(array $preview): int
    {
        return $preview['freeLeft'] > 0 ? 0 : $preview['feeRials'];
    }

    /** @param array<string,mixed> $preview */
    private static function fingerprint(array $preview): string
    {
        $snapshot = [
            $preview['serverId'], $preview['ipv4'], $preview['status'],
            $preview['changeAllowed'], $preview['pending'], $preview['freeLeft'],
            $preview['changeUsed'], $preview['feeRials'], $preview['nodeUuid'],
            $preview['node']['address'] ?? null, $preview['nodeState'], $preview['dns'],
        ];

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function updateStatus(int $id, string $status, ?int $actionId = null): void
    {
        DB::table(self::TABLE)->where('id', $id)->whereIn('status', ['reserved', 'accepted'])->update([
            'status' => $status, 'action_id' => $actionId, 'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private static function safeStatus(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'serverId' => (int) $row->cloud_server_id,
            'status' => $row->status,
            'oldIpv4' => $row->old_ipv4,
            'newIpv4' => $row->new_ipv4,
            'feeRials' => (int) $row->fee_rials,
            'actionId' => $row->action_id === null ? null : (int) $row->action_id,
        ];
    }
}
