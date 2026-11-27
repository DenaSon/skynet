@php
    $headers = [
        ['key' => 'id', 'label' => 'شناسه'],
        ['key' => 'name', 'label' => 'نام'],
        ['key' => 'is_active', 'label' => 'وضعیت'],
        ['key' => 'created_at', 'label' => 'عضویت'],
        ['key' => 'transactions', 'label' => 'تعداد تراکنش'],
        ['key' => 'revenue_rials', 'label' => 'خرید تسویه‌شده'],
    ];
    if ($resellers) {
        $headers[] = ['key' => 'phones', 'label' => 'شماره یکتا'];
        $headers[] = ['key' => 'name_only', 'label' => 'نام بدون شماره'];
    } else {
        $headers[] = ['key' => 'telegram_linked', 'label' => 'تلگرام', 'sortable' => false];
    }
@endphp
<x-card class="border border-base-300">
    @if ($resellers)
        <p class="mb-4 text-sm text-base-content/60">مبلغ خرید نماینده نزد دناگیت بر اساس کانال ثبت‌شده سفارش است. سفارش‌های تاریخی با کانال نامشخص در این مبلغ لحاظ نمی‌شوند.</p>
    @endif
    <x-table :headers="$headers" :rows="$rows" :sort-by="$sortBy" with-pagination>
        @scope('cell_is_active', $row)
            <x-badge :value="$row->is_active ? 'فعال' : 'غیرفعال'" :class="$row->is_active ? 'badge-success badge-soft' : 'badge-error badge-soft'" />
        @endscope
        @scope('cell_telegram_linked', $row)
            <x-badge :value="$row->telegram_linked ? 'متصل' : 'متصل نیست'" class="badge-ghost" />
        @endscope
        @scope('cell_created_at', $row)
            {{ \Illuminate\Support\Carbon::parse($row->created_at, 'UTC')->timezone('Asia/Tehran')->format('Y-m-d') }}
        @endscope
        @scope('cell_revenue_rials', $row)
            {{ number_format($row->revenue_rials / 10, $row->revenue_rials % 10 === 0 ? 0 : 1) }} تومان
        @endscope
    </x-table>
    @if ($rows->isEmpty())
        <p class="py-4 text-sm text-base-content/60">موردی با فیلترهای فعلی پیدا نشد.</p>
    @endif
    <p class="mt-3 text-xs text-base-content/60">تعداد نتایج: {{ number_format($rows->total()) }}</p>
</x-card>
