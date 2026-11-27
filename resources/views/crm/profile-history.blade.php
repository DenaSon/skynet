@php
    $statuses = ['pending_payment' => 'در انتظار پرداخت', 'paid' => 'پرداخت‌شده', 'fulfilled' => 'تکمیل‌شده', 'refunded' => 'برگشت‌خورده', 'cancelled' => 'لغوشده'];
    $channels = ['direct' => 'مستقیم', 'reseller' => 'نمایندگی'];
    $headers = match ($tab) {
        'orders' => [
            ['key' => 'id', 'label' => 'سفارش'],
            ['key' => 'product_name', 'label' => 'محصول'],
            ['key' => 'renewed', 'label' => 'نوع'],
            ['key' => 'buyer_category', 'label' => 'کانال'],
            ['key' => 'status', 'label' => 'وضعیت سفارش'],
            ['key' => 'total_price_rials', 'label' => 'مبلغ سفارش'],
            ['key' => 'created_at', 'label' => 'تاریخ'],
        ],
        'payments' => [
            ['key' => 'order_id', 'label' => 'سفارش'],
            ['key' => 'payment_method', 'label' => 'روش تسویه'],
            ['key' => 'buyer_category', 'label' => 'کانال'],
            ['key' => 'amount_rials', 'label' => 'مبلغ تسویه'],
            ['key' => 'settled_at', 'label' => 'زمان تسویه'],
        ],
        default => [
            ['key' => 'id', 'label' => 'سفارش'],
            ['key' => 'product_name', 'label' => 'محصول'],
            ['key' => 'state', 'label' => 'وضعیت محلی'],
            ['key' => 'created_at', 'label' => 'تاریخ سفارش'],
        ],
    };
@endphp
@if ($tab === 'services')
    <x-alert icon="o-information-circle" class="alert-info mb-4">این وضعیت از اطلاعات محلی ثبت‌شده خوانده می‌شود و وضعیت زنده اتصال یا انقضا نیست.</x-alert>
@endif
<x-table :headers="$headers" :rows="$rows" with-pagination show-empty-text empty-text="موردی یافت نشد.">
    @scope('cell_status', $row)
        <x-badge :value="['pending_payment' => 'در انتظار پرداخت', 'paid' => 'پرداخت‌شده', 'fulfilled' => 'تکمیل‌شده', 'refunded' => 'برگشت‌خورده', 'cancelled' => 'لغوشده'][$row->status] ?? 'نامشخص'" class="badge-ghost" />
    @endscope
    @scope('cell_buyer_category', $row)
        {{ ['direct' => 'مستقیم', 'reseller' => 'نمایندگی'][$row->buyer_category] ?? 'نامشخص' }}
    @endscope
    @scope('cell_renewed', $row)
        {{ $row->renewed ? 'تمدید' : 'خرید' }}
    @endscope
    @scope('cell_payment_method', $row)
        {{ ['bank' => 'بانکی', 'wallet' => 'کیف پول'][$row->payment_method] ?? 'نامشخص' }}
    @endscope
    @scope('cell_state', $row)
        <x-badge :value="['active' => 'فعال محلی', 'needs_review' => 'نیازمند بررسی', 'pending' => 'در انتظار', 'failed' => 'ناموفق', 'reserved' => 'رزروشده'][$row->state] ?? 'نامشخص'" class="badge-ghost" />
    @endscope
    @scope('cell_total_price_rials', $row)
        {{ number_format($row->total_price_rials / 10, $row->total_price_rials % 10 === 0 ? 0 : 1) }} تومان
    @endscope
    @scope('cell_amount_rials', $row)
        {{ number_format($row->amount_rials / 10, $row->amount_rials % 10 === 0 ? 0 : 1) }} تومان
    @endscope
    @scope('cell_created_at', $row)
        {{ \Illuminate\Support\Carbon::parse($row->created_at, 'UTC')->timezone('Asia/Tehran')->format('Y-m-d H:i') }}
    @endscope
    @scope('cell_settled_at', $row)
        {{ \Illuminate\Support\Carbon::parse($row->settled_at, 'UTC')->timezone('Asia/Tehran')->format('Y-m-d H:i') }}
    @endscope
</x-table>
<p class="mt-3 text-xs text-base-content/60">تعداد نتایج: {{ number_format($rows->total()) }}</p>
