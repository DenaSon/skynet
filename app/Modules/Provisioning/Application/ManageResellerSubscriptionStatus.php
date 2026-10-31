<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Settings\ResellerServiceStatusSettings;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Only the reseller who owns a fulfilled order may pause its existing service.
 * Resume is restricted to the last completed pause by that same reseller.
 * Admin-only status management and the existing provider lifecycle stay intact.
 */
final readonly class ManageResellerSubscriptionStatus
{
    public function __construct(
        private ResolveSalesBuyer $buyers,
        private RemnawaveSubscriptionLifecycle $lifecycle,
    ) {}

    /** Fail closed if the setting is missing or the database is unavailable. */
    public function enabled(): bool
    {
        if (config('denagate_live.enabled') !== true
            || config('services.remnawave.writes_enabled') !== true) {
            return false;
        }

        try {
            return app(ResellerServiceStatusSettings::class)->enabled === true;
        } catch (Throwable) {
            return false;
        }
    }

    public function canResume(int $buyerId, int $orderId): bool
    {
        $linked = $this->linked($buyerId, $orderId);

        return $this->pausedByReseller($linked, $buyerId, $orderId);
    }

    public function authorizeChange(int $buyerId, int $orderId, string $mode): void
    {
        if (! $this->enabled() || ! in_array($mode, ['disable', 'enable'], true)) {
            throw ValidationException::withMessages(['status' => 'Status changes unavailable.']);
        }

        $linked = $this->linked($buyerId, $orderId);
        if ($mode === 'enable' && ! $this->pausedByReseller($linked, $buyerId, $orderId)) {
            throw ValidationException::withMessages(['status' => 'Only your own pause may be resumed.']);
        }

        // The lifecycle GET independently validates the remote identity and
        // works in both Livewire and the asynchronous provider worker.
        $remote = $this->lifecycle->subscription('sales-order:'.$orderId);
        $this->assertUsable($remote->status, $remote->expireAt, $remote->trafficLimitBytes, $remote->usedTrafficBytes, $mode);

        // Race/revocation check after the provider GET.
        $again = $this->linked($buyerId, $orderId);
        if ($mode === 'enable' && ! $this->pausedByReseller($again, $buyerId, $orderId)) {
            throw ValidationException::withMessages(['status' => 'Subscription state changed.']);
        }
    }

    public function change(int $buyerId, int $orderId, string $mode, string $key): void
    {
        if (preg_match('/\\Alive:status:'.preg_quote((string) $orderId, '/').':'.preg_quote((string) $buyerId, '/').':[a-f0-9]{32}\\z/D', $key) !== 1) {
            throw ValidationException::withMessages(['status' => 'Invalid status request.']);
        }

        $this->authorizeChange($buyerId, $orderId, $mode);
        $reference = 'sales-order:'.$orderId;

        if ($mode === 'disable') {
            $this->lifecycle->disable($reference, $key);
        } else {
            $this->lifecycle->enable($reference, $key);
        }
    }

    private function linked(int $buyerId, int $orderId): object
    {
        if ($buyerId < 1 || $orderId < 1 || ! $this->buyers->isReseller($buyerId)) {
            throw new AuthorizationException;
        }

        $row = DB::table('sales_orders as orders')
            ->join('sales_fulfillment_outbox as intent', 'intent.sales_order_id', '=', 'orders.id')
            ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
            ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
            ->where('orders.id', $orderId)
            ->where('orders.buyer_user_id', $buyerId)
            ->where('orders.status', 'fulfilled')
            ->whereNotNull('orders.plan_id')
            ->where('intent.state', 'completed')
            ->where('sub.reference', 'sales-order:'.$orderId)
            ->where('sub.state', 'active')
            ->whereNotNull('sub.remote_user_id')
            ->select('sub.id', 'sub.last_operation_key')
            ->first();

        if ($row === null) {
            throw ValidationException::withMessages(['status' => 'Subscription is not eligible.']);
        }

        return $row;
    }

    private function pausedByReseller(object $linked, int $buyerId, int $orderId): bool
    {
        $key = $linked->last_operation_key;
        if (! is_string($key)
            || preg_match('/\\Alive:status:'.preg_quote((string) $orderId, '/').':'.preg_quote((string) $buyerId, '/').':[a-f0-9]{32}\\z/D', $key) !== 1) {
            return false;
        }

        return DB::table('provisioning_operations')
            ->where('subscription_id', $linked->id)
            ->where('idempotency_key', $key)
            ->where('kind', 'disable')
            ->where('state', 'completed')
            ->exists();
    }

    private function assertUsable(string $status, string $expireAt, int $limitBytes, int $usedBytes, string $mode): void
    {
        if ($status !== ($mode === 'disable' ? 'ACTIVE' : 'DISABLED')
            || (new DateTimeImmutable($expireAt))->getTimestamp() <= time()
            || ($limitBytes > 0 && $usedBytes >= $limitBytes)) {
            throw ValidationException::withMessages(['status' => 'Subscription state does not allow this change.']);
        }
    }
}
