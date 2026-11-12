<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AdminPendingReceiptFollowup
{
    public const EVENT = 'BuyerReceiptReminder';

    private const QUIET_HOURS = 6;

    public function __construct(private SuperAdminTrust $trust) {}

    /** @return array{items:list<object>,page:int,hasNext:bool} */
    public function browse(int $actorId, int $page = 1, ?int $buyerId = null): array
    {
        $this->authorize($actorId);
        if ($page < 1 || $page > 1000 || ($buyerId !== null && $buyerId < 1)) {
            throw ValidationException::withMessages(['order' => 'Invalid page or buyer.']);
        }

        $rows = $this->eligibleOrders()
            ->when($buyerId !== null, static fn ($query) => $query->where('o.buyer_user_id', $buyerId))
            ->orderByDesc('o.id')
            ->offset(($page - 1) * 8)
            ->limit(9)
            ->get();

        return [
            'items' => $rows->take(8)->values()->all(),
            'page' => $page,
            'hasNext' => $rows->count() > 8,
        ];
    }

    /** @return array{order:object,latest:?object,canSend:bool} */
    public function detail(int $actorId, int $orderId): array
    {
        $this->authorize($actorId);
        if ($orderId < 1) {
            throw ValidationException::withMessages(['order' => 'Invalid order.']);
        }

        $order = $this->eligibleOrders()->where('o.id', $orderId)->first();
        if ($order === null) {
            throw ValidationException::withMessages(['order' => 'Order is no longer awaiting a receipt.']);
        }

        $latest = $this->latest($orderId);

        return [
            'order' => $order,
            'latest' => $latest,
            'canSend' => $this->configured()
                && $this->contactReady($order)
                && ($latest === null || $latest->created_at < now()->subHours(self::QUIET_HOURS)->toDateTimeString()),
        ];
    }

    /** Reserve exactly one reminder intent. Never alter money, claims or subscriptions. */
    public function enqueue(int $actorId, int $orderId, string $token): int
    {
        $this->authorize($actorId);
        if ($orderId < 1 || preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1 || ! $this->configured()) {
            throw ValidationException::withMessages(['order' => 'Reminder unavailable.']);
        }

        return DB::transaction(function () use ($actorId, $orderId, $token): int {
            // Serialize requests per order across multiple trusted operators.
            $order = DB::table('sales_orders')->where('id', $orderId)->lockForUpdate()->first(['id']);
            if ($order === null) {
                throw ValidationException::withMessages(['order' => 'Order not found.']);
            }

            $snapshot = $this->eligibleOrders()->where('o.id', $orderId)->first();
            if ($snapshot === null || ! $this->contactReady($snapshot)) {
                throw ValidationException::withMessages(['order' => 'Order or Sales Bot contact is unavailable.']);
            }
            if (DB::table('telegram_notification_deliveries')
                ->where('event_type', self::EVENT)
                ->where('order_id', $orderId)
                ->where('created_at', '>', now()->subHours(self::QUIET_HOURS))->exists()) {
                throw ValidationException::withMessages(['order' => 'A reminder was already requested recently.']);
            }

            $id = (int) DB::table('telegram_notification_deliveries')->insertGetId([
                'event_key' => 'receipt-followup:order:'.$orderId.':actor:'.$actorId.':'.$token,
                'event_type' => self::EVENT,
                'recipient_user_id' => $snapshot->buyer_user_id,
                'bot_key' => 'sales',
                'order_id' => $orderId,
                'state' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Telegram interface enqueues after this transaction commits.
            // The existing scheduled outbox dispatcher recovers a lost enqueue.
            return $id;
        }, 3);
    }

    /** Validate an existing delivery again immediately before Telegram send. */
    public function canDeliver(object $delivery): bool
    {
        $orderId = (int) $delivery->order_id;
        $userId = (int) $delivery->recipient_user_id;

        if ($orderId < 1 || $userId < 1 || $delivery->event_type !== self::EVENT
            || $delivery->bot_key !== 'sales' || (int) $delivery->attempts !== 1 || ! $this->configured()) {
            return false;
        }

        if (preg_match('/\Areceipt-followup:order:'.$orderId.':actor:([1-9][0-9]*):[a-f0-9]{32}\z/D', (string) $delivery->event_key, $matches) !== 1) {
            return false;
        }

        try {
            $this->authorize((int) $matches[1]);
        } catch (AuthorizationException) {
            return false;
        }

        $order = $this->eligibleOrders()->where('o.id', $orderId)
            ->where('o.buyer_user_id', $userId)->first();

        return $order !== null && $this->contactReady($order);
    }

    private function authorize(int $actorId): void
    {
        $user = User::query()->with('telegramAccount')->find($actorId);
        if ($user === null || ! $this->trust->eligible($user)) {
            throw new AuthorizationException;
        }
    }

    private function configured(): bool
    {
        return config('telegram_notifications.enabled') === true
            && config('telegram.sales.enabled') === true
            && config('sales.manual_bank_review_enabled') === true
            && is_string(config('telegram.sales.token'))
            && config('telegram.sales.token') !== ''
            && config('telegram.sales.token') !== config('nutgram.token');
    }

    private function contactReady(object $order): bool
    {
        return (bool) $order->is_active && is_numeric($order->telegram_user_id)
            && (int) $order->telegram_user_id > 0
            && $order->delivery_status === 'reachable'
            && (string) $order->chat_id === (string) $order->telegram_user_id;
    }

    private function latest(int $orderId): ?object
    {
        return DB::table('telegram_notification_deliveries')
            ->where('event_type', self::EVENT)
            ->where('order_id', $orderId)
            ->orderByDesc('id')
            ->first(['state', 'last_failure', 'created_at', 'sent_at']);
    }

    private function eligibleOrders(): Builder
    {
        return DB::table('sales_orders as o')
            ->join('users as u', 'u.id', '=', 'o.buyer_user_id')
            ->leftJoin('telegram_accounts as ta', 'ta.user_id', '=', 'u.id')
            ->leftJoin('telegram_bot_contacts as c', function ($join): void {
                $join->on('c.user_id', '=', 'u.id')->where('c.bot_key', 'sales');
            })
            ->where('o.status', 'pending_payment')
            // Wallet-only reseller checkout is not a bank-receipt follow-up.
            ->where('o.buyer_category', 'direct')
            ->whereNotNull('o.plan_id')
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('sales_bank_payment_claims as claims')
                    ->whereColumn('claims.sales_order_id', 'o.id');
            })
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('sales_bank_settlements as bank')
                    ->whereColumn('bank.sales_order_id', 'o.id');
            })
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('sales_order_settlements as wallet')
                    ->whereColumn('wallet.sales_order_id', 'o.id');
            })
            ->select(
                'o.id', 'o.buyer_user_id', 'o.product_name', 'o.total_price_rials', 'o.created_at',
                'u.name', 'u.is_active', 'ta.telegram_user_id', 'c.chat_id',
                'c.delivery_status', 'c.last_seen_at',
            );
    }
}
