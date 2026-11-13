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
                <section class="dg-card flex min-h-32 flex-col items-center justify-between gap-3 p-4 text-center">
                    <p class="text-xs font-semibold leading-5 text-[#536C76]">کل سرویس‌ها</p>
                    <p class="dg-stat block w-full text-center text-2xl sm:text-3xl">۱۸</p>
                </section>
                <section class="dg-card flex min-h-32 flex-col items-center justify-between gap-3 p-4 text-center">
                    <p class="text-xs font-semibold leading-5 text-[#536C76]">فعال در سوابق داخلی</p>
                    <p class="dg-stat block w-full text-center text-2xl sm:text-3xl">۱۵</p>
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
                <div class="grid grid-cols-1 justify-items-center gap-3 sm:grid-cols-2 sm:gap-4">
                    @foreach ($plans as $plan)
                        <article class="dg-card dg-plan-card flex flex-col p-4 sm:p-5" data-tone="{{ ['teal', 'blue', 'sand'][$loop->index % 3] }}">
                            <h2 class="break-words text-base font-extrabold text-[#1B2935]">{{ $plan['name'] }}</h2>
                            <p class="mt-2 text-sm font-extrabold leading-7 text-[#354C58]">{{ number_format($plan['durationDays']) }} روز · {{ $plan['trafficGb'] === null ? 'حجم نامحدود' : number_format($plan['trafficGb']).' گیگابایت' }}</p>
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

            {{-- This is an illustrative UI sample, never a reseller quote or an order form. --}}
            <section class="dg-card mt-6 p-5 sm:p-7" aria-label="پلن‌ساز آزمایشی"
                x-data="{ demoBuilderStep: 'volume', demoGb: 75, demoDays: 30 }">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-extrabold text-[#1B2935]">🧩 پلن‌ساز نمایندگی</h2>
                    <span class="dg-status" data-tone="neutral">نمونه تعاملی</span>
                </div>
                <p class="mt-2 text-xs leading-7 text-[#617380]">مراحل انتخاب را امتحان کنید. قیمت و سفارش واقعی فقط در حساب نماینده قابل مشاهده‌اند.</p>

                <div x-show="demoBuilderStep === 'volume'">
                    <p class="mt-6 text-xs font-bold text-[#087F73]">مرحله ۱ از ۲ · حجم سرویس</p>
                    <div class="mt-4 flex items-center justify-between gap-3">
                        <label for="demo-builder-gb" class="text-sm font-bold text-[#1B2935]">حجم موردنیاز را انتخاب کنید</label>
                        <span class="shrink-0 text-lg font-extrabold tabular-nums text-[#087F73]"
                            x-text="demoGb + ' گیگابایت'">۷۵ گیگابایت</span>
                    </div>
                    <input id="demo-builder-gb" type="range" name="demo_volume_gb" min="1" max="500" step="1"
                        x-model.number="demoGb" x-bind:aria-valuetext="demoGb + ' گیگابایت'"
                        aria-describedby="demo-builder-gb-hint"
                        class="mt-4 h-11 w-full cursor-pointer accent-[#14B8A6] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#087F73]" />
                    <p id="demo-builder-gb-hint" class="mt-1 flex justify-between gap-3 text-xs text-[#71818B]">
                        <span>۱ گیگ</span><span>۵۰۰ گیگ</span>
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2" role="group" aria-label="حجم‌های آماده نمونه">
                        @foreach ([1, 10, 20, 30, 50, 75, 100, 150, 250, 500] as $gb)
                            <button type="button" x-on:click="demoGb = {{ $gb }}"
                                x-bind:aria-pressed="demoGb === {{ $gb }} ? 'true' : 'false'"
                                x-bind:class="demoGb === {{ $gb }} ? 'border-[#14B8A6] bg-[#E7F8F3] text-[#087F73]' : 'border-[#E7ECEF] bg-white text-[#354C58]'"
                                class="min-h-11 rounded-xl border px-3 py-2 text-sm font-bold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#087F73]">{{ $gb }} گیگ</button>
                        @endforeach
                    </div>
                    <div class="mt-5 flex items-center justify-between gap-3">
                        <button type="button" x-on:click="demoGb = Math.max(1, demoGb - 1)"
                            x-bind:disabled="demoGb <= 1" aria-label="کاهش یک گیگابایت"
                            class="min-h-11 rounded-xl border border-[#E7ECEF] bg-white px-5 text-xl font-bold text-[#087F73] disabled:opacity-40">−</button>
                        <span class="text-xs font-medium text-[#71818B]">تنظیم دقیق حجم</span>
                        <button type="button" x-on:click="demoGb = Math.min(500, demoGb + 1)"
                            x-bind:disabled="demoGb >= 500" aria-label="افزایش یک گیگابایت"
                            class="min-h-11 rounded-xl border border-[#E7ECEF] bg-white px-5 text-xl font-bold text-[#087F73] disabled:opacity-40">+</button>
                    </div>
                    <button type="button" x-on:click="demoBuilderStep = 'days'"
                        class="dg-btn-primary mt-6 w-full">ادامه · انتخاب مدت</button>
                </div>

                <div x-cloak x-show="demoBuilderStep === 'days'">
                    <p class="mt-6 text-xs font-bold text-[#087F73]">مرحله ۲ از ۲ · مدت سرویس</p>
                    <p class="mt-2 text-sm font-semibold text-[#536C76]">حجم: <span x-text="demoGb + ' گیگابایت'"></span></p>
                    <div class="mt-4 flex items-center justify-between gap-3">
                        <label for="demo-builder-days" class="text-sm font-bold text-[#1B2935]">مدت سرویس را به روز مشخص کنید</label>
                        <span class="shrink-0 text-lg font-extrabold tabular-nums text-[#087F73]"
                            x-text="demoDays + ' روز'">۳۰ روز</span>
                    </div>
                    <input id="demo-builder-days" type="range" name="demo_duration_days" min="7" max="180" step="1"
                        x-model.number="demoDays" x-bind:aria-valuetext="demoDays + ' روز'"
                        aria-describedby="demo-builder-days-hint"
                        class="mt-4 h-11 w-full cursor-pointer accent-[#14B8A6] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#087F73]" />
                    <p id="demo-builder-days-hint" class="mt-1 flex justify-between gap-3 text-xs text-[#71818B]">
                        <span>۷ روز</span><span>۱۸۰ روز</span>
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2" role="group" aria-label="مدت‌های آماده نمونه">
                        @foreach ([7, 15, 30, 60, 90, 180] as $days)
                            <button type="button" x-on:click="demoDays = {{ $days }}"
                                x-bind:aria-pressed="demoDays === {{ $days }} ? 'true' : 'false'"
                                x-bind:class="demoDays === {{ $days }} ? 'border-[#14B8A6] bg-[#E7F8F3] text-[#087F73]' : 'border-[#E7ECEF] bg-white text-[#354C58]'"
                                class="min-h-11 rounded-xl border px-3 py-2 text-sm font-bold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#087F73]">{{ $days }} روز</button>
                        @endforeach
                    </div>
                    <div class="mt-5 flex items-center justify-between gap-3">
                        <button type="button" x-on:click="demoDays = Math.max(7, demoDays - 1)"
                            x-bind:disabled="demoDays <= 7" aria-label="کاهش یک روز"
                            class="min-h-11 rounded-xl border border-[#E7ECEF] bg-white px-5 text-xl font-bold text-[#087F73] disabled:opacity-40">−</button>
                        <span class="text-xs font-medium text-[#71818B]">تنظیم دقیق مدت</span>
                        <button type="button" x-on:click="demoDays = Math.min(180, demoDays + 1)"
                            x-bind:disabled="demoDays >= 180" aria-label="افزایش یک روز"
                            class="min-h-11 rounded-xl border border-[#E7ECEF] bg-white px-5 text-xl font-bold text-[#087F73] disabled:opacity-40">+</button>
                    </div>
                    <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                        <button type="button" x-on:click="demoBuilderStep = 'volume'"
                            class="dg-btn-secondary sm:w-auto">تغییر حجم</button>
                        <button type="button" x-on:click="demoBuilderStep = 'review'"
                            class="dg-btn-primary flex-1">مشاهده مشخصات انتخاب‌شده</button>
                    </div>
                </div>

                <div x-cloak x-show="demoBuilderStep === 'review'">
                    <div class="mt-6 rounded-2xl bg-[#F6F8FA] p-4">
                        <p class="text-xs font-semibold text-[#71818B]">مشخصات انتخاب‌شده · نمونه</p>
                        <p class="mt-2 text-base font-extrabold text-[#1B2935]">
                            <span x-text="demoGb + ' گیگابایت'"></span> · <span x-text="demoDays + ' روز'"></span>
                        </p>
                        <p class="mt-3 text-xs leading-7 text-[#617380]">در این پیش‌نمایش قیمت محاسبه نمی‌شود و سفارشی ثبت نخواهد شد.</p>
                        <div class="mt-4 flex flex-wrap gap-3">
                            <button type="button" x-on:click="demoBuilderStep = 'volume'"
                                class="dg-btn-secondary">تغییر حجم</button>
                            <button type="button" x-on:click="demoBuilderStep = 'days'"
                                class="dg-btn-secondary">تغییر مدت</button>
                        </div>
                    </div>
                </div>
                <p class="mt-5 text-xs leading-7 text-[#71818B]">محدوده‌های این نمونه فقط برای آشنایی با پلن‌ساز هستند؛ محدوده و قیمت واقعی به پلن منتشرشده بستگی دارد.</p>
            </section>
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
