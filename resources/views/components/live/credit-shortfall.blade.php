@props(['amount', 'url'])
<div class="dg-card-accent mt-5 p-4" role="status">
    <p class="text-sm font-bold">کسری اعتبار: {{ \App\Support\PersianCalendar::number(number_format($amount / 10, $amount % 10 === 0 ? 0 : 1)) }} تومان</p>
    @if ($url !== null)
        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="dg-btn-primary mt-3"
            x-on:click="const tg = window.Telegram?.WebApp; if (tg && tg.platform !== 'unknown' && typeof tg.openTelegramLink === 'function') { $event.preventDefault(); tg.openTelegramLink($el.href); }">➕ افزایش اعتبار</a>
    @endif
    <p class="mt-3 text-xs leading-7">پس از تأیید شارژ، پرداخت همین سفارش را دوباره انتخاب کنید. اگر برنامه بسته شد، سفارش خرید در «سفارش‌های اخیر من» و تمدید در جزئیات همان سرویس در دسترس است.</p>
</div>
