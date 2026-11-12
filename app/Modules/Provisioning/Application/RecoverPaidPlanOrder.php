<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\CreateRemnawaveUser;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Emergency, trusted-root-only recovery of a single uncertain paid CREATE.
 * Reuses the existing financial checks, provider lifecycle and completion.
 * Never replay the original POST or rewrite its historical evidence.
 */
final readonly class RecoverPaidPlanOrder
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private FulfillPaidPlanOrder $fulfill,
        private CheckPlanSquadReadiness $squads,
        private RemnawaveSubscriptionLifecycle $lifecycle,
    ) {}

    public function authorize(int $actorId, int $orderId): object
    {
        $this->access->handle($actorId);

        if ($orderId < 1 || config('services.remnawave.recovery_create_enabled', false) !== true
            || config('services.remnawave.writes_enabled') !== true
            || config('services.remnawave.squad_bindings_live_verified') !== true) {
            throw new RuntimeException('Emergency CREATE recovery is not enabled.');
        }

        $reference = 'sales-order:'.$orderId;
        $order = DB::table('sales_orders')->where('id', $orderId)->first();
        $outbox = DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)->first();
        $local = DB::table('provisioning_subscriptions')->where('reference', $reference)->first();
        $original = DB::table('provisioning_operations')
            ->where('idempotency_key', $reference.':create')->first();

        if ($order === null || $order->status !== 'paid' || $order->plan_id === null
            || $outbox === null || $outbox->state !== 'needs_review'
            || (int) $outbox->attempts !== 1 || $outbox->event_key !== $reference.':create'
            || $local === null || $local->state !== 'needs_review' || $local->remote_user_id !== null
            || $local->last_operation_key !== $reference.':create'
            || $original === null || $original->kind !== 'create' || $original->state !== 'needs_review'
            || (int) $original->subscription_id !== (int) $local->id
            || DB::table('sales_renewal_orders')->where('sales_order_id', $orderId)->exists()
            || DB::table('provisioning_order_links')->where('sales_order_id', $orderId)->exists()
            || DB::table('provisioning_operations')
                ->where('idempotency_key', $reference.':recovery:create:1')->exists()) {
            throw new RuntimeException('Order is not eligible for a single recovery CREATE.');
        }

        $this->fulfill->assertSettlement($order);

        $expected = json_decode((string) $original->expected_payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($expected)
            || (int) $order->duration_days < 1 || (int) $order->duration_days > 3650
            || ! in_array($order->plan_kind, ['time', 'volume', 'hybrid'], true)
            || ($order->traffic_gb === null && $order->plan_kind !== 'time')
            || ($order->traffic_gb !== null && (int) $order->traffic_gb < 1)
            || $order->plan_revision === null || ! is_string($order->internal_squad_uuid)
            || ($expected['username'] ?? null) !== 'sk_'.substr(hash('sha256', $reference), 0, 24)
            || ($expected['description'] ?? null) !== 'SkyNetwork ref:'.hash('sha256', $reference)
            || ($expected['status'] ?? null) !== 'ACTIVE'
            || ($expected['trafficLimitStrategy'] ?? null) !== 'NO_RESET'
            || ! is_string($expected['expireAt'] ?? null)
            || ($expected['activeInternalSquads'] ?? null) !== [(string) $order->internal_squad_uuid]
            || ($expected['trafficLimitBytes'] ?? null) !== ($order->traffic_gb === null
                ? 0 : (int) $order->traffic_gb * 1073741824)) {
            throw new RuntimeException('Original provider intent does not match the immutable paid order.');
        }

        // MySQL JSON columns may normalize object key order. Reconstruct the
        // original CreateRemnawaveUser::body() insertion order before checking
        // the immutable reservation hash. Still reject modified/missing fields.
        $originalBody = (new CreateRemnawaveUser(
            username: 'sk_'.substr(hash('sha256', $reference), 0, 24),
            expireAt: $expected['expireAt'],
            trafficLimitBytes: $order->traffic_gb === null ? 0 : (int) $order->traffic_gb * 1073741824,
            internalSquadUuids: [(string) $order->internal_squad_uuid],
            description: 'SkyNetwork ref:'.hash('sha256', $reference),
        ))->body();
        $normalizedExpected = $expected;
        $normalizedOriginal = $originalBody;
        ksort($normalizedExpected);
        ksort($normalizedOriginal);
        if ($normalizedExpected !== $normalizedOriginal
            || ! hash_equals(
                (string) $original->payload_hash,
                hash('sha256', json_encode([$reference, 'create', $originalBody], JSON_THROW_ON_ERROR)),
            )) {
            throw new RuntimeException('Original provider intent does not match the immutable paid order.');
        }

        // Revoked or revised plan/Squad bindings never authorize a new write.
        $this->squads->assertOrderReady((int) $order->plan_id, (int) $order->plan_revision, $order->internal_squad_uuid);

        return $order;
    }

    public function handle(int $actorId, int $orderId): int
    {
        $order = $this->authorize($actorId, $orderId);
        $reference = 'sales-order:'.$orderId;

        $remoteId = $this->lifecycle->recoverCreate(
            $reference,
            $reference.':recovery:create:1',
            now()->utc()->addDays((int) $order->duration_days)->format('Y-m-d\TH:i:s\Z'),
            $order->traffic_gb === null ? 0 : (int) $order->traffic_gb * 1073741824,
            [(string) $order->internal_squad_uuid],
            $actorId,
        );

        // Same atomic order-link, notification and settlement checks as
        // the regular paid fulfillment; never mark a partial POST as success.
        return $this->fulfill->completeRecovered($orderId, $remoteId);
    }
}
