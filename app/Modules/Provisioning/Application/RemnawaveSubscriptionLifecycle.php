<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Infrastructure\Remnawave\CreateRemnawaveUser;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUsersApi;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUserSnapshot;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Durable, at-most-one-write-attempt, INTERNAL subscription lifecycle.
 *
 * Internal only: callers prove an immutable paid settlement OR an admitted
 * one-time free trial through their own authorized Application Action/worker.
 * Never call CREATE directly from Telegram/HTTP. Preserve the server write gate.
 *
 * No remote calls inside a DB transaction; reserve first, then execute once.
 * After uncertain outcomes, block retries until manual reconciliation.
 */
final class RemnawaveSubscriptionLifecycle
{
    public function __construct(private readonly RemnawaveApiClientFactory $factory) {}

    /** @param list<string> $internalSquadUuids */
    public function create(
        string $reference,
        string $idempotencyKey,
        string $expireAt,
        int $trafficLimitBytes,
        array $internalSquadUuids,
    ): int {
        $this->assertEnabled();
        $this->assertKeys($reference, $idempotencyKey);
        $data = new CreateRemnawaveUser(
            username: self::username($reference),
            expireAt: $expireAt,
            trafficLimitBytes: $trafficLimitBytes,
            internalSquadUuids: $internalSquadUuids,
            description: self::marker($reference),
        );

        $reservation = $this->reserve($reference, $idempotencyKey, 'create', $data->body(), true);

        if ($reservation['alreadyComplete']) {
            return $reservation['remoteId'];
        }

        try {
            $remote = $this->api()->create($data);
            $this->assertIdentity($remote, $reference);
            $this->assertExpected($remote, [
                'expireAt' => $expireAt,
                'trafficLimitBytes' => $trafficLimitBytes,
                'status' => 'ACTIVE',
                'activeInternalSquads' => $internalSquadUuids,
            ]);

            return $this->complete($reference, $idempotencyKey, $remote);
        } catch (Throwable $e) {
            $this->ambiguous($reference, $idempotencyKey);

            throw new RuntimeException('Remnawave create outcome is uncertain; reconciliation required.', 0, $e);
        }
    }

    /**
     * One separately gated, at-most-once manual recovery attempt for a failed
     * paid-order CREATE. The initial create operation and evidence are retained.
     * Two full read-only absence checks are mandatory. Not a general retry API.
     *
     * @param list<string> $internalSquadUuids
     */
    public function recoverCreate(
        string $reference,
        string $idempotencyKey,
        string $expireAt,
        int $trafficLimitBytes,
        array $internalSquadUuids,
        int $actorId,
    ): int {
        $this->assertEnabled();
        if (config('services.remnawave.recovery_create_enabled', false) !== true) {
            throw new RuntimeException('Manual CREATE recovery is disabled.');
        }
        $this->assertKeys($reference, $idempotencyKey);
        if (! preg_match('/\Asales-order:[1-9][0-9]*\z/D', $reference)
            || $idempotencyKey !== $reference.':recovery:create:1'
            || $actorId < 1) {
            throw new InvalidArgumentException('Invalid scoped recovery operation.');
        }

        $data = new CreateRemnawaveUser(
            username: self::username($reference),
            expireAt: $expireAt,
            trafficLimitBytes: $trafficLimitBytes,
            internalSquadUuids: $internalSquadUuids,
            description: self::marker($reference),
        );

        // A 404 by username alone is not proof: independently enumerate all
        // RW users and reject incomplete inventories or ownership conflicts.
        $this->assertAbsentInProvider($reference);
        $this->reserve($reference, $idempotencyKey, 'create', $data->body(), true, $actorId, 'support', null, true);

        try {
            // Recheck after durable reservation to narrow the race window.
            // A failed preflight consumes this one recovery opportunity safely.
            $this->assertAbsentInProvider($reference);

            $remote = $this->api()->create($data);
            $this->assertIdentity($remote, $reference);
            $this->assertExpected($remote, [
                'expireAt' => $expireAt,
                'trafficLimitBytes' => $trafficLimitBytes,
                'status' => 'ACTIVE',
                'activeInternalSquads' => $internalSquadUuids,
            ]);

            return $this->complete($reference, $idempotencyKey, $remote);
        } catch (Throwable $e) {
            $this->ambiguous($reference, $idempotencyKey);

            throw new RuntimeException('Recovery CREATE outcome requires read-only reconciliation; never replay.', 0, $e);
        }
    }

    /** Full inventory preflight: no leaked credentials, no remote mutation. */
    private function assertAbsentInProvider(string $reference): void
    {
        $api = $this->api();
        try {
            $api->byUsername(self::username($reference));
            throw new RuntimeException('Provider already contains the requested username.');
        } catch (RequestException $exception) {
            if ($exception->response->status() !== 404) {
                throw $exception;
            }
        }

        $offset = 0;
        $total = null;
        do {
            $batch = $api->list($offset, 200);
            if ($total === null) {
                $total = $batch['total'];
                if ($total > 2000) {
                    throw new RuntimeException('Provider user inventory is too large for bounded recovery preflight.');
                }
            }
            if ($batch['total'] !== $total) {
                throw new RuntimeException('Provider inventory changed during recovery preflight.');
            }
            foreach ($batch['users'] as $user) {
                if ($user->username === self::username($reference)
                    || $user->description === self::marker($reference)) {
                    throw new RuntimeException('Provider already contains the owned subscription identity.');
                }
            }
            $count = count($batch['users']);
            if ($count === 0 && $offset < $total) {
                throw new RuntimeException('Incomplete provider inventory; recovery denied.');
            }
            $offset += $count;
        } while ($offset < $total);
    }

    /** Absolute (not additive) expiration makes replay detection possible. */
    public function setExpiration(string $reference, string $idempotencyKey, string $expireAt): int
    {
        CreateRemnawaveUser::validateExpiry($expireAt);

        return $this->mutate($reference, $idempotencyKey, 'expiration', ['expireAt' => $expireAt]);
    }

    /** Absolute bytes; never auto-credit relative to existing quota. */
    public function setTrafficLimit(string $reference, string $idempotencyKey, int $bytes): int
    {
        CreateRemnawaveUser::validateLimit($bytes);

        return $this->mutate($reference, $idempotencyKey, 'quota', ['trafficLimitBytes' => $bytes]);
    }

    /** Renew quota/expiry without overwriting an administrative remote disable. */
    public function renew(string $reference, string $idempotencyKey, string $expireAt, int $totalLimitBytes): int
    {
        CreateRemnawaveUser::validateExpiry($expireAt);
        CreateRemnawaveUser::validateLimit($totalLimitBytes);

        return $this->mutate($reference, $idempotencyKey, 'renewal', [
            'expireAt' => $expireAt,
            'trafficLimitBytes' => $totalLimitBytes,
        ], [
            // Activation is a verified outcome of the provider's quota/expiry
            // rules, never a requested status change. Preserve the stored tuple.
            'status' => 'ACTIVE',
        ]);
    }

    public function disable(string $reference, string $idempotencyKey, ?int $actorId = null, ?string $reasonCode = null, ?string $preflightStatus = null): int
    {
        return $this->mutate($reference, $idempotencyKey, 'disable', ['status' => 'DISABLED'], [], $actorId, $reasonCode, $preflightStatus);
    }

    public function enable(string $reference, string $idempotencyKey, ?int $actorId = null, ?string $reasonCode = null, ?string $preflightStatus = null): int
    {
        return $this->mutate($reference, $idempotencyKey, 'enable', ['status' => 'ACTIVE'], [], $actorId, $reasonCode, $preflightStatus);
    }

    /**
     * Read-only lookup from an owned local reference.
     * The URL is a credential: callers MUST authorize before sending it.
     */
    public function subscription(string $reference): RemnawaveUserSnapshot
    {
        self::assertReference($reference);

        $local = DB::table('provisioning_subscriptions')->where('reference', $reference)->first();

        if ($local === null || $local->state !== 'active' || ! is_numeric($local->remote_user_id)) {
            throw new RuntimeException('Subscription is not active or linked.');
        }

        $remote = $this->api()->byId((int) $local->remote_user_id);
        $this->assertIdentity($remote, $reference);

        return $remote;
    }

    /**
     * Read-only reconciliation after an uncertain CREATE. Never reissues POST.
     * Requires the remote username AND ownership marker to match.
     */
    public function reconcileCreate(string $reference, string $idempotencyKey): int
    {
        self::assertReference($reference);
        self::assertKey($idempotencyKey);

        $row = DB::table('provisioning_subscriptions')->where('reference', $reference)->first();
        $op = DB::table('provisioning_operations')->where('idempotency_key', $idempotencyKey)->first();

        if ($row === null || $op === null || (int) $op->subscription_id !== (int) $row->id
            || $op->kind !== 'create'
            || ! in_array($row->state, ['reserved', 'needs_review'], true)
            || ! in_array($op->state, ['reserved', 'needs_review'], true)
            || $row->last_operation_key !== $idempotencyKey) {
            throw new RuntimeException('No uncertain create to reconcile.');
        }

        $expected = json_decode((string) $op->expected_payload, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($expected)) {
            throw new RuntimeException('Invalid persisted provisioning operation.');
        }

        $remote = $this->api()->byUsername(self::username($reference));
        $this->assertIdentity($remote, $reference);
        $this->assertExpected($remote, [
            'trafficLimitBytes' => $expected['trafficLimitBytes'] ?? null,
            'expireAt' => $expected['expireAt'] ?? null,
            'status' => $expected['status'] ?? null,
            'activeInternalSquads' => $expected['activeInternalSquads'] ?? null,
        ]);

        return $this->complete($reference, $idempotencyKey, $remote);
    }

    /**
     * Read-only reconciliation of an uncertain absolute update.
     * Does not perform a second write. A mismatched state stays blocked.
     */
    public function reconcileUpdate(string $reference, string $idempotencyKey): int
    {
        self::assertReference($reference);
        self::assertKey($idempotencyKey);

        $row = DB::table('provisioning_subscriptions')->where('reference', $reference)->first();
        $op = DB::table('provisioning_operations')->where('idempotency_key', $idempotencyKey)->first();

        if ($row === null || $op === null || (int) $op->subscription_id !== (int) $row->id
            || ! in_array($row->state, ['reserved', 'needs_review'], true)
            || ! in_array($op->state, ['reserved', 'needs_review'], true)
            || $row->last_operation_key !== $idempotencyKey
            || ! in_array($op->kind, ['expiration', 'quota', 'disable', 'enable', 'renewal'], true)
            || ! is_numeric($row->remote_user_id)) {
            throw new RuntimeException('No uncertain absolute update to reconcile.');
        }

        $expected = json_decode((string) $op->expected_payload, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($expected)) {
            throw new RuntimeException('Invalid persisted provisioning operation.');
        }

        $remote = $this->api()->byId((int) $row->remote_user_id);
        $this->assertIdentity($remote, $reference);
        $this->assertExpected($remote, $expected);

        return $this->complete($reference, $idempotencyKey, $remote);
    }

    /** @param array<string, mixed> $expected */
    private function assertExpected(RemnawaveUserSnapshot $remote, array $expected): void
    {
        foreach ($expected as $key => $value) {
            $matches = match ($key) {
                'trafficLimitBytes' => $remote->trafficLimitBytes === $value,
                'activeInternalSquads' => is_array($value) && $this->sameSquads($remote->activeInternalSquadUuids, $value),
                'status' => $remote->status === $value,
                'expireAt' => is_string($value) && $this->sameInstant($remote->expireAt, $value),
                default => false,
            };

            if (! $matches) {
                throw new RuntimeException('Remote provisioning result differs; manual operator review is required.');
            }
        }
    }

    /**
     * @param  list<string>  $actual
     * @param  array<mixed>  $expected
     */
    private function sameSquads(array $actual, array $expected): bool
    {
        if (! array_is_list($expected)) {
            return false;
        }

        foreach ($expected as $uuid) {
            if (! is_string($uuid)) {
                return false;
            }
        }

        $expected = array_map('strtolower', $expected);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private function sameInstant(string $actual, string $expected): bool
    {
        try {
            return (new \DateTimeImmutable($actual))->getTimestamp() === (new \DateTimeImmutable($expected))->getTimestamp();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $postconditions
     */
    private function mutate(
        string $reference,
        string $key,
        string $kind,
        array $fields,
        array $postconditions = [],
        ?int $actorId = null,
        ?string $reasonCode = null,
        ?string $preflightStatus = null,
    ): int {
        $this->assertEnabled();
        $this->assertKeys($reference, $key);

        if (($actorId !== null && $actorId < 1)
            || ($actorId === null && ($reasonCode !== null || $preflightStatus !== null))
            || ($reasonCode !== null && ! in_array($reasonCode, ['customer', 'support', 'other'], true))
            || ($preflightStatus !== null && ! in_array($preflightStatus, ['ACTIVE', 'DISABLED'], true))) {
            throw new InvalidArgumentException('Invalid administrative operation metadata.');
        }

        $expected = array_merge($fields, $postconditions);
        $reserved = $this->reserve($reference, $key, $kind, $expected, false, $actorId, $reasonCode, $preflightStatus);

        if ($reserved['alreadyComplete']) {
            return $reserved['remoteId'];
        }

        try {
            $remote = $this->api()->update($reserved['remoteId'], $fields);
            $this->assertIdentity($remote, $reference);
            $this->assertExpected($remote, $expected);

            return $this->complete($reference, $key, $remote);
        } catch (Throwable $e) {
            $this->ambiguous($reference, $key);

            throw new RuntimeException('Remnawave update outcome is uncertain; reconciliation required.', 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{alreadyComplete: bool, remoteId: int}
     */
    private function reserve(
        string $reference,
        string $key,
        string $kind,
        array $payload,
        bool $creating,
        ?int $actorId = null,
        ?string $reasonCode = null,
        ?string $preflightStatus = null,
        bool $recovery = false,
    ): array {
        $hash = hash('sha256', json_encode([$reference, $kind, $payload], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($reference, $key, $kind, $hash, $payload, $creating, $actorId, $reasonCode, $preflightStatus, $recovery): array {
            if ($recovery) {
                // Match the existing order → outbox → subscription lock order.
                // A concurrent reconciliation may complete between GET and
                // reservation; never POST for a now-fulfilled order.
                $orderId = (int) substr($reference, strlen('sales-order:'));
                $order = DB::table('sales_orders')->where('id', $orderId)->lockForUpdate()->first();
                $outbox = DB::table('sales_fulfillment_outbox')
                    ->where('sales_order_id', $orderId)->lockForUpdate()->first();
                if ($order === null || $order->status !== 'paid'
                    || $outbox === null || $outbox->state !== 'needs_review'
                    || $outbox->event_key !== $reference.':create'
                    || DB::table('provisioning_order_links')->where('sales_order_id', $orderId)->exists()) {
                    throw new RuntimeException('Paid-order recovery is no longer eligible.');
                }
            }
            $previous = DB::table('provisioning_operations')->where('idempotency_key', $key)->lockForUpdate()->first();
            $record = DB::table('provisioning_subscriptions')->where('reference', $reference)->lockForUpdate()->first();

            if ($previous !== null) {
                if ($record === null || (int) $previous->subscription_id !== (int) $record->id
                    || $previous->kind !== $kind || ! hash_equals((string) $previous->payload_hash, $hash)) {
                    throw new RuntimeException('Idempotency key is bound to a different operation.');
                }

                if ($actorId !== null
                    && ((int) $previous->actor_user_id !== $actorId
                        || $previous->reason_code !== $reasonCode
                        || $previous->preflight_status !== $preflightStatus)) {
                    throw new RuntimeException('Idempotency key belongs to a different administrative request.');
                }

                if ($previous->state !== 'completed' || $record->state !== 'active'
                    || ! is_numeric($record->remote_user_id)) {
                    throw new RuntimeException('Provisioning operation requires reconciliation; no automatic replay.');
                }

                return ['alreadyComplete' => true, 'remoteId' => (int) $record->remote_user_id];
            }

            if ($creating && $recovery) {
                $original = DB::table('provisioning_operations')
                    ->where('idempotency_key', $reference.':create')->lockForUpdate()->first();
                if ($record === null || $record->state !== 'needs_review'
                    || $record->remote_user_id !== null
                    || $record->last_operation_key !== $reference.':create'
                    || $original === null || $original->state !== 'needs_review'
                    || $original->kind !== 'create'
                    || (int) $original->subscription_id !== (int) $record->id) {
                    throw new RuntimeException('Original uncertain CREATE is not eligible for one-time recovery.');
                }
                $id = (int) $record->id;
                $remoteId = 0;
                DB::table('provisioning_subscriptions')->where('id', $id)->update([
                    'state' => 'reserved', 'last_operation_key' => $key, 'updated_at' => now(),
                ]);
            } elseif ($creating) {
                if ($record !== null) {
                    throw new RuntimeException('Subscription reference is already reserved.');
                }

                $id = DB::table('provisioning_subscriptions')->insertGetId([
                    'reference' => $reference,
                    'remote_username' => self::username($reference),
                    'remote_user_id' => null,
                    'state' => 'reserved',
                    'last_operation_key' => $key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $remoteId = 0;
            } else {
                if ($record === null || $record->state !== 'active' || ! is_numeric($record->remote_user_id)) {
                    throw new RuntimeException('Subscription is not active and linked.');
                }

                $id = (int) $record->id;
                $remoteId = (int) $record->remote_user_id;

                DB::table('provisioning_subscriptions')->where('id', $id)->update([
                    'state' => 'reserved',
                    'last_operation_key' => $key,
                    'updated_at' => now(),
                ]);
            }

            DB::table('provisioning_operations')->insert([
                'subscription_id' => $id,
                'idempotency_key' => $key,
                'kind' => $kind,
                'payload_hash' => $hash,
                'expected_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'state' => 'reserved',
                'actor_user_id' => $actorId,
                'reason_code' => $reasonCode,
                'preflight_status' => $preflightStatus,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['alreadyComplete' => false, 'remoteId' => $remoteId];
        });
    }

    private function complete(string $reference, string $key, RemnawaveUserSnapshot $remote): int
    {
        DB::transaction(function () use ($reference, $key, $remote): void {
            $row = DB::table('provisioning_subscriptions')->where('reference', $reference)->lockForUpdate()->first();
            $op = DB::table('provisioning_operations')->where('idempotency_key', $key)->lockForUpdate()->first();

            if ($row === null || $op === null || (int) $op->subscription_id !== (int) $row->id
                || $row->last_operation_key !== $key
                || ! in_array($row->state, ['reserved', 'needs_review'], true)
                || ! in_array($op->state, ['reserved', 'needs_review'], true)
                || ($row->remote_user_id !== null && (int) $row->remote_user_id !== $remote->id)) {
                throw new RuntimeException('Provisioning completion conflicts with current state.');
            }

            DB::table('provisioning_subscriptions')->where('id', $row->id)->update([
                'remote_user_id' => $remote->id,
                'state' => 'active',
                'updated_at' => now(),
            ]);
            DB::table('provisioning_operations')->where('id', $op->id)->update([
                'state' => 'completed',
                'updated_at' => now(),
            ]);
        });

        return $remote->id;
    }

    private function ambiguous(string $reference, string $key): void
    {
        DB::transaction(function () use ($reference, $key): void {
            $row = DB::table('provisioning_subscriptions')->where('reference', $reference)->lockForUpdate()->first();
            $op = DB::table('provisioning_operations')->where('idempotency_key', $key)->lockForUpdate()->first();

            if ($row !== null && $op !== null && $row->last_operation_key === $key
                && $row->state === 'reserved' && $op->state === 'reserved') {
                DB::table('provisioning_subscriptions')->where('id', $row->id)->update(['state' => 'needs_review', 'updated_at' => now()]);
                DB::table('provisioning_operations')->where('id', $op->id)->update(['state' => 'needs_review', 'updated_at' => now()]);
            }
        });
    }

    private function assertIdentity(RemnawaveUserSnapshot $remote, string $reference): void
    {
        if ($remote->username !== self::username($reference) || $remote->description !== self::marker($reference)) {
            throw new RuntimeException('Remote user is not owned by this SkyNetwork reference.');
        }
    }

    private function api(): RemnawaveUsersApi
    {
        return new RemnawaveUsersApi($this->factory->make());
    }

    private function assertEnabled(): void
    {
        if (config('services.remnawave.writes_enabled') !== true) {
            throw new RuntimeException('Remnawave provisioning writes are disabled.');
        }
    }

    private function assertKeys(string $reference, string $key): void
    {
        self::assertReference($reference);
        self::assertKey($key);
    }

    private static function assertReference(string $reference): void
    {
        if (! preg_match('/^[A-Za-z0-9:_-]{1,120}$/D', $reference)) {
            throw new InvalidArgumentException('Invalid subscription reference.');
        }
    }

    private static function assertKey(string $key): void
    {
        if (! preg_match('/^[A-Za-z0-9:_-]{1,120}$/D', $key)) {
            throw new InvalidArgumentException('Invalid idempotency key.');
        }
    }

    private static function username(string $reference): string
    {
        return 'sk_'.substr(hash('sha256', $reference), 0, 24);
    }

    private static function marker(string $reference): string
    {
        return 'SkyNetwork ref:'.hash('sha256', $reference);
    }
}
