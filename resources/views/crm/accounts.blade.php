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
        array_unshift($headers, ['key' => 'sales_rank', 'label' => 'رتبه فروش', 'sortable' => false]);
        $headers[] = ['key' => 'phones', 'label' => 'شماره یکتا'];
        $headers[] = ['key' => 'purchased_phones', 'label' => 'شماره دارای خرید', 'sortable' => false];
        $headers[] = ['key' => 'name_only', 'label' => 'نام بدون شماره'];
        $headers[] = ['key' => 'purchased_name_only', 'label' => 'نام بدون شماره دارای خرید', 'sortable' => false];
    } else {
        $headers[] = ['key' => 'telegram_linked', 'label' => 'تلگرام', 'sortable' => false];
    }
@endphp
<x-card class="border border-base-300">
    @if ($resellers)
        <p class="mb-4 text-sm text-base-content/60">مبلغ خرید نماینده نزد دناگیت بر اساس کانال ثبت‌شده سفارش است. سفارش‌های تاریخی با کانال نامشخص در این مبلغ لحاظ نمی‌شوند. رتبه بر اساس مبلغ خرید در نتایج فیلترشده است.</p>
    @endif
    <p class="mb-3 text-sm font-medium text-primary">تعداد نتایج: {{ \App\Support\PersianCalendar::number(number_format($rows->total())) }}</p>
    <x-table :headers="$headers" :rows="$rows" :sort-by="$sortBy" with-pagination>
        @scope('cell_name', $row, $resellers)
            <x-button :label="$row->name" :link="route($resellers ? 'crm.resellers.show' : 'crm.customers.show', ['id' => $row->id])" class="btn-ghost btn-sm text-primary" />
        @endscope
        @scope('cell_is_active', $row)
            <x-badge :value="$row->is_active ? 'فعال' : 'غیرفعال'" :class="$row->is_active ? 'badge-success badge-soft' : 'badge-error badge-soft'" />
        @endscope
        @scope('cell_telegram_linked', $row)
            <x-badge :value="$row->telegram_linked ? 'متصل' : 'متصل نیست'" class="badge-ghost" />
        @endscope
        @scope('cell_created_at', $row)
            {{ \App\Support\PersianCalendar::date(\Illuminate\Support\Carbon::parse($row->created_at, 'UTC')->timezone('Asia/Tehran')->format('Y-m-d')) }}
        @endscope
        @scope('cell_revenue_rials', $row)
            {{ \App\Support\PersianCalendar::number(number_format($row->revenue_rials / 10, $row->revenue_rials % 10 === 0 ? 0 : 1)) }} تومان
        @endscope
        @scope('cell_transactions', $row)
            {{ \App\Support\PersianCalendar::number(number_format($row->transactions)) }}
        @endscope
        @scope('cell_sales_rank', $row)
            {{ \App\Support\PersianCalendar::number(number_format($row->sales_rank)) }}
        @endscope
        @scope('cell_phones', $row)
            {{ \App\Support\PersianCalendar::number(number_format($row->phones)) }}
        @endscope
        @scope('cell_purchased_phones', $row)
            {{ \App\Support\PersianCalendar::number(number_format($row->purchased_phones)) }}
        @endscope
        @scope('cell_name_only', $row)
            {{ \App\Support\PersianCalendar::number(number_format($row->name_only)) }}
        @endscope
        @scope('cell_purchased_name_only', $row)
            {{ \App\Support\PersianCalendar::number(number_format($row->purchased_name_only)) }}
        @endscope
    </x-table>
    @if ($rows->isEmpty())
        <p class="py-4 text-sm text-base-content/60">موردی با فیلترهای فعلی پیدا نشد.</p>
    @endif
</x-card>
