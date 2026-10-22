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
            ->select('orders.id', 'labels.label')
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
     * @return array{items: list<array{orderId: int, label: string}>, nextBeforeOrderId: ?int}
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
            'label' => (string) $row->label,
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
                DB::table('sales_service_customer_labels')->where('sales_order_id', $orderId)->delete();

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
