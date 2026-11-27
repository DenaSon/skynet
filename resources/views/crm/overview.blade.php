@include('crm.financial-overview')
<h2 class="text-base font-semibold text-dg-navy">وضعیت مشتریان و نمایندگان</h2>
<div class="crm-metric-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
    <x-card class="border border-base-300 crm-tone-blue"><x-stat title="مشتریان مستقیم" :value="\App\Support\PersianCalendar::number(number_format($data['customers']))" description="ثبت‌نام در بازه" icon="o-users" /></x-card>
    <x-card class="border border-base-300 crm-tone-violet"><x-stat title="نمایندگان" :value="\App\Support\PersianCalendar::number(number_format($data['resellers']))" description="ثبت‌نام در بازه" icon="o-user-group" /></x-card>
    <x-card class="border border-base-300 crm-tone-green"><x-stat title="حساب‌های فعال ثبت‌نام‌شده در بازه" :value="\App\Support\PersianCalendar::number(number_format($data['active']))" :description="'غیرفعال: '.\App\Support\PersianCalendar::number(number_format($data['inactive']))" icon="o-check-circle" /></x-card>
</div>
<x-card title="تفکیک خریدهای تسویه‌شده" class="border border-base-300">
    <div class="crm-metric-grid grid gap-3 md:grid-cols-3">
        @foreach (['direct' => 'کانال مستقیم', 'reseller' => 'کانال نمایندگی', 'unknown' => 'کانال تاریخی نامشخص'] as $key => $name)
            <x-stat :title="$name" :value="$money($data['channels'][$key]['revenueRials'])" :description="\App\Support\PersianCalendar::number(number_format($data['channels'][$key]['transactions'])).' تراکنش'" />
        @endforeach
    </div>
    <p class="mt-3 text-sm text-base-content/60">کانال نامشخص از نقش فعلی حساب استنباط نشده است.</p>
</x-card>
@include('crm.trends', ['trend' => $data['trend']])
<x-card title="نمایندگان برتر" subtitle="بر اساس خرید تسویه‌شده نزد دناگیت، نه فروش خرده‌فروشی نماینده" class="border border-base-300">
    <x-table :headers="[['key' => 'name', 'label' => 'نماینده'], ['key' => 'revenue_rials', 'label' => 'مبلغ'], ['key' => 'transactions', 'label' => 'تراکنش'], ['key' => 'phones', 'label' => 'شماره یکتا']]" :rows="$data['top']">
        @scope('cell_name', $row)
            <x-button :label="$row->name" :link="route('crm.resellers.show', ['id' => $row->id])" class="btn-ghost btn-sm text-primary" />
        @endscope
        @scope('cell_revenue_rials', $row)
            {{ \App\Support\PersianCalendar::number(number_format($row->revenue_rials / 10, $row->revenue_rials % 10 === 0 ? 0 : 1)) }} تومان
        @endscope
        @scope('cell_transactions', $row)
            {{ \App\Support\PersianCalendar::number(number_format($row->transactions)) }}
        @endscope
        @scope('cell_phones', $row)
            {{ \App\Support\PersianCalendar::number(number_format($row->phones)) }}
        @endscope
    </x-table>
    @if ($data['top']->isEmpty())
        <p class="py-4 text-sm text-base-content/60">در این بازه خرید تسویه‌شده‌ای برای رتبه‌بندی وجود ندارد.</p>
    @endif
</x-card>
<x-card title="اطلاعات مشتریان نمایندگان" class="border border-base-300">
    <div class="crm-metric-grid grid gap-3 sm:grid-cols-2">
    <x-stat title="شماره‌های تماس یکتا" :value="\App\Support\PersianCalendar::number(number_format($data['phones']))" :description="'دارای خرید: '.\App\Support\PersianCalendar::number(number_format($data['purchasedPhones']))" icon="o-phone" />
    <x-stat title="نام‌های بدون شماره" :value="\App\Support\PersianCalendar::number(number_format($data['nameOnly']))"  :description="'دارای خرید: '.\App\Support\PersianCalendar::number(number_format($data['purchasedNameOnly']))" icon="o-identification" />
    </div>
    <p class="mt-3 text-xs text-base-content/60">شماره‌ها در محدوده هر نماینده یکتا هستند؛ نام‌های بدون شماره هویت یکتای قطعی ندارند.</p>
</x-card>
<p class="text-xs text-base-content/60">زمان محاسبه به وقت تهران: <bdi dir="ltr">{{ \App\Support\PersianCalendar::localDateTime($data['generatedAt']) }}</bdi></p>
