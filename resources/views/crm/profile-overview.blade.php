@php
    $summary = $data['summary'];
    $interaction = $data['interactions'];
    $trial = $data['trial'];
    $date = static fn ($value) => $value === null ? 'نامشخص' : \App\Support\PersianCalendar::utcDateTime((string) $value);
@endphp
<div class="crm-metric-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
    <x-stat title="تعداد سفارش‌ها" :value="\App\Support\PersianCalendar::number(number_format($summary['orders']))" :description="'در انتظار پرداخت: '.\App\Support\PersianCalendar::number(number_format($summary['pending']))" icon="o-shopping-bag" class="crm-tone-violet" />
    <x-stat title="تراکنش‌های تسویه‌شده" :value="\App\Support\PersianCalendar::number(number_format($summary['transactions']))" icon="o-banknotes" class="crm-tone-green" />
    <x-stat title="مجموع خرید تسویه‌شده" :value="$money($summary['direct'] + $summary['reseller'] + $summary['unknown'])" icon="o-currency-dollar" class="crm-tone-green" />
    <x-stat title="کانال مستقیم" :value="$money($summary['direct'])" />
    <x-stat title="کانال نمایندگی" :value="$money($summary['reseller'])" />
    <x-stat title="کانال تاریخی نامشخص" :value="$money($summary['unknown'])" />
    @if ($data['walletState'] === 'available')
        <x-stat title="موجودی فعلی کیف پول" :value="$money($data['wallet'])" description="مستقل از بازه گزارش" icon="o-wallet" class="crm-tone-green" />
    @elseif ($data['walletState'] === 'unavailable')
        <x-alert class="alert-warning">موجودی کیف پول نامشخص است.</x-alert>
    @endif
    @if ($reseller)
        <x-stat title="شماره‌های تماس یکتا" :value="\App\Support\PersianCalendar::number(number_format($summary['phones']))" :description="'دارای خرید: '.\App\Support\PersianCalendar::number(number_format($summary['purchasedPhones']))" icon="o-phone" class="crm-tone-violet" />
        <x-stat title="نام‌های بدون شماره" :value="\App\Support\PersianCalendar::number(number_format($summary['nameOnly']))" icon="o-identification" class="crm-tone-amber" />
        <x-stat title="سفارش فاقد مشخصات مشتری" :value="\App\Support\PersianCalendar::number(number_format($summary['noContact']))" />
    @endif
</div>
<p class="mt-4 text-sm text-base-content/60">مبالغ بر اساس زمان تسویه معتبر و سفارش‌ها بر اساس تاریخ ثبت سفارش محاسبه می‌شوند. کانال تاریخی نامشخص از نقش فعلی حساب استنباط نمی‌شود.</p>
@if ($reseller)
    <p class="mt-2 text-sm text-base-content/60">خرید نماینده نزد دناگیت معادل سود یا فروش خرده‌فروشی او نیست. شماره یکتا نیز لزوماً به معنی شخص یکتا نیست.</p>
@endif

<div class="mt-6 border-t border-base-300 pt-5">
    <x-header title="رفتار و سابقه مشتری" subtitle="اطلاعات ثبت‌شده؛ نه میزان استفاده از سرویس" />
    <div class="crm-metric-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <x-stat title="آخرین تعامل با بات فروش" :value="$date($interaction['lastSeenAt'])" description="مستقل از بازه گزارش" icon="o-clock" class="crm-tone-blue" />
        <x-stat title="تعاملات ثبت‌شده" :value="$interaction['recordedCount'] === null ? 'نامشخص' : \App\Support\PersianCalendar::number(number_format($interaction['recordedCount']))" description="فقط از آغاز ثبت رویدادها" icon="o-chat-bubble-left-right" class="crm-tone-blue" />
        <x-stat title="خریدهای معتبر در بازه" :value="\App\Support\PersianCalendar::number(number_format($summary['purchases']))" :description="'تمدید: '.\App\Support\PersianCalendar::number(number_format($summary['renewals']))" icon="o-shopping-bag" class="crm-tone-violet" />
        <x-stat title="اولین خرید معتبر در بازه" :value="$date($summary['firstPurchaseAt'])" />
        <x-stat title="آخرین خرید معتبر در بازه" :value="$date($summary['lastPurchaseAt'])" />
        @if (! $reseller)
            <x-stat title="تست رایگان" :value="$trial['activatedAt'] !== null ? 'فعال‌سازی ثبت‌شده' : ($trial['claimed'] ? 'درخواست ثبت‌شده' : 'بدون درخواست ثبت‌شده')" />
        @endif
    </div>
    <p class="mt-4 text-sm text-base-content/60">اولین رویداد ثبت‌شده در سیستم: <bdi dir="ltr">{{ $date($interaction['firstObservedAt']) }}</bdi>. این زمان تضمین پوشش کامل تاریخچه نیست. تعداد تعاملات ثبت‌شده، تعداد کل تعاملات گذشته کاربر محسوب نمی‌شود.</p>
</div>
