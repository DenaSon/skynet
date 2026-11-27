<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <x-card class="border border-base-300"><x-stat title="مشتریان مستقیم" :value="number_format($data['customers'])" description="ثبت‌نام در بازه" icon="o-users" /></x-card>
    <x-card class="border border-base-300"><x-stat title="نمایندگان" :value="number_format($data['resellers'])" description="ثبت‌نام در بازه" icon="o-user-group" /></x-card>
    <x-card class="border border-base-300"><x-stat title="حساب‌های فعال" :value="number_format($data['active'])" :description="'غیرفعال: '.number_format($data['inactive'])" icon="o-check-circle" /></x-card>
    <x-card class="border border-base-300"><x-stat title="خرید تسویه‌شده" :value="$money($data['totalRials'])" icon="o-banknotes" /></x-card>
    <x-card class="border border-base-300"><x-stat title="شماره‌های تماس یکتا" :value="number_format($data['phones'])"  :description="'دارای خرید: '.number_format($data['purchasedPhones'])" icon="o-phone" /></x-card>
    <x-card class="border border-base-300"><x-stat title="نام‌های بدون شماره" :value="number_format($data['nameOnly'])"  :description="'دارای خرید: '.number_format($data['purchasedNameOnly'])" icon="o-identification" /></x-card>
</div>
<p class="text-xs text-base-content/60">شماره‌ها در محدوده هر نماینده یکتا هستند؛ نام‌های بدون شماره هویت یکتای قطعی ندارند.</p>
<x-card title="تفکیک خریدهای تسویه‌شده" class="border border-base-300">
    <div class="grid gap-3 md:grid-cols-3">
        @foreach (['direct' => 'کانال مستقیم', 'reseller' => 'کانال نمایندگی', 'unknown' => 'کانال تاریخی نامشخص'] as $key => $name)
            <x-stat :title="$name" :value="$money($data['channels'][$key]['revenueRials'])" :description="number_format($data['channels'][$key]['transactions']).' تراکنش'" />
        @endforeach
    </div>
    <p class="mt-3 text-sm text-base-content/60">کانال نامشخص از نقش فعلی حساب استنباط نشده است.</p>
</x-card>
<x-card title="نمایندگان برتر" subtitle="بر اساس خرید تسویه‌شده نزد دناگیت، نه فروش خرده‌فروشی نماینده" class="border border-base-300">
    <x-table :headers="[['key' => 'name', 'label' => 'نماینده'], ['key' => 'revenue_rials', 'label' => 'مبلغ'], ['key' => 'transactions', 'label' => 'تراکنش'], ['key' => 'phones', 'label' => 'شماره یکتا']]" :rows="$data['top']">
        @scope('cell_revenue_rials', $row)
            {{ number_format($row->revenue_rials / 10, $row->revenue_rials % 10 === 0 ? 0 : 1) }} تومان
        @endscope
    </x-table>
    @if ($data['top']->isEmpty())
        <p class="py-4 text-sm text-base-content/60">در این بازه خرید تسویه‌شده‌ای برای رتبه‌بندی وجود ندارد.</p>
    @endif
</x-card>
<p class="text-xs text-base-content/60">زمان محاسبه به وقت تهران: {{ $data['generatedAt'] }}</p>
