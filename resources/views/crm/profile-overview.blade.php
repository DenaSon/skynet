@php($summary = $data['summary'])
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <x-stat title="تعداد سفارش‌ها" :value="number_format($summary['orders'])" :description="'در انتظار پرداخت: '.number_format($summary['pending'])" icon="o-shopping-bag" />
    <x-stat title="تراکنش‌های تسویه‌شده" :value="number_format($summary['transactions'])" icon="o-banknotes" />
    <x-stat title="مجموع خرید تسویه‌شده" :value="$money($summary['direct'] + $summary['reseller'] + $summary['unknown'])" icon="o-currency-dollar" />
    <x-stat title="کانال مستقیم" :value="$money($summary['direct'])" />
    <x-stat title="کانال نمایندگی" :value="$money($summary['reseller'])" />
    <x-stat title="کانال تاریخی نامشخص" :value="$money($summary['unknown'])" />
    @if ($data['walletState'] === 'available')
        <x-stat title="موجودی فعلی کیف پول" :value="$money($data['wallet'])" description="مستقل از بازه گزارش" icon="o-wallet" />
    @elseif ($data['walletState'] === 'unavailable')
        <x-alert class="alert-warning">موجودی کیف پول نامشخص است.</x-alert>
    @endif
    @if ($reseller)
        <x-stat title="شماره‌های تماس یکتا" :value="number_format($summary['phones'])" :description="'دارای خرید: '.number_format($summary['purchasedPhones'])" icon="o-phone" />
        <x-stat title="نام‌های بدون شماره" :value="number_format($summary['nameOnly'])" icon="o-identification" />
        <x-stat title="سفارش فاقد مشخصات مشتری" :value="number_format($summary['noContact'])" />
    @endif
</div>
<p class="mt-4 text-sm text-base-content/60">مبالغ بر اساس زمان تسویه معتبر و سفارش‌ها بر اساس تاریخ ثبت سفارش محاسبه می‌شوند. کانال تاریخی نامشخص از نقش فعلی حساب استنباط نمی‌شود.</p>
@if ($reseller)
    <p class="mt-2 text-sm text-base-content/60">خرید نماینده نزد دناگیت معادل سود یا فروش خرده‌فروشی او نیست. شماره یکتا نیز لزوماً به معنی شخص یکتا نیست.</p>
@endif
