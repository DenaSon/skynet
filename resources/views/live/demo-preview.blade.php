{{-- All figures and records below are fictitious. No real business data is read. --}}
@php
    $toman = static fn (int $rials): string => number_format(intdiv($rials, 10)).($rials % 10 === 0 ? '' : '.'.($rials % 10));
@endphp
@component('live.layout')
    <x-live.shell section="overview" :demo="true">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <span class="dg-status" data-tone="neutral">👀 حالت دمو · اطلاعات نمونه</span>
                <p class="mt-2 text-xs leading-6 text-[#617380]">آمار و سرویس‌ها نمونه‌اند؛ قیمت‌های منتشرشده پلن‌ها به‌روز هستند.</p>
            </div>
            <form method="POST" action="{{ route('denagate-live.demo.exit') }}">
                @csrf
                <button class="dg-btn-secondary" type="submit">خروج از دمو</button>
            </form>
        </div>

        <section x-show="demoSection === 'overview'" aria-label="نمای کلی نمونه">
            <div class="dg-card-accent bg-gradient-to-bl from-[#E9F8F3] via-white to-white p-6 md:p-10">
                <span class="dg-status" data-tone="success">آشنایی با پنل فروش</span>
                <h1 class="dg-heading mt-4">فروش و مدیریت سرویس‌ها، در یک نگاه</h1>
                <p class="mt-2 text-sm leading-8 text-[#617380]">تمام اطلاعات این صفحه نمونه هستند و به هیچ حساب واقعی متصل نیستند.</p>
                <button type="button" class="dg-btn-primary mt-5" x-on:click="demoSection = 'shop'">مشاهده بخش پلن‌ها ←</button>
            </div>
            <h2 class="dg-heading mt-7 mb-4">نمای کلی · نمونه</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <article class="dg-card">
                    <p class="text-xs text-[#617380]">اعتبار نمونه</p>
                    <p class="dg-stat mt-3">۲٬۵۰۰٬۰۰۰ <span class="text-xs font-normal">تومان</span></p>
                    <button type="button" class="dg-btn-secondary mt-4 w-full" x-on:click="demoSection = 'credit'">جزئیات نمونه ←</button>
                </article>
                <article class="dg-card">
                    <p class="text-xs text-[#617380]">سرویس‌های نمونه</p>
                    <p class="dg-stat mt-3">۱۸</p>
                    <button type="button" class="dg-btn-secondary mt-4 w-full" x-on:click="demoSection = 'services'">مشاهده سرویس‌ها ←</button>
                </article>
                <article class="dg-card">
                    <p class="text-xs text-[#617380]">فروش نمونه · ۳۰ روز</p>
                    <p class="dg-stat mt-3">۹٬۲۵۰٬۰۰۰ <span class="text-xs font-normal">تومان</span></p>
                    <button type="button" class="dg-btn-secondary mt-4 w-full" x-on:click="demoSection = 'reports'">گزارش نمونه ←</button>
                </article>
            </div>
        </section>

        <section x-cloak x-show="demoSection === 'services'" aria-label="سرویس‌های نمونه">
            <h1 class="dg-heading mb-2">سرویس‌ها · نمونه</h1>
            <p class="mb-5 text-sm leading-7 text-[#617380]">این نام‌ها و سرویس‌ها ساختگی هستند؛ هیچ اطلاعات مشتری واقعی نمایش داده نمی‌شود.</p>
            <div class="space-y-3">
                @foreach ([
                    ['مشتری نمونه ۱', '۲۰ گیگابایت · ۳۰ روز', 'فعال'],
                    ['مشتری نمونه ۲', '۵۰ گیگابایت · ۳۰ روز', 'فعال'],
                    ['مشتری نمونه ۳', '۱۰ گیگابایت · ۱۵ روز', 'منقضی'],
                ] as [$customer, $description, $state])
                    <article class="dg-card flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="font-bold">{{ $customer }}</h2>
                            <p class="mt-1 text-xs text-[#617380]">{{ $description }}</p>
                        </div>
                        <span class="dg-status" data-tone="{{ $state === 'فعال' ? 'success' : 'warning' }}">{{ $state }} · نمونه</span>
                    </article>
                @endforeach
            </div>
        </section>

        <section x-cloak x-show="demoSection === 'credit'" aria-label="اعتبار نمونه">
            <h1 class="dg-heading mb-5">اعتبار من · نمونه</h1>
            <div class="dg-card-accent">
                <p class="text-xs text-[#617380]">اعتبار قابل استفاده · نمونه</p>
                <p class="dg-stat mt-3">۲٬۵۰۰٬۰۰۰ <span class="text-xs font-normal">تومان</span></p>
                <p class="mt-4 text-xs leading-7 text-[#617380]">در این پیش‌نمایش هیچ کیف پول یا امکان شارژ و برداشت وجود ندارد.</p>
            </div>
            <h2 class="dg-heading mt-8 mb-4">تراکنش‌های نمونه</h2>
            <div class="dg-card space-y-4 text-sm">
                <div class="flex justify-between gap-3"><span>شارژ آزمایشی</span><span dir="ltr" class="font-bold">+۱٬۰۰۰٬۰۰۰ تومان</span></div>
                <div class="border-t border-[#E7ECEF] pt-4 flex justify-between gap-3"><span>خرید نمونه</span><span dir="ltr" class="font-bold">−۱۹۵٬۰۰۰ تومان</span></div>
            </div>
        </section>

        <section x-cloak x-show="demoSection === 'reports'" aria-label="گزارش‌های نمونه">
            <h1 class="dg-heading mb-2">گزارش فروش · نمونه</h1>
            <p class="mb-5 text-sm leading-7 text-[#617380]">ارقام و آمار زیر تنها برای معرفی امکانات پنل هستند.</p>
            <div class="dg-card-accent">
                <p class="text-sm font-bold">مجموع فروش نمونه · ۳۰ روز</p>
                <p class="dg-stat mt-3">۹٬۲۵۰٬۰۰۰ <span class="text-xs font-normal">تومان</span></p>
            </div>
            <div class="mt-4 grid grid-cols-3 gap-2.5">
                @foreach ([['خریدهای جدید', '۲۴'], ['تمدیدها', '۱۲'], ['مشتریان نمونه', '۱۸'], ['تراکنش‌ها', '۳۶'], ['سرویس‌های فعال', '۱۵'], ['در انتظار', '۳']] as [$label, $value])
                    <div class="dg-card min-w-0 p-3">
                        <p class="text-[11px] leading-5 text-[#617380]">{{ $label }}</p>
                        <p class="mt-2 text-lg font-extrabold">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section x-cloak x-show="demoSection === 'shop'" aria-label="پلن‌های منتشرشده نمایندگی">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="dg-heading">پلن‌های نمایندگی</h1>
                    <p class="mt-2 text-xs leading-7 text-[#617380]">قیمت‌های واقعیِ منتشرشده در زمان نمایش؛ سفارش یا رزرو قیمت نیست.</p>
                </div>
                <a href="{{ route('denagate-live.demo.preview', ['section' => 'shop']) }}"
                   class="dg-btn-secondary inline-flex min-h-11 items-center justify-center px-4 py-2 text-xs">به‌روزرسانی قیمت‌ها</a>
            </div>

            @if (count($plans) === 0)
                <div class="dg-empty">
                    <p class="font-semibold">فعلاً تعرفه قابل نمایشی وجود ندارد.</p>
                    <p>پس از انتشار پلن‌های آماده، قیمت‌های معتبر در این بخش نمایش داده می‌شوند.</p>
                </div>
            @else
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($plans as $plan)
                        <article class="dg-card min-w-0 space-y-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h2 class="font-extrabold break-words">{{ $plan['name'] }}</h2>
                                    <p class="mt-1 text-xs text-[#617380]">
                                        {{ $plan['trafficGb'] === null ? 'حجم نامحدود' : number_format($plan['trafficGb']).' گیگابایت' }}
                                        · {{ number_format($plan['durationDays']) }} روز
                                    </p>
                                </div>
                                <span class="dg-status shrink-0" data-tone="success">قیمت به‌روز</span>
                            </div>
                            <div class="border-t border-[#E7ECEF] pt-3 text-sm">
                                <div class="flex justify-between gap-3">
                                    <span class="text-[#617380]">قیمت عمومی</span>
                                    <span class="font-semibold" dir="ltr">{{ $toman($plan['publicRials']) }} تومان</span>
                                </div>
                                <div class="mt-2 flex justify-between gap-3">
                                    <span class="text-[#617380]">تخفیف نمایندگی</span>
                                    <span class="font-bold text-[#087F73]">{{ $plan['discountPercent'] }}٪</span>
                                </div>
                                <div class="mt-3 flex items-center justify-between gap-3 border-t border-[#E7ECEF] pt-3">
                                    <strong>قیمت نماینده</strong>
                                    <strong class="text-lg text-[#087F73]" dir="ltr">{{ $toman($plan['resellerRials']) }} تومان</strong>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
            <p class="mt-5 text-xs leading-7 text-[#617380]">پلن‌های سفارشی در این نسخه صرفاً معرفی می‌شوند؛ قیمت و شرایط نهایی خرید در پنل واقعی دوباره محاسبه می‌شوند.</p>
        </section>

        @if ($botUrl !== null)
            <div class="mt-8 flex flex-col items-center gap-2 border-t border-[#E7ECEF] pt-6">
                <a href="{{ $botUrl }}" target="_blank" rel="noopener noreferrer"
                   x-on:click="const tg = window.Telegram?.WebApp; if (tg && tg.platform !== 'unknown' && typeof tg.openTelegramLink === 'function') { $event.preventDefault(); tg.openTelegramLink($el.href); }"
                   class="dg-btn-primary inline-flex min-h-11 items-center justify-center px-5 py-2.5">
                    🧩 درخواست نمایندگی
                </a>
                <p class="text-xs text-[#617380]">درخواست و شرایط فعال‌سازی طبق قوانین فعلی بات فروش بررسی می‌شوند.</p>
            </div>
        @else
            <p class="mt-8 border-t border-[#E7ECEF] pt-5 text-center text-xs leading-7 text-[#617380]">برای درخواست نمایندگی از بخش حساب کاربری بات فروش اقدام کنید.</p>
        @endif
    </x-live.shell>
@endcomponent
