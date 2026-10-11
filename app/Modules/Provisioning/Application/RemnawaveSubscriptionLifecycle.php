<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Infrastructure\Remnawave\CreateRemnawaveUser;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUserSnapshot;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUsersApi;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Durable, at-most-one-write-attempt, INTERNAL subscription lifecycle.
 *
 * Intentionally not callable from a route, webhook, purchase, Telegram or job.
 * Before any production use a verified settled order & authenticated actor
 * must authorize calling this action. Default-deny ENV prevents writes.
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
            ]);

            return $this->complete($reference, $idempotencyKey, $remote);
        } catch (Throwable $e) {
            $this->ambiguous($reference, $idempotencyKey);

            throw new RuntimeException('Remnawave create outcome is uncertain; reconciliation required.', 0, $e);
        }
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

    public function disable(string $reference, string $idempotencyKey): int
    {
        return $this->mutate($reference, $idempotencyKey, 'disable', ['status' => 'DISABLED']);
    }

    public function enable(string $reference, string $idempotencyKey): int
    {
        return $this->mutate($reference, $idempotencyKey, 'enable', ['status' => 'ACTIVE']);
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
            || $op->kind !== 'create' || $row->state !== 'needs_review' || $op->state !== 'needs_review') {
            throw new RuntimeException('No uncertain create to reconcile.');
        }

        $remote = $this->api()->byUsername(self::username($reference));
        $this->assertIdentity($remote, $reference);

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
            || $row->state !== 'needs_review' || $op->state !== 'needs_review'
            || $row->last_operation_key !== $idempotencyKey
            || ! in_array($op->kind, ['expiration', 'quota', 'disable', 'enable'], true)
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
                'status' => $remote->status === $value,
                'expireAt' => is_string($value) && $this->sameInstant($remote->expireAt, $value),
                default => false,
            };

            if (! $matches) {
                throw new RuntimeException('Remote provisioning result differs; manual operator review is required.');
            }
        }
    }

    private function sameInstant(string $actual, string $expected): bool
    {
        try {
            return (new \DateTimeImmutable($actual))->getTimestamp() === (new \DateTimeImmutable($expected))->getTimestamp();
        } catch (\Exception) {
            return false;
        }
    }

    /** @param array<string, mixed> $fields */
    private function mutate(string $reference, string $key, string $kind, array $fields): int
    {
        $this->assertEnabled();
        $this->assertKeys($reference, $key);

        $reserved = $this->reserve($reference, $key, $kind, $fields, false);

        if ($reserved['alreadyComplete']) {
            return $reserved['remoteId'];
        }

        try {
            $remote = $this->api()->update($reserved['remoteId'], $fields);
            $this->assertIdentity($remote, $reference);
            $this->assertExpected($remote, $fields);

            return $this->complete($reference, $key, $remote);
        } catch (Throwable $e) {
            $this->ambiguous($reference, $key);

            throw new RuntimeException('Remnawave update outcome is uncertain; reconciliation required.', 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{alreadyComplete: bool, remoteId: int}
     */
    private function reserve(string $reference, string $key, string $kind, array $payload, bool $creating): array
    {
        $hash = hash('sha256', json_encode([$reference, $kind, $payload], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($reference, $key, $kind, $hash, $creating): array {
            $previous = DB::table('provisioning_operations')->where('idempotency_key', $key)->lockForUpdate()->first();
            $record = DB::table('provisioning_subscriptions')->where('reference', $reference)->lockForUpdate()->first();

            if ($previous !== null) {
                if ($record === null || (int) $previous->subscription_id !== (int) $record->id
                    || $previous->kind !== $kind || ! hash_equals((string) $previous->payload_hash, $hash)) {
                    throw new RuntimeException('Idempotency key is bound to a different operation.');
                }

                if ($previous->state !== 'completed' || $record->state !== 'active'
                    || ! is_numeric($record->remote_user_id)) {
                    throw new RuntimeException('Provisioning operation requires reconciliation; no automatic replay.');
                }

                return ['alreadyComplete' => true, 'remoteId' => (int) $record->remote_user_id];
            }

            if ($creating) {
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
