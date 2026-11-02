{{-- Operational numbers and service records are fixed Demo fixtures; only published plan prices are live. --}}
@php
    $toman = static fn (int $rials): string => number_format(intdiv($rials, 10)).($rials % 10 === 0 ? '' : '.'.($rials % 10));
@endphp
@component('live.layout')
    <x-live.shell section="overview" :demo="true">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs leading-6 text-[#617380]">آمار و سرویس‌ها نمایشی‌اند؛ تعرفه‌ها به‌روز هستند.</p>
            <form method="POST" action="{{ route('denagate-live.demo.exit') }}">
                @csrf
                <button class="dg-btn-secondary" type="submit">خروج از دمو</button>
            </form>
        </div>

        <section x-show="demoSection === 'overview'" aria-label="نمای کلی نمونه">
            <section class="dg-card-accent relative overflow-hidden bg-gradient-to-bl from-[#E9F8F3] via-white to-white p-6 md:p-10">
                <div class="relative z-10">
                    <span class="mb-4 inline-flex items-center gap-2 rounded-full border border-[#CCEDE4] bg-white/80 px-3 py-1.5 text-xs font-semibold text-[#087F73]">
                        <span class="h-2 w-2 rounded-full bg-[#14B8A6]" aria-hidden="true"></span>
                        پنل نمایندگی
                    </span>
                    <h1 class="max-w-3xl break-words text-[25px] font-extrabold leading-[1.75] tracking-tight text-[#1B2935] md:text-4xl">
                        به پنل نمایندگی خوش آمدید
                    </h1>
                    <p class="mt-2 max-w-xl text-sm leading-8 text-[#617380]">اعتبار، سرویس‌ها و فروش در یک نگاه</p>
                    <button type="button" class="dg-btn-primary mt-6 min-h-12 px-6 py-3"
                        x-on:click="demoSection = 'shop'">مشاهده پلن‌ها <span class="mr-2" aria-hidden="true">←</span></button>
                </div>
                <div class="pointer-events-none absolute -bottom-24 -left-24 h-60 w-60 rounded-full bg-[#14B8A6]/10 blur-3xl" aria-hidden="true"></div>
            </section>

            <h2 class="dg-heading mb-5 mt-8">نمای کلی</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 md:gap-5">
                <section class="dg-card p-5 md:p-7">
                    <p class="text-xs font-semibold text-[#71818B]">اعتبار قابل استفاده</p>
                    <p class="dg-stat mt-3" dir="ltr">۲٬۵۰۰٬۰۰۰ <span class="text-xs font-normal text-[#687987]">تومان</span></p>
                    <button type="button" class="dg-btn-secondary mt-4 w-full"
                        x-on:click="demoSection = 'credit'">جزئیات اعتبار ←</button>
                </section>
                <section class="dg-card p-5 md:p-7">
                    <p class="text-xs font-semibold text-[#71818B]">سرویس‌های ثبت‌شده</p>
                    <p class="dg-stat mt-3">۱۸</p>
                    <p class="mt-1 text-xs text-[#71818B]">فعال در سوابق: ۱۵</p>
                    <button type="button" class="dg-btn-secondary mt-4 w-full"
                        x-on:click="demoSection = 'services'">فهرست سرویس‌ها ←</button>
                </section>
                <section class="dg-card p-5 md:p-7">
                    <p class="text-xs font-semibold text-[#71818B]">فروش تسویه‌شده · ۳۰ روز اخیر</p>
                    <p class="dg-stat mt-3" dir="ltr">۹٬۲۵۰٬۰۰۰ <span class="text-xs font-normal text-[#687987]">تومان</span></p>
                    <p class="mt-1 text-xs text-[#71818B]">۳۶ تراکنش تسویه‌شده</p>
                    <button type="button" class="dg-btn-secondary mt-4 w-full"
                        x-on:click="demoSection = 'reports'">گزارش فروش ←</button>
                </section>
            </div>
        </section>

        <section x-cloak x-show="demoSection === 'services'" aria-label="سرویس‌های نمونه">
            <div class="mb-6">
                <h1 class="dg-heading">سرویس‌های من</h1>
                <p class="mt-2 text-sm text-[#71818B]">فهرست سرویس‌ها</p>
            </div>
            <div class="mb-5 grid grid-cols-2 gap-3">
                <section class="dg-card p-4">
                    <p class="text-xs text-[#71818B]">کل سرویس‌ها</p>
                    <p class="dg-stat mt-2">۱۸</p>
                </section>
                <section class="dg-card p-4">
                    <p class="text-xs text-[#71818B]">فعال در سوابق داخلی</p>
                    <p class="dg-stat mt-2">۱۵</p>
                </section>
            </div>
            <div class="space-y-3">
                @foreach ([
                    ['مشتری نمونه ۱', '۲۰ گیگابایت', '۳۰ روز', 'فعال در سوابق', 'neutral'],
                    ['مشتری نمونه ۲', '۵۰ گیگابایت', '۳۰ روز', 'فعال در سوابق', 'neutral'],
                    ['مشتری نمونه ۳', '۱۰ گیگابایت', '۱۵ روز', 'نیازمند پیگیری', 'warning'],
                ] as [$customer, $traffic, $duration, $status, $tone])
                    <article class="dg-card p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="break-words text-base font-extrabold text-[#1B2935]">{{ $customer }}</h2>
                                <p class="mt-1 break-words text-xs leading-6 text-[#71818B]">سرویس اشتراک · {{ $duration }} · {{ $traffic }}</p>
                            </div>
                            <span class="dg-status" data-tone="{{ $tone }}">{{ $status }}</span>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section x-cloak x-show="demoSection === 'credit'" aria-label="اعتبار نمونه">
            <div class="mb-6">
                <h1 class="dg-heading">اعتبار من</h1>
                <p class="mt-2 text-sm text-[#71818B]">موجودی و آخرین درخواست‌های شارژ</p>
            </div>
            <section class="dg-card-accent bg-gradient-to-bl from-[#E7F8F3] to-white p-7 md:p-9">
                <p class="text-sm font-semibold text-[#087F73]">اعتبار قابل استفاده</p>
                <p class="dg-stat mt-4 text-3xl" dir="ltr">۲٬۵۰۰٬۰۰۰ <span class="text-sm font-medium text-[#687987]">تومان</span></p>
            </section>
            <section class="dg-card mt-5 p-5 md:p-7">
                <h2 class="mb-3 text-base font-extrabold">درخواست‌های اخیر شارژ</h2>
                @foreach ([
                    ['۱٬۰۰۰٬۰۰۰', 'تأییدشده', 'success'],
                    ['۵۰۰٬۰۰۰', 'در انتظار بررسی واریز', 'warning'],
                ] as [$amount, $status, $tone])
                    <div class="flex min-h-12 flex-wrap items-center justify-between gap-2 border-b border-[#F0F2F4] py-2.5 last:border-b-0">
                        <p class="text-sm font-bold text-[#1B2935]">{{ $amount }} تومان</p>
                        <span class="dg-status" data-tone="{{ $tone }}">{{ $status }}</span>
                    </div>
                @endforeach
            </section>
        </section>

        <section x-cloak x-show="demoSection === 'reports'" aria-label="گزارش‌های نمونه">
            <section aria-labelledby="demo-report-title">
                <div class="mb-6 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div class="min-w-0">
                        <p class="mb-1 text-xs font-semibold text-[#087F73]">عملکرد نمایندگی</p>
                        <h1 id="demo-report-title" class="dg-heading">گزارش فروش</h1>
                        <p class="mt-2 text-sm leading-7 text-[#617380]">مرور فروش و وضعیت سرویس‌ها</p>
                    </div>
                    <span class="dg-status self-start" data-tone="success">بازه ۳۰ روزه</span>
                </div>
                <section class="dg-card-accent relative overflow-hidden bg-gradient-to-bl from-[#E4F8F1] via-[#F4FBF8] to-white p-6 sm:p-8" aria-labelledby="demo-revenue-title">
                    <div class="pointer-events-none absolute -left-14 -top-20 h-52 w-52 rounded-full border-[24px] border-[#C9ECE2]/60" aria-hidden="true"></div>
                    <div class="relative z-10 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-[#087F73]">خلاصه عملکرد فروش</p>
                            <h2 id="demo-revenue-title" class="mt-2 text-base font-extrabold text-[#1B2935]">مجموع فروش تسویه‌شده</h2>
                            <p class="dg-stat mt-3 block" dir="ltr">۹٬۲۵۰٬۰۰۰</p>
                            <p class="mt-1 text-sm font-semibold text-[#536C76]">تومان</p>
                        </div>
                    </div>
                </section>
                <section class="mt-5" aria-label="شاخص‌های فروش و حساب">
                    <div class="grid grid-cols-3 gap-2.5 sm:gap-3">
                        @foreach ([
                            ['خریدهای جدید', 24, true],
                            ['تمدیدها', 12, true],
                            ['تراکنش‌های تأییدشده', 36, true],
                            ['سفارش‌های در انتظار پرداخت', 3, false],
                            ['کل سرویس‌های ثبت‌شده', 18, false],
                            ['سرویس‌های دارای نام', 10, false],
                        ] as [$label, $value, $historical])
                            <x-live.report-metric :label="$label" :value="$value" :historical="$historical" />
                        @endforeach
                    </div>
                </section>
            </section>
        </section>

        <section x-cloak x-show="demoSection === 'shop'" aria-label="پلن‌های منتشرشده نمایندگی">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="dg-heading">پلن‌های نمایندگی</h1>
                    <p class="mt-2 text-sm leading-7 text-[#71818B]">تعرفه‌های به‌روز نمایندگی</p>
                </div>
                <a href="{{ route('denagate-live.demo.preview', ['section' => 'shop']) }}"
                    class="dg-btn-secondary inline-flex min-h-11 items-center justify-center px-4 py-2 text-xs">به‌روزرسانی قیمت‌ها</a>
            </div>

            @if (count($plans) === 0)
                <section class="dg-empty">در حال حاضر پلنی برای نمایش موجود نیست.</section>
            @else
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ($plans as $plan)
                        <article class="dg-card flex flex-col p-4 sm:p-5">
                            <h2 class="break-words text-base font-extrabold text-[#1B2935]">{{ $plan['name'] }}</h2>
                            <p class="mt-2 text-xs leading-7 text-[#71818B]">{{ number_format($plan['durationDays']) }} روز · {{ $plan['trafficGb'] === null ? 'حجم نامحدود' : number_format($plan['trafficGb']).' گیگابایت' }}</p>
                            <p class="dg-stat mt-4 text-[#087F73]" dir="ltr">{{ $toman($plan['resellerRials']) }} <span class="text-xs font-medium text-[#71818B]">تومان</span></p>
                            <p class="mt-1 text-xs text-[#71818B]">با تخفیف نمایندگی {{ $plan['discountPercent'] }}٪</p>
                            <div class="mt-4 flex items-center justify-between gap-3 border-t border-[#E7ECEF] pt-3 text-xs text-[#71818B]">
                                <span>قیمت عمومی</span>
                                <span dir="ltr" class="font-semibold text-[#1B2935]">{{ $toman($plan['publicRials']) }} تومان</span>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($botUrl !== null)
            <div class="mt-8 flex justify-center border-t border-[#E7ECEF] pt-5">
                <a href="{{ $botUrl }}" target="_blank" rel="noopener noreferrer"
                    x-on:click="const tg = window.Telegram?.WebApp; if (tg && tg.platform !== 'unknown' && typeof tg.openTelegramLink === 'function') { $event.preventDefault(); tg.openTelegramLink($el.href); }"
                    class="dg-btn-primary inline-flex min-h-11 items-center justify-center px-5 py-2.5">
                    🧩 درخواست نمایندگی
                </a>
            </div>
        @else
            <p class="mt-8 border-t border-[#E7ECEF] pt-5 text-center text-xs text-[#617380]">درخواست نمایندگی از بات فروش</p>
        @endif
    </x-live.shell>
@endcomponent
