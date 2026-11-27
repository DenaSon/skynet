@php
    $headers = [
        ['key' => 'order_id', 'label' => 'سفارش'],
        ['key' => 'label', 'label' => 'نام ثبت‌شده', 'sortable' => false],
        ['key' => 'phone', 'label' => 'شماره تماس', 'sortable' => false],
        ['key' => 'reseller_name', 'label' => 'نماینده', 'sortable' => false],
        ['key' => 'product_name', 'label' => 'محصول', 'sortable' => false],
        ['key' => 'purchased', 'label' => 'تسویه معتبر', 'sortable' => false],
        ['key' => 'status', 'label' => 'وضعیت سفارش', 'sortable' => false],
        ['key' => 'created_at', 'label' => 'تاریخ سفارش'],
    ];
@endphp
<x-card class="border border-base-300">
    <p class="mb-4 text-sm text-base-content/60">هر ردیف یک رکورد مرتبط با سفارش است، نه لزوماً یک شخص یکتا. شماره‌های تکراری در شاخص هر نماینده تنها یک بار شمرده می‌شوند.</p>
    <p class="mb-3 text-sm font-medium text-primary">تعداد رکوردها: {{ \App\Support\PersianCalendar::number(number_format($rows->total())) }}</p>
    <x-table :headers="$headers" :rows="$rows" :sort-by="$sortBy" with-pagination>
        @scope('cell_label', $row)
            {{ $row->label ?: 'ثبت نشده' }}
        @endscope
        @scope('cell_phone', $row)
            <span dir="ltr">{{ $row->phone ?: '—' }}</span>
        @endscope
        @scope('cell_purchased', $row)
            <x-badge :value="$row->purchased ? 'دارد' : 'ندارد'" :class="$row->purchased ? 'badge-success badge-soft' : 'badge-ghost'" />
        @endscope
        @scope('cell_status', $row)
            {{ ['pending_payment' => 'در انتظار پرداخت', 'paid' => 'پرداخت‌شده', 'fulfilled' => 'تکمیل‌شده', 'refunded' => 'برگشت‌خورده', 'cancelled' => 'لغوشده'][$row->status] ?? 'نامشخص' }}
        @endscope
        @scope('cell_created_at', $row)
            {{ \App\Support\PersianCalendar::date(\Illuminate\Support\Carbon::parse($row->created_at, 'UTC')->timezone('Asia/Tehran')->format('Y-m-d')) }}
        @endscope
    </x-table>
    @if ($rows->isEmpty())
        <p class="py-4 text-sm text-base-content/60">موردی با فیلترهای فعلی پیدا نشد.</p>
    @endif
</x-card>
