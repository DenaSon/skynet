@php
    $headers = [
        ['key' => 'reseller_name', 'label' => 'نماینده', 'sortable' => false],
        ['key' => 'phone', 'label' => 'شماره تماس', 'sortable' => false],
        ['key' => 'label', 'label' => 'نام ثبت‌شده', 'sortable' => false],
        ['key' => 'records_count', 'label' => 'تعداد رکورد سفارش', 'sortable' => false],
        ['key' => 'purchased_count', 'label' => 'سفارش با تسویه معتبر', 'sortable' => false],
        ['key' => 'order_id', 'label' => 'آخرین شناسه سفارش'],
        ['key' => 'created_at', 'label' => 'آخرین تاریخ سفارش'],
    ];
@endphp
<x-card class="border border-base-300">
    <p class="mb-4 text-sm text-base-content/60">شماره‌های یکسان فقط در محدوده یک نماینده گروه‌بندی شده‌اند. رکورد فاقد شماره مستقل می‌ماند؛ نام‌های متفاوت یک گروه به‌عنوان هویت تأییدشده نمایش داده نمی‌شوند.</p>
    <x-table :headers="$headers" :rows="$rows" :sort-by="$sortBy" with-pagination show-empty-text empty-text="موردی یافت نشد.">
        @scope('cell_phone', $row)
            <span dir="ltr">{{ $row->phone ?: '—' }}</span>
        @endscope
        @scope('cell_label', $row)
            {{ $row->records_count > 1 ? 'چند رکورد' : ($row->label ?: 'ثبت نشده') }}
        @endscope
        @scope('cell_created_at', $row)
            {{ \Illuminate\Support\Carbon::parse($row->created_at, 'UTC')->timezone('Asia/Tehran')->format('Y-m-d') }}
        @endscope
    </x-table>
    <p class="mt-3 text-xs text-base-content/60">تعداد گروه‌ها و رکوردهای مستقل: {{ number_format($rows->total()) }}</p>
</x-card>
