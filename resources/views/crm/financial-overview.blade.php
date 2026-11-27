<x-card title="عملکرد مالی" subtitle="بر اساس بازه گزارش انتخاب‌شده" class="border border-base-300">
    <div class="crm-metric-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat title="مجموع واریزی‌های تأییدشده" :value="$data['cash'] === null ? 'نامشخص' : $money($data['cash']['cashInRials'])" icon="o-banknotes" class="crm-tone-green" />
        <x-stat title="کل خرید تسویه‌شده" :value="$money($data['totalRials'])" icon="o-shopping-bag" />
        <x-stat title="شارژ بانکی تأییدشده کیف پول" :value="$data['cash'] === null ? 'نامشخص' : $money($data['cash']['topupsRials'])" icon="o-wallet" class="crm-tone-blue" />
        <x-stat title="خرید با تسویه مستقیم بانکی" :value="$data['cash'] === null ? 'نامشخص' : $money($data['cash']['bankSalesRials'])" icon="o-credit-card" class="crm-tone-violet" />
    </div>
    <p class="mt-3 text-xs text-base-content/70">واریزی شامل شارژ بانکی معتبر کیف پول و تسویه مستقیم بانکی است؛ خرید از اعتبار کیف پول واریزی جدید نیست. این ارقام گردش زنده حساب بانکی یا سود نیستند.</p>
</x-card>
<x-card title="موجودی اعتبارات" subtitle="مانده فعلی دفتر کل؛ مستقل از بازه گزارش" class="border border-base-300">
    @if ($data['wallets'] === null)
        <x-alert icon="o-information-circle" class="alert-warning">موجودی کیف پول‌ها نامشخص است.</x-alert>
    @else
        <div class="crm-metric-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <x-stat title="کل موجودی کیف پول‌ها" :value="$money($data['wallets']['balanceRials'])" icon="o-wallet" class="crm-tone-green" />
            <x-stat title="اعتبار مشتریان مستقیم" :value="$money($data['wallets']['memberBalanceRials'])" icon="o-users" class="crm-tone-blue" />
            <x-stat title="اعتبار نمایندگان" :value="$money($data['wallets']['resellerBalanceRials'])" icon="o-user-group" class="crm-tone-violet" />
            @if ($data['wallets']['otherBalanceRials'] !== 0)
                <x-stat title="اعتبار سایر حساب‌ها" :value="$money($data['wallets']['otherBalanceRials'])" icon="o-identification" />
            @endif
        </div>
        <p class="mt-3 text-xs text-base-content/70">تفکیک بر اساس نقش فعلی حساب؛ مانده خالص حساب‌های فعال و غیرفعال در محاسبه لحاظ شده است.</p>
    @endif
</x-card>
