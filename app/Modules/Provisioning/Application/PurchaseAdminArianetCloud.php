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
 * ARI-04B: exactly one explicit Cloud create per durable paid confirmation.
 * An uncertain response NEVER authorizes an automatic retry or another order.
 */
final readonly class PurchaseAdminArianetCloud
{
    private const TABLE = 'arianet_cloud_purchases';

    private const LOCK = 'arianet:cloud:purchase';

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private PreviewAdminArianetPurchase $preview,
        private ArianetCloudReadClient $read,
        private ArianetCloudWriteClient $write,
    ) {}

    public function enabled(): bool
    {
        return config('services.arianet_cloud.purchase_enabled') === true
            && filled(config('services.arianet_cloud.read_token'));
    }

    /** @return array{id:int,token:string,quote:array<string,mixed>} */
    public function prepare(int $actorId, array $selection): array
    {
        $this->access->handle($actorId);
        $this->assertEnabled();
        $quote = $this->verifiedQuote($actorId, $selection);
        $token = bin2hex(random_bytes(16));

        $id = Cache::lock(self::LOCK, 90)->block(3, function () use ($actorId, $quote, $token): int {
            return DB::transaction(function () use ($actorId, $quote, $token): int {
                $active = DB::table(self::TABLE)->where('active_slot', 1)->lockForUpdate()->first();
                if ($active !== null) {
                    if ($active->status !== 'prepared' || now()->lessThanOrEqualTo($active->expires_at)) {
                        throw new RuntimeException('An existing Cloud purchase requires review before another order.');
                    }
                    DB::table(self::TABLE)->where('id', $active->id)->where('status', 'prepared')->update([
                        'status' => 'expired', 'active_slot' => null, 'updated_at' => now(),
                    ]);
                }

                return (int) DB::table(self::TABLE)->insertGetId([
                    'active_slot' => 1,
                    'actor_user_id' => $actorId,
                    'token_hash' => hash('sha256', $token),
                    'name' => $quote['name'],
                    'datacenter' => $quote['plan']['datacenter'],
                    'plan_id' => $quote['plan']['id'],
                    'image' => $quote['image'],
                    'ssh_key_id' => $quote['sshKeyId'],
                    'quote_hash' => self::fingerprint($quote),
                    'hourly_rials' => $quote['plan']['hourlyRials'],
                    'monthly_rials' => $quote['plan']['monthlyRials'],
                    'minimum_rials' => $quote['minimumRials'],
                    'status' => 'prepared',
                    'expires_at' => now()->addMinutes(10),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        });

        return ['id' => $id, 'token' => $token, 'quote' => $quote];
    }

    /** A one-use reservation is committed BEFORE an irreversible paid POST.
     * @return array<string,mixed>
     */
    public function confirm(int $actorId, int $id, string $token): array
    {
        $this->access->handle($actorId);
        $this->assertEnabled();
        if ($id < 1 || preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            throw new RuntimeException('Invalid Cloud purchase confirmation.');
        }

        return Cache::lock(self::LOCK, 90)->block(3, function () use ($actorId, $id, $token): array {
            $row = DB::table(self::TABLE)->where('id', $id)
                ->where('actor_user_id', $actorId)->where('status', 'prepared')
                ->where('token_hash', hash('sha256', $token))->first();
            if ($row === null) {
                throw new RuntimeException('Confirmation expired or already used. Check purchase status.');
            }
            if (now()->greaterThan($row->expires_at)) {
                $this->update($id, 'expired', ['active_slot' => null]);

                throw new RuntimeException('The purchase quote expired. Review a new quote.');
            }

            // Check price, datacenter availability, image and public SSH key
            // immediately before accepting any financial exposure.
            $fresh = $this->verifiedQuote($actorId, self::selection($row));
            if ($row->quote_hash !== self::fingerprint($fresh)
                || (int) $row->hourly_rials !== $fresh['plan']['hourlyRials']
                || (int) $row->monthly_rials !== $fresh['plan']['monthlyRials']
                || (int) $row->minimum_rials !== $fresh['minimumRials']) {
                $this->update($id, 'expired', ['active_slot' => null]);

                throw new RuntimeException('Plan, price or SSH key changed; new confirmation required.');
            }

            $this->access->handle($actorId);
            $this->assertEnabled();
            if (DB::table(self::TABLE)->where('id', $id)->where('status', 'prepared')
                ->where('active_slot', 1)
                ->update(['status' => 'reserved', 'confirmed_at' => now(), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Confirmation already consumed.');
            }

            try {
                $receipt = $this->write->createServer(
                    $row->name, $row->datacenter, (int) $row->plan_id,
                    $row->image, (int) $row->ssh_key_id,
                );
                $this->update($id, 'accepted', [
                    'server_id' => $receipt['server_id'], 'action_id' => $receipt['action_id'],
                ]);
            } catch (ArianetCloudWriteUncertain) {
                $this->update($id, 'uncertain');
            } catch (Throwable) {
                // An exception after the reservation must NEVER be treated as
                // permission for a second paid POST, even if it looks rejected.
                $this->update($id, 'needs_review');
            }

            return $this->status($actorId, $id);
        });
    }

    public function cancel(int $actorId, int $id, string $token): void
    {
        $this->access->handle($actorId);
        if ($id < 1 || preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            throw new RuntimeException('Invalid cancellation.');
        }
        Cache::lock(self::LOCK, 90)->block(3, function () use ($actorId, $id, $token): void {
            if (DB::table(self::TABLE)->where('id', $id)->where('actor_user_id', $actorId)
                ->where('status', 'prepared')->where('token_hash', hash('sha256', $token))
                ->update(['status' => 'cancelled', 'active_slot' => null, 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Only an unconfirmed purchase may be cancelled.');
            }
        });
    }

    /** @return array<string,mixed> */
    public function status(int $actorId, int $id): array
    {
        $this->access->handle($actorId);
        $row = $this->row($actorId, $id);

        return [
            'id' => $id,
            'name' => $row->name,
            'datacenter' => $row->datacenter,
            'planId' => (int) $row->plan_id,
            'image' => $row->image,
            'sshKeyId' => (int) $row->ssh_key_id,
            'hourlyRials' => (int) $row->hourly_rials,
            'monthlyRials' => (int) $row->monthly_rials,
            'minimumRials' => (int) $row->minimum_rials,
            'status' => $row->status,
            'serverId' => $row->server_id === null ? null : (int) $row->server_id,
            'actionId' => $row->action_id === null ? null : (int) $row->action_id,
        ];
    }

    /** Return the most recent actor-owned purchase without exposing a token. */
    public function recent(int $actorId): ?int
    {
        $this->access->handle($actorId);
        $id = DB::table(self::TABLE)->where('actor_user_id', $actorId)->latest('id')->value('id');

        return is_int($id) ? $id : null;
    }

    /** GET-only reconciliation. An unknown/ambiguous purchase never causes another POST.
     * @return array<string,mixed>
     */
    public function reconcile(int $actorId, int $id): array
    {
        $this->access->handle($actorId);

        return Cache::lock(self::LOCK, 90)->block(3, function () use ($actorId, $id): array {
            $row = $this->row($actorId, $id);
            if ($row->status === 'accepted' && $row->action_id !== null && $row->server_id !== null) {
                try {
                    $action = $this->read->action((int) $row->action_id)['data'] ?? null;
                    if (! is_array($action) || ($action['id'] ?? null) !== (int) $row->action_id
                        || ($action['command'] ?? null) !== 'create'
                        || ($action['resource']['id'] ?? null) !== (int) $row->server_id) {
                        throw new RuntimeException('Create action identity is unverified.');
                    }
                    if (($action['status'] ?? null) === 'success') {
                        $server = $this->read->server((int) $row->server_id)['data'] ?? null;
                        if (! is_array($server) || ($server['id'] ?? null) !== (int) $row->server_id
                            || ($server['name'] ?? null) !== $row->name) {
                            throw new RuntimeException('Created Cloud server identity is unverified.');
                        }
                        $this->update($id, 'completed', ['active_slot' => null]);
                    } elseif (($action['status'] ?? null) === 'error') {
                        $this->update($id, 'needs_review');
                    } elseif (! in_array($action['status'] ?? null, ['pending', 'queued', 'running'], true)) {
                        throw new RuntimeException('Cloud create action has an unknown state.');
                    }
                } catch (Throwable) {
                    // Preserve accepted operation for later GET-only operator review.
                }
            } elseif (in_array($row->status, ['reserved', 'uncertain', 'needs_review'], true)) {
                // Gather bounded evidence, but do not infer a definitive matching
                // purchase from a reused name or an incomplete action listing.
                try {
                    $this->read->actions(1, 25);
                    $this->read->servers(1, 25);
                } catch (Throwable) {
                    // No status upgrade without independently verified IDs.
                }
            }

            return $this->status($actorId, $id);
        });
    }

    /**
     * ARI-04C: fresh, actor-owned creation evidence, independent of the
     * purchase write switch. A successful create is not an SSH readiness test.
     *
     * @return array{purchaseId:int,serverId:int,name:string,ipv4:string,image:string,sshKeyId:int}
     */
    public function readiness(int $actorId, int $id): array
    {
        $this->access->handle($actorId);
        $row = $this->row($actorId, $id);
        if ($row->status !== 'completed' || $row->server_id === null || $row->action_id === null) {
            throw new RuntimeException('Cloud creation is not verified.');
        }

        $serverId = (int) $row->server_id;
        $actionId = (int) $row->action_id;
        if ($serverId < 1 || $actionId < 1) {
            throw new RuntimeException('Invalid Cloud creation identifiers.');
        }
        ManageAdminArianetOperations::assertNoActive($serverId);
        $expectedPlan = ManageAdminArianetOperations::verifiedPlan($serverId, (int) $row->plan_id);
        $action = $this->read->action($actionId)['data'] ?? null;
        if (! is_array($action) || ($action['id'] ?? null) !== $actionId
            || ($action['command'] ?? null) !== 'create' || ($action['status'] ?? null) !== 'success'
            || ($action['resource']['id'] ?? null) !== $serverId
            || ($action['resource']['type'] ?? null) !== 'server') {
            throw new RuntimeException('Cloud creation action is not verified.');
        }

        $server = $this->read->server($serverId)['data'] ?? null;
        $ip = is_array($server) ? ($server['ipv4'] ?? null) : null;
        if (! is_array($server) || ($server['id'] ?? null) !== $serverId
            || ($server['name'] ?? null) !== $row->name
            || ($server['datacenter'] ?? null) !== $row->datacenter
            || ($server['plan_id'] ?? null) !== $expectedPlan
            || ($server['status'] ?? null) !== 'running'
            || ($server['ip_change']['pending'] ?? false) !== false
            || ! is_string($ip)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) !== $ip) {
            throw new RuntimeException('Cloud server identity, running state or public IPv4 is not verified.');
        }

        return [
            'purchaseId' => $id,
            'serverId' => $serverId,
            'name' => $row->name,
            'ipv4' => $ip,
            'image' => $row->image,
            'sshKeyId' => (int) $row->ssh_key_id,
        ];
    }

    /** A handed-off purchase must still identify the same public VPS. */
    public function assertReadyTarget(int $actorId, int $id, string $ip): void
    {
        if ($this->readiness($actorId, $id)['ipv4'] !== $ip) {
            throw new RuntimeException('Cloud server IPv4 changed since handoff.');
        }
    }

    /** @param array<string,mixed> $selection
     * @return array<string,mixed>
     */
    private function verifiedQuote(int $actorId, array $selection): array
    {
        if (! is_string($selection['datacenter'] ?? null)
            || ! is_int($selection['planId'] ?? null)
            || ! is_string($selection['image'] ?? null)
            || ! is_string($selection['name'] ?? null)
            || ! is_int($selection['keyId'] ?? null)) {
            throw new RuntimeException('Invalid Cloud purchase selection.');
        }

        return $this->preview->quote($actorId, $selection['datacenter'], $selection['planId'],
            $selection['image'], $selection['name'], $selection['keyId']);
    }

    /** @return array<string,mixed> */
    private static function selection(object $row): array
    {
        return [
            'name' => $row->name,
            'datacenter' => $row->datacenter,
            'planId' => (int) $row->plan_id,
            'image' => $row->image,
            'keyId' => (int) $row->ssh_key_id,
        ];
    }

    /** @param array<string,mixed> $quote */
    private static function fingerprint(array $quote): string
    {
        return hash('sha256', json_encode([
            $quote['name'], $quote['image'], $quote['sshKeyId'], $quote['sshKeyName'],
            $quote['sshPublicKeyHash'],
            $quote['plan']['id'], $quote['plan']['datacenter'], $quote['plan']['name'],
            $quote['plan']['cores'], $quote['plan']['memoryMb'], $quote['plan']['diskGb'],
            $quote['plan']['trafficGb'], $quote['plan']['minimumHours'],
            $quote['plan']['hourlyRials'], $quote['plan']['monthlyRials'],
            $quote['minimumRials'],
        ], JSON_THROW_ON_ERROR));
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Arianet Cloud purchases are disabled.');
        }
    }

    private function row(int $actorId, int $id): object
    {
        if ($id < 1) {
            throw new RuntimeException('Invalid purchase identifier.');
        }
        $row = DB::table(self::TABLE)->where('id', $id)->where('actor_user_id', $actorId)->first();
        if ($row === null) {
            throw new RuntimeException('Cloud purchase not found or not authorized.');
        }

        return $row;
    }

    /** @param array<string,mixed> $changes */
    private function update(int $id, string $status, array $changes = []): void
    {
        DB::table(self::TABLE)->where('id', $id)->whereIn('status', ['prepared', 'reserved', 'accepted'])
            ->update(['status' => $status, ...$changes, 'updated_at' => now()]);
    }
}
