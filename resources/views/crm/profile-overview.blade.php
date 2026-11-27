@php
    $summary = $data['summary'];
    $interaction = $data['interactions'];
    $trial = $data['trial'];
    $date = static fn ($value) => $value === null ? 'نامشخص' : \Illuminate\Support\Carbon::parse($value, 'UTC')->timezone('Asia/Tehran')->format('Y-m-d H:i');
@endphp
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

<div class="mt-6 border-t border-base-300 pt-5">
    <x-header title="رفتار و سابقه مشتری" subtitle="اطلاعات ثبت‌شده؛ نه میزان استفاده از سرویس" />
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-stat title="آخرین تعامل با بات فروش" :value="$date($interaction['lastSeenAt'])" description="مستقل از بازه گزارش" icon="o-clock" />
        <x-stat title="تعاملات ثبت‌شده" :value="$interaction['recordedCount'] === null ? 'نامشخص' : number_format($interaction['recordedCount'])" description="فقط از آغاز ثبت رویدادها" icon="o-chat-bubble-left-right" />
        <x-stat title="خریدهای معتبر در بازه" :value="number_format($summary['purchases'])" :description="'تمدید: '.number_format($summary['renewals'])" icon="o-shopping-bag" />
        <x-stat title="اولین خرید معتبر در بازه" :value="$date($summary['firstPurchaseAt'])" />
        <x-stat title="آخرین خرید معتبر در بازه" :value="$date($summary['lastPurchaseAt'])" />
        @if (! $reseller)
            <x-stat title="تست رایگان" :value="$trial['activatedAt'] !== null ? 'فعال‌سازی ثبت‌شده' : ($trial['claimed'] ? 'درخواست ثبت‌شده' : 'بدون درخواست ثبت‌شده')" />
        @endif
    </div>
    <p class="mt-4 text-sm text-base-content/60">اولین رویداد ثبت‌شده در سیستم: {{ $date($interaction['firstObservedAt']) }}. این زمان تضمین پوشش کامل تاریخچه نیست. تعداد تعاملات ثبت‌شده، تعداد کل تعاملات گذشته کاربر محسوب نمی‌شود.</p>
</div>
