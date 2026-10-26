<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ManageResellerServiceLabels
{
    public function __construct(private ResolveSalesBuyer $buyers) {}

    /** @param list<int> $orderIds
     * @return array<int, string>
     */
    public function forOrders(int $buyerId, array $orderIds): array
    {
        $this->authorize($buyerId);
        if ($orderIds === []) {
            return [];
        }

        $rows = DB::table('sales_service_customer_labels as labels')
            ->join('sales_orders as orders', 'orders.id', '=', 'labels.sales_order_id')
            ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
            ->where('orders.buyer_user_id', $buyerId)
            ->whereIn('orders.status', ['paid', 'fulfilled'])
            ->whereIn('orders.id', $orderIds)
            ->whereNotNull('labels.label')->select('orders.id', 'labels.label')
            ->get();

        $labels = [];
        foreach ($rows as $row) {
            $labels[(int) $row->id] = (string) $row->label;
        }

        return $labels;
    }

    /**
     * Search only private customer labels on linked, paid reseller-owned services.
     *
     * @return array{items: list<array{orderId: int, label: ?string}>, nextBeforeOrderId: ?int}
     */
    public function search(int $buyerId, string $term, int $limit = 6, ?int $beforeOrderId = null): array
    {
        $this->authorize($buyerId);
        $term = trim($term);
        if ($term === '' || mb_strlen($term) > 60
            || preg_match('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $term) !== 0
            || $limit < 1 || $limit > 20 || ($beforeOrderId !== null && $beforeOrderId < 1)) {
            throw ValidationException::withMessages(['search' => 'Invalid customer name search.']);
        }

        // Treat %, _ and the escape character literally, never as user SQL wildcards.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $query = DB::table('sales_service_customer_labels as labels')
            ->join('sales_orders as orders', 'orders.id', '=', 'labels.sales_order_id')
            ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
            ->where('orders.buyer_user_id', $buyerId)
            ->whereIn('orders.status', ['paid', 'fulfilled'])
            ->whereRaw("labels.label LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);

        if ($beforeOrderId !== null) {
            $query->where('orders.id', '<', $beforeOrderId);
        }

        $rows = $query->orderByDesc('orders.id')->limit($limit + 1)
            ->get(['orders.id as order_id', 'labels.label']);
        $hasNext = $rows->count() > $limit;
        $items = $rows->take($limit)->map(static fn ($row): array => [
            'orderId' => (int) $row->order_id,
            'label' => $row->label === null ? null : (string) $row->label,
        ])->values()->all();

        return [
            'items' => $items,
            'nextBeforeOrderId' => $hasNext ? $items[count($items) - 1]['orderId'] : null,
        ];
    }

    public function get(int $buyerId, int $orderId): ?string
    {
        $this->authorize($buyerId);
        $this->assertOwnedService($buyerId, $orderId);

        $label = DB::table('sales_service_customer_labels')
            ->where('sales_order_id', $orderId)->value('label');

        return $label === null ? null : (string) $label;
    }

    /** Null removes the optional label, never the service or immutable order. */
    public function save(int $buyerId, int $orderId, ?string $label): void
    {
        if ($label !== null) {
            $clean = preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $label);
            if ($clean === null) {
                throw ValidationException::withMessages(['label' => 'Invalid customer label.']);
            }
            $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
            if ($clean === '' || mb_strlen($clean) > 60) {
                throw ValidationException::withMessages(['label' => 'Customer label must be 1 to 60 characters.']);
            }
            $label = $clean;
        }

        DB::transaction(function () use ($buyerId, $orderId, $label): void {
            $this->authorize($buyerId);
            $this->assertOwnedService($buyerId, $orderId, true);

            if ($label === null) {
                $phone = DB::table('sales_service_customer_labels')
                    ->where('sales_order_id', $orderId)->value('phone');
                if ($phone === null) {
                    DB::table('sales_service_customer_labels')->where('sales_order_id', $orderId)->delete();
                } else {
                    DB::table('sales_service_customer_labels')->where('sales_order_id', $orderId)
                        ->update(['label' => null, 'updated_at' => now()]);
                }

                return;
            }

            DB::table('sales_service_customer_labels')->upsert([[
                'sales_order_id' => $orderId,
                'label' => $label,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['sales_order_id'], ['label', 'updated_at']);
        }, 3);
    }

    /** A contact number is optional, owner-scoped and never sent to Remnawave. */
    public function phone(int $buyerId, int $orderId): ?string
    {
        $this->authorize($buyerId);
        $this->assertOwnedService($buyerId, $orderId);

        $value = DB::table('sales_service_customer_labels')
            ->where('sales_order_id', $orderId)->value('phone');

        return $value === null ? null : (string) $value;
    }

    /** A null number removes only the phone, preserving any saved customer name. */
    public function savePhone(int $buyerId, int $orderId, ?string $input): void
    {
        $number = $input === null ? null : $this->normalizePhone($input);
        DB::transaction(function () use ($buyerId, $orderId, $number): void {
            $this->authorize($buyerId);
            $this->assertOwnedService($buyerId, $orderId, true);
            $row = DB::table('sales_service_customer_labels')->where('sales_order_id', $orderId)->first(['label']);
            if ($row === null && $number === null) {
                return;
            }
            if ($row === null) {
                DB::table('sales_service_customer_labels')->insert([
                    'sales_order_id' => $orderId, 'label' => null, 'phone' => $number,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return;
            }
            if ($row->label === null && $number === null) {
                DB::table('sales_service_customer_labels')->where('sales_order_id', $orderId)->delete();

                return;
            }
            DB::table('sales_service_customer_labels')->where('sales_order_id', $orderId)
                ->update(['phone' => $number, 'updated_at' => now()]);
        }, 3);
    }

    private function normalizePhone(string $input): string
    {
        $digits = $this->digits(trim($input));
        if ($digits === null) {
            throw ValidationException::withMessages(['phone' => 'Invalid mobile number.']);
        }
        if (preg_match('/\A09[0-9]{9}\z/D', $digits) === 1) {
            return '+98'.substr($digits, 1);
        }
        if (preg_match('/\A(?:\+98|0098|98)9[0-9]{9}\z/D', $digits) === 1) {
            return '+98'.substr($digits, -10);
        }

        throw ValidationException::withMessages(['phone' => 'Invalid Iranian mobile number.']);
    }

    private function digits(string $value): ?string
    {
        $normalized = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return preg_match('/\A\+?[0-9]+\z/D', $normalized) === 1 ? $normalized : null;
    }

    /** Save the customer's display name on a pending managed-plan order before payment. */
    public function savePending(int $buyerId, int $orderId, string $name): void
    {
        if (str_contains($name, "\n") || str_contains($name, "\r")) {
            throw ValidationException::withMessages(['label' => 'Multiline names are not permitted.']);
        }
        $clean = preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $name);
        if ($clean === null) {
            throw ValidationException::withMessages(['label' => 'Invalid name.']);
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $clean));
        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['label' => 'Customer username must be 1 to 60 characters.']);
        }

        DB::transaction(function () use ($buyerId, $orderId, $name): void {
            $this->authorize($buyerId);
            $this->assertPendingNewPlan($buyerId, $orderId, true);

            DB::table('sales_service_customer_labels')->upsert([[
                'sales_order_id' => $orderId,
                'label' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['sales_order_id'], ['label', 'updated_at']);
        }, 3);
    }

    /** Only the original pending-order owner may inspect the checkout label. */
    public function pendingName(int $buyerId, int $orderId): ?string
    {
        $this->authorize($buyerId);
        $order = DB::table('sales_orders')->where('id', $orderId)
            ->where('buyer_user_id', $buyerId)->where('status', 'pending_payment')
            ->whereNotNull('plan_id')->first(['id']);
        if ($order === null || DB::table('sales_renewal_orders')->where('sales_order_id', $orderId)->exists()) {
            return null;
        }

        $name = DB::table('sales_service_customer_labels')
            ->where('sales_order_id', $orderId)->value('label');

        return $name === null ? null : (string) $name;
    }

    /** Store mobile for a reseller's new pending plan before payment. */
    public function savePendingPhone(int $buyerId, int $orderId, string $phone): void
    {
        $number = $this->normalizePhone($phone);
        DB::transaction(function () use ($buyerId, $orderId, $number): void {
            $this->authorize($buyerId);
            $this->assertPendingNewPlan($buyerId, $orderId, true);
            DB::table('sales_service_customer_labels')->upsert([[
                'sales_order_id' => $orderId, 'phone' => $number,
                'created_at' => now(), 'updated_at' => now(),
            ]], ['sales_order_id'], ['phone', 'updated_at']);
        }, 3);
    }

    public function pendingPhone(int $buyerId, int $orderId): ?string
    {
        $this->authorize($buyerId);
        $this->assertPendingNewPlan($buyerId, $orderId);

        $phone = DB::table('sales_service_customer_labels')
            ->where('sales_order_id', $orderId)->value('phone');

        return $phone === null ? null : (string) $phone;
    }

    /** Shared payability check, not merely a keyboard visibility rule. */
    public function assertPendingDetails(int $buyerId, int $orderId): void
    {
        if (! $this->buyers->isReseller($buyerId)) {
            return;
        }
        $order = DB::table('sales_orders')->where('id', $orderId)
            ->where('buyer_user_id', $buyerId)->first(['id', 'plan_id', 'status']);
        if ($order === null || $order->status !== 'pending_payment') {
            throw ValidationException::withMessages(['customer' => 'Order is not pending.']);
        }
        // Preserve legacy reseller products and renewals without new requirements.
        if ($order->plan_id === null || DB::table('sales_renewal_orders')
            ->where('sales_order_id', $orderId)->exists()) {
            return;
        }
        $details = DB::table('sales_service_customer_labels')
            ->where('sales_order_id', $orderId)->first(['label']);
        if ($details === null || trim((string) $details->label) === '') {
            throw ValidationException::withMessages(['customer' => 'Complete customer username before payment.']);
        }
    }

    private function assertPendingNewPlan(int $buyerId, int $orderId, bool $lock = false): void
    {
        $query = DB::table('sales_orders')->where('id', $orderId)
            ->where('buyer_user_id', $buyerId)->where('status', 'pending_payment')->whereNotNull('plan_id');
        if ($lock) {
            $query->lockForUpdate();
        }
        if ($query->first(['id']) === null || DB::table('sales_renewal_orders')
            ->where('sales_order_id', $orderId)->exists()) {
            throw ValidationException::withMessages(['customer' => 'Customer information cannot be edited for this order.']);
        }
    }

    private function authorize(int $buyerId): void
    {
        if (! $this->buyers->isReseller($buyerId)) {
            throw new AuthorizationException;
        }
    }

    private function assertOwnedService(int $buyerId, int $orderId, bool $lock = false): void
    {
        if ($orderId < 1) {
            throw ValidationException::withMessages(['service' => 'Service not found.']);
        }

        $query = DB::table('sales_orders')->where('id', $orderId)
            ->where('buyer_user_id', $buyerId)
            ->whereIn('status', ['paid', 'fulfilled']);

        if ($lock) {
            $query->lockForUpdate();
        }

        if ($query->first(['id']) === null || ! DB::table('provisioning_order_links')
            ->where('sales_order_id', $orderId)->exists()) {
            throw ValidationException::withMessages(['service' => 'Service not found.']);
        }
    }
}
