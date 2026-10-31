<div class="min-h-dvh bg-[#F6F8FA]">
    <div class="mx-auto flex min-h-dvh max-w-[1440px]">
        {{-- Compact desktop navigation; the same four actions stay accessible on mobile. --}}
        <aside class="hidden w-64 shrink-0 border-l border-[#E7ECEF] bg-white px-5 py-8 lg:flex lg:flex-col">
            <div class="mb-12 flex items-center gap-3 px-2">
                <img src="/images/denagate-logo.png" alt="لوگوی دناگیت" width="44" height="44" class="h-11 w-11 shrink-0 rounded-2xl object-contain" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-lg font-extrabold tracking-tight text-[#1B2935]" dir="ltr">DenaGate</p>
                        <span class="dg-status whitespace-nowrap px-2 py-1 text-[11px]" data-tone="success"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-[#14B8A6] motion-safe:animate-pulse" aria-hidden="true"></span>فعال</span>
                    </div>
                    <p class="text-xs font-medium text-[#647787]">پنل نمایندگی</p>
                </div>
            </div>

            <nav class="flex flex-col gap-2" aria-label="منوی پنل نمایندگی">
                @foreach ([
                    ['overview', 'نمای کلی'],
                    ['services', 'سرویس‌ها'],
                    ['credit', 'اعتبار من'],
                    ['reports', 'گزارش‌ها'],
                ] as [$key, $label])
                    <button type="button" wire:click="showSection('{{ $key }}')" wire:target="showSection" wire:loading.attr="disabled"
                        @class([
                            'flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-right text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14B8A6]',
                            'bg-[#E7F8F3] text-[#087F73]' => $section === $key,
                            'text-[#677987] hover:bg-[#F6F8FA] hover:text-[#1B2935]' => $section !== $key,
                        ])
                        @if ($section === $key) aria-current="page" @endif>
                        <span class="h-2 w-2 rounded-full {{ $section === $key ? 'bg-[#14B8A6]' : 'bg-[#C5D2D8]' }}" aria-hidden="true"></span>
                        {{ $label }}
                    </button>
                @endforeach
            </nav>

            <div class="mt-auto rounded-2xl bg-[#F6F8FA] px-4 py-4 text-xs leading-7 text-[#647787]">
                مدیریت حساب و سرویس‌های نمایندگی
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 border-b border-[#E7ECEF] bg-white/95 backdrop-blur-xl">
                <div class="mx-auto flex h-[76px] max-w-6xl items-center justify-between gap-3 px-5 md:px-9">
                    <div class="flex min-w-0 items-center gap-3">
                        <img src="/images/denagate-logo.png" alt="لوگوی دناگیت" width="40" height="40" class="h-10 w-10 shrink-0 rounded-[14px] object-contain lg:hidden" />
                        <div class="min-w-0">
                            <p class="text-[11px] font-medium text-[#71818B]">پنل نمایندگی</p>
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <p class="truncate text-base font-bold text-[#1B2935]" dir="ltr">DenaGate</p>
                                <span class="dg-status whitespace-nowrap px-2 py-1 text-[11px]" data-tone="success"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-[#14B8A6] motion-safe:animate-pulse" aria-hidden="true"></span>فعال</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="hidden items-center gap-2 rounded-full bg-[#EAF8F4] px-3 py-1.5 text-xs font-semibold text-[#087F73] sm:inline-flex">
                            <span class="h-1.5 w-1.5 rounded-full bg-[#14B8A6]" aria-hidden="true"></span>
                            حساب نماینده
                        </span>
                        <form method="POST" action="{{ route('denagate-live.logout', [], false) }}">
                            @csrf
                            <button type="submit" class="rounded-xl border border-[#E2E8EC] bg-white px-3.5 py-2 text-xs font-semibold text-[#586A78] transition-colors hover:bg-[#F6F8FA] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14B8A6]">
                                خروج
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <main id="live-main" class="mx-auto w-full max-w-6xl flex-1 px-4 pb-32 pt-7 sm:px-6 md:px-9 md:pt-10 lg:pb-12" aria-label="محتوای پنل نمایندگی">
                <p wire:loading.delay wire:target="showSection" role="status" class="mb-4 text-xs font-semibold text-[#087F73]">در حال باز کردن بخش…</p>
                @if ($readError)
                    <section class="rounded-[28px] border border-[#F0E0C9] bg-white p-7 shadow-sm" role="alert">
                        <h1 class="text-xl font-extrabold text-[#1B2935]">دریافت اطلاعات امکان‌پذیر نیست</h1>
                        <p class="mt-3 text-sm leading-8 text-[#687987]">لطفاً کمی بعد دوباره تلاش کنید.</p>
                        <button type="button" wire:click="$refresh" wire:target="$refresh" wire:loading.attr="disabled" class="mt-5 rounded-xl bg-[#14B8A6] px-5 py-2.5 text-sm font-bold text-white">تلاش دوباره<span wire:loading.delay wire:target="$refresh" role="status" class="text-xs font-semibold">در حال تازه‌سازی…</span></button>
                    </section>
                @elseif ($section === 'overview')
                    <section class="dg-card-accent relative overflow-hidden bg-gradient-to-bl from-[#E9F8F3] via-white to-white p-6 md:p-10">
                        <div class="relative z-10">
                            <span class="mb-4 inline-flex items-center gap-2 rounded-full border border-[#CCEDE4] bg-white/80 px-3 py-1.5 text-xs font-semibold text-[#087F73]">
                                <span class="h-2 w-2 rounded-full bg-[#14B8A6]" aria-hidden="true"></span>
                                پنل نمایندگی
                            </span>
                            <h1 class="max-w-3xl break-words text-[25px] font-extrabold leading-[1.75] tracking-tight text-[#1B2935] md:text-4xl">
                                خوش آمدید، <bdi>{{ $name }}</bdi>
                            </h1>
                            <p class="mt-2 max-w-xl text-sm leading-8 text-[#617380]">اعتبار، سرویس‌ها و فروش شما در یک نگاه</p>
                            <button type="button" wire:click="showSection('shop')" wire:target="showSection" wire:loading.attr="disabled" class="dg-btn-primary mt-6 min-h-12 px-6 py-3">
                                خرید سرویس <span class="mr-2" aria-hidden="true">←</span>
                            </button>
                            @if ($salesBotUrl !== null)
                                <a href="{{ $salesBotUrl }}" target="_blank" rel="noopener noreferrer"
                                    x-on:click="const tg = window.Telegram?.WebApp; if (tg && tg.platform !== 'unknown' && typeof tg.openTelegramLink === 'function') { $event.preventDefault(); tg.openTelegramLink($el.href); }"
                                    class="mt-4 inline-flex min-h-11 items-center rounded-xl px-2 text-sm font-semibold text-[#087F73] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14B8A6]">
                                    بازگشت به بات فروش ←
                                </a>
                            @endif
                        </div>
                        <div class="pointer-events-none absolute -bottom-24 -left-24 h-60 w-60 rounded-full bg-[#14B8A6]/10 blur-3xl" aria-hidden="true"></div>
                    </section>

                    <h2 class="dg-heading mb-5 mt-8">نمای کلی</h2>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 md:gap-5">
                        <section class="dg-card p-5 md:p-7">
                            <p class="text-xs font-semibold text-[#71818B]">اعتبار قابل استفاده</p>
                            <p class="dg-stat mt-3" dir="ltr">{{ number_format(intdiv($wallet->balanceRials, 10)) }} <span class="text-xs font-normal text-[#687987]">تومان</span></p>
                            <button type="button" wire:click="showSection('credit')" wire:target="showSection" wire:loading.attr="disabled" class="dg-btn-secondary mt-4 w-full">جزئیات اعتبار ←</button>
                        </section>
                        <section class="dg-card p-5 md:p-7">
                            <p class="text-xs font-semibold text-[#71818B]">سرویس‌های ثبت‌شده</p>
                            <p class="dg-stat mt-3">{{ number_format($counts['total']) }}</p>
                            <p class="mt-1 text-xs text-[#71818B]">فعال در سوابق: {{ number_format($counts['activeRecorded']) }}</p>
                            <button type="button" wire:click="showSection('services')" wire:target="showSection" wire:loading.attr="disabled" class="dg-btn-secondary mt-4 w-full">فهرست سرویس‌ها ←</button>
                        </section>
                        <section class="dg-card p-5 md:p-7">
                            <p class="text-xs font-semibold text-[#71818B]">فروش تسویه‌شده · ۳۰ روز اخیر</p>
                            <p class="dg-stat mt-3" dir="ltr">{{ number_format(intdiv($report['revenueRials'], 10)) }} <span class="text-xs font-normal text-[#687987]">تومان</span></p>
                            <p class="mt-1 text-xs text-[#71818B]">{{ number_format($report['transactions']) }} تراکنش تسویه‌شده</p>
                            <button type="button" wire:click="showSection('reports')" wire:target="showSection" wire:loading.attr="disabled" class="dg-btn-secondary mt-4 w-full">گزارش فروش ←</button>
                        </section>
                    </div>
                @elseif ($section === 'shop')
                    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h1 class="dg-heading">خرید سرویس</h1>
                            <p class="mt-2 text-sm leading-7 text-[#71818B]">پلن مناسب را انتخاب کنید و مبلغ آن را ببینید.</p>
                        </div>
                        <button type="button" wire:click="showSection('overview')" wire:target="showSection" wire:loading.attr="disabled" class="dg-btn-secondary">بستن</button>
                    </div>

                    @if ($createdOrder !== null)
                        <section class="dg-card p-6 sm:p-8" role="status">
                            <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-[#E7F8F3] text-[#087F73]">
                                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                            </div>
                            @php
                                $orderStatusText = match ($createdOrder->status) {
                                    'pending_payment' => 'در انتظار پرداخت',
                                    'paid' => 'پرداخت ثبت‌شده؛ سرویس در انتظار آماده‌سازی',
                                    'fulfilled' => 'سرویس در سوابق ثبت شده است',
                                    'needs_review' => 'نیازمند بررسی',
                                    'failed' => 'نیازمند پیگیری',
                                    default => 'وضعیت سفارش را بررسی کنید',
                                };
                            @endphp
                            <h2 class="dg-heading">{{ $createdOrder->status === 'pending_payment' ? 'سفارش شما ثبت شد' : 'سفارش #'.$createdOrder->id }}</h2>
                            <p class="dg-status mt-2">{{ $orderStatusText }}</p>
                            @if ($createdOrder->status === 'pending_payment')
                                <p class="mt-2 text-xs leading-7 text-[#71818B]">تا زمان تأیید پرداخت، مبلغی از اعتبار شما کسر نمی‌شود و سرویسی فعال نمی‌شود.</p>
                            @elseif ($createdOrder->status === 'paid')
                                <p class="mt-2 text-xs leading-7 text-[#71818B]">پرداخت ثبت شده است؛ آماده‌سازی سرویس هنوز تکمیل نشده است.</p>
                            @endif
                            <div class="mt-6 space-y-3 rounded-2xl bg-[#F6F8FA] p-5 text-sm">
                                <div class="flex flex-wrap justify-between gap-3"><span class="text-[#71818B]">شماره سفارش</span><span dir="ltr" class="font-bold text-[#1B2935]">#{{ $createdOrder->id }}</span></div>
                                <div class="flex flex-wrap justify-between gap-3"><span class="text-[#71818B]">پلن</span><span class="font-bold text-[#1B2935]">{{ $createdOrder->productName }}</span></div>
                                @if ($createdCustomerName !== null)
                                    <div class="flex flex-wrap justify-between gap-3"><span class="text-[#71818B]">نام کاربری مشتری</span><span class="break-all font-bold text-[#1B2935]" dir="auto">{{ $createdCustomerName }}</span></div>
                                @endif
                                <div class="flex flex-wrap justify-between gap-3 border-t border-[#E7ECEF] pt-3"><span class="text-[#71818B]">مبلغ سفارش</span><span class="font-extrabold text-[#087F73]" dir="ltr">{{ number_format(intdiv($createdOrder->totalPriceRials, 10)) }}{{ $createdOrder->totalPriceRials % 10 === 0 ? '' : '.'.($createdOrder->totalPriceRials % 10) }} تومان</span></div>
                            </div>
                            @if ($walletError)
                                <p role="alert" class="mt-5 rounded-2xl border border-[#F0E0C9] bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">{{ $walletError }}</p>
                            @endif
                            @if ($createdOrder->status === 'pending_payment')
                                @if ($walletConfirmToken !== null && $walletConfirmAmountRials !== null && $walletConfirmBalanceRials !== null)
                                    <div class="dg-card-accent mt-5 p-5">
                                        <h3 class="text-base font-extrabold text-[#1B2935]">تأیید پرداخت از اعتبار</h3>
                                        <p class="mt-2 break-words text-xs leading-6 text-[#536C76]">برای سفارش #{{ $createdOrder->id }} · {{ $createdOrder->productName }}</p>
                                        <div class="mt-4 flex flex-wrap justify-between gap-2 text-sm text-[#536C76]"><span>مبلغ برداشت</span><span dir="ltr" class="font-bold">{{ number_format(intdiv($walletConfirmAmountRials, 10)) }}{{ $walletConfirmAmountRials % 10 === 0 ? '' : '.'.($walletConfirmAmountRials % 10) }} تومان</span></div>
                                        <div class="mt-3 flex flex-wrap justify-between gap-2 text-sm text-[#536C76]"><span>اعتبار فعلی</span><span dir="ltr">{{ number_format(intdiv($walletConfirmBalanceRials, 10)) }}{{ $walletConfirmBalanceRials % 10 === 0 ? '' : '.'.($walletConfirmBalanceRials % 10) }} تومان</span></div>
                                        <div class="mt-3 flex flex-wrap justify-between gap-2 border-t border-[#CCEDE4] pt-3 text-sm font-bold text-[#1B2935]"><span>اعتبار پس از پرداخت</span><span dir="ltr">{{ number_format(intdiv($walletConfirmBalanceRials - $walletConfirmAmountRials, 10)) }}{{ ($walletConfirmBalanceRials - $walletConfirmAmountRials) % 10 === 0 ? '' : '.'.(($walletConfirmBalanceRials - $walletConfirmAmountRials) % 10) }} تومان</span></div>
                                        <p class="mt-4 text-xs leading-7 text-[#536C76]">با تأیید، مبلغ بالا یک بار از اعتبار شما کسر می‌شود.</p>
                                        <button type="button" wire:click="confirmWalletPayment" wire:loading.attr="disabled" wire:target="confirmWalletPayment"
                                            class="dg-btn-primary mt-5 min-h-12 w-full">تأیید و پرداخت<span wire:loading.delay wire:target="confirmWalletPayment" role="status" class="text-xs font-semibold">در حال ثبت پرداخت…</span></button>
                                        <button type="button" wire:click="cancelWalletPayment" wire:target="cancelWalletPayment,confirmWalletPayment" wire:loading.attr="disabled" class="dg-btn-secondary mt-3 w-full">انصراف از پرداخت</button>
                                    </div>
                                @elseif (config('sales.wallet_settlement_enabled') === true)
                                    <button type="button" wire:click="prepareWalletPayment" wire:loading.attr="disabled" wire:target="prepareWalletPayment"
                                        class="dg-btn-primary mt-5 min-h-12 w-full">پرداخت با اعتبار<span wire:loading.delay wire:target="prepareWalletPayment" role="status" class="text-xs font-semibold">در حال بررسی اعتبار…</span></button>
                                @else
                                    <p class="mt-4 text-xs leading-7 text-[#71818B]">پرداخت از اعتبار در حال حاضر در دسترس نیست. می‌توانید سفارش خود را در بات فروش پیگیری کنید.</p>
                                @endif
                            @endif
                            <button type="button" wire:click="backToPlans" wire:target="backToPlans" wire:loading.attr="disabled" class="dg-btn-secondary mt-5">انتخاب سرویس دیگر</button>
                        </section>
                    @elseif ($selectedPlan === null)
                        @if ($planUnavailable || $quoteError || $orderError || $walletError)
                            <p role="alert" class="mb-5 rounded-2xl border border-[#F0E0C9] bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">
                                {{ $quoteError ?? $walletError ?? $orderError ?? 'این پلن در دسترس نیست. لطفاً یکی از پلن‌های موجود را انتخاب کنید.' }}
                            </p>
                        @endif
                        @if (count($recentOrders) > 0)
                            <section class="dg-card mb-6 p-4 sm:p-5">
                                <h2 class="text-base font-extrabold text-[#1B2935]">سفارش‌های اخیر من</h2>
                                <p class="mt-2 text-xs leading-6 text-[#71818B]">تا ۱۰ سفارش اخیر؛ برای جزئیات یا پرداخت، یک سفارش را انتخاب کنید.</p>
                                <div class="mt-3 divide-y divide-[#EFF2F4]">
                                    @foreach ($recentOrders as $order)
                                        <button type="button" wire:key="live-order-{{ $order->id }}" wire:click="openOrder({{ $order->id }})" wire:target="openOrder" wire:loading.attr="disabled"
                                            class="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg py-2.5 text-right focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#087F73]">
                                            <span class="min-w-0">
                                                <span class="block text-sm font-bold text-[#1B2935]">#{{ $order->id }} · {{ $order->productName }}</span>
                                                <span class="mt-1 block text-xs leading-5 text-[#71818B]">{{ match ($order->status) {
                                                    'pending_payment' => 'در انتظار پرداخت',
                                                    'paid' => 'پرداخت ثبت‌شده؛ سرویس در انتظار آماده‌سازی',
                                                    'fulfilled' => 'سرویس در سوابق ثبت شده است',
                                                    'needs_review' => 'نیازمند بررسی',
                                                    default => 'مشاهده وضعیت',
                                                } }}</span>
                                            </span>
                                            <span class="shrink-0 text-xs font-bold text-[#087F73]">مشاهده ←</span>
                                        </button>
                                    @endforeach
                                </div>
                            </section>
                        @endif
                        @if (count($catalogPresets) === 0 && count($catalogBuilders) === 0)
                            <section class="dg-empty">
                                در حال حاضر پلنی برای خرید موجود نیست.
                            </section>
                        @else
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ($catalogPresets as $item)
                                    <article wire:key="live-plan-{{ $item->plan->id }}" class="dg-card flex flex-col p-4 sm:p-5">
                                        <h2 class="break-words text-base font-extrabold text-[#1B2935]">{{ $item->plan->name }}</h2>
                                        <p class="mt-2 text-xs leading-7 text-[#71818B]">
                                            {{ $item->plan->durationDays }} روز · {{ $item->plan->trafficGb === null ? 'حجم نامحدود' : number_format($item->plan->trafficGb).' گیگابایت' }}
                                        </p>
                                        <p class="dg-stat mt-4 text-[#087F73]" dir="ltr">{{ number_format(intdiv($item->quote->totalPriceRials, 10)) }}{{ $item->quote->totalPriceRials % 10 === 0 ? '' : '.'.($item->quote->totalPriceRials % 10) }} <span class="text-xs font-medium text-[#71818B]">تومان</span></p>
                                        <p class="mt-1 text-xs text-[#71818B]">با تخفیف نمایندگی {{ $item->quote->discountPercent }}٪</p>
                                        <button type="button" wire:click="selectPlan({{ $item->plan->id }})" wire:target="selectPlan" wire:loading.attr="disabled" class="dg-btn-secondary mt-4 w-full">انتخاب پلن<span wire:loading.delay wire:target="selectPlan" role="status" class="text-xs font-semibold">در حال انتخاب…</span></button>
                                    </article>
                                @endforeach
                                @foreach ($catalogBuilders as $builder)
                                    <article wire:key="live-builder-{{ $builder->id }}" class="dg-card flex flex-col p-4 sm:p-5">
                                        <h2 class="break-words text-base font-extrabold text-[#1B2935]">{{ $builder->name }}</h2>
                                        <p class="mt-2 text-xs leading-7 text-[#71818B]">مدت دلخواه: {{ $builder->minDays }} تا {{ $builder->maxDays }} روز</p>
                                        <p class="mt-1 text-xs leading-7 text-[#71818B]">{{ $builder->kind === 'time' ? 'حجم نامحدود' : 'حجم دلخواه: '.number_format($builder->minGb).' تا '.number_format($builder->maxGb).' گیگابایت' }}</p>
                                        <p class="mt-5 text-sm font-semibold text-[#087F73]">قیمت بر اساس انتخاب شما</p>
                                        <button type="button" wire:click="selectPlan({{ $builder->id }})" wire:target="selectPlan" wire:loading.attr="disabled" class="dg-btn-secondary mt-auto w-full">تنظیم پلن<span wire:loading.delay wire:target="selectPlan" role="status" class="text-xs font-semibold">در حال انتخاب…</span></button>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <button type="button" wire:click="backToPlans" wire:target="backToPlans" wire:loading.attr="disabled" class="mb-5 min-h-11 text-sm font-bold text-[#087F73] focus-visible:outline-2 focus-visible:outline-[#14B8A6]">→ بازگشت به پلن‌ها</button>
                        <section class="dg-card p-5 sm:p-7">
                            <h2 class="text-xl font-extrabold text-[#1B2935]">{{ $selectedPlan->name }}</h2>
                            <p class="mt-2 text-xs text-[#71818B]">{{ $selectedPlan->mode === 'builder' ? 'انتخاب مدت و حجم سرویس' : 'جزئیات پلن انتخاب‌شده' }}</p>

                            @if ($selectedPlan->mode === 'builder')
                                <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="quote-days" class="mb-2 block text-sm font-semibold text-[#536C76]">مدت سرویس (روز)</label>
                                        <input id="quote-days" aria-describedby="quote-days-hint" type="number" min="{{ $selectedPlan->minDays }}" max="{{ $selectedPlan->maxDays }}" step="1" inputmode="numeric" wire:model="daysInput" class="dg-field min-h-12 px-4" />
                                        <p id="quote-days-hint" class="mt-2 text-xs text-[#71818B]">{{ $selectedPlan->minDays }} تا {{ $selectedPlan->maxDays }} روز</p>
                                    </div>
                                    @if ($selectedPlan->kind !== 'time')
                                        <div>
                                            <label for="quote-gb" class="mb-2 block text-sm font-semibold text-[#536C76]">حجم سرویس (گیگابایت)</label>
                                            <input id="quote-gb" aria-describedby="quote-gb-hint" type="number" min="{{ $selectedPlan->minGb }}" max="{{ $selectedPlan->maxGb }}" step="1" inputmode="numeric" wire:model="gbInput" class="dg-field min-h-12 px-4" />
                                            <p id="quote-gb-hint" class="mt-2 text-xs text-[#71818B]">{{ number_format($selectedPlan->minGb) }} تا {{ number_format($selectedPlan->maxGb) }} گیگابایت</p>
                                        </div>
                                    @else
                                        <p class="self-center text-sm font-semibold text-[#536C76]">حجم نامحدود</p>
                                    @endif
                                </div>
                            @else
                                <p class="mt-5 text-sm font-semibold text-[#536C76]">{{ $selectedPlan->durationDays }} روز · {{ $selectedPlan->trafficGb === null ? 'حجم نامحدود' : number_format($selectedPlan->trafficGb).' گیگابایت' }}</p>
                            @endif

                            <button type="button" wire:click="previewQuote" wire:target="previewQuote" wire:loading.attr="disabled" class="dg-btn-primary mt-6 min-h-12 w-full">مشاهده قیمت نهایی<span wire:loading.delay wire:target="previewQuote" role="status" class="text-xs font-semibold">در حال محاسبه…</span></button>

                            @if ($orderError)
                                <p role="alert" class="mt-5 rounded-2xl bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">{{ $orderError }}</p>
                            @endif

                            @if ($quoteError)
                                <p role="alert" class="mt-5 rounded-2xl bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">{{ $quoteError }}</p>
                            @elseif ($planQuote !== null)
                                <div class="dg-card-accent mt-6 p-5" aria-label="پیش‌فاکتور نمایندگی">
                                    <h3 class="text-base font-extrabold text-[#1B2935]">پیش‌فاکتور شما</h3>
                                    <p class="mt-2 text-xs leading-7 text-[#617380]">{{ $planQuote->durationDays }} روز · {{ $planQuote->trafficGb === null ? 'حجم نامحدود' : number_format($planQuote->trafficGb).' گیگابایت' }}</p>
                                    <div class="mt-5 flex items-center justify-between gap-3 text-sm text-[#536C76]">
                                        <span>قیمت اصلی</span><span dir="ltr">{{ number_format(intdiv($planQuote->publicPriceRials, 10)) }}{{ $planQuote->publicPriceRials % 10 === 0 ? '' : '.'.($planQuote->publicPriceRials % 10) }} تومان</span>
                                    </div>
                                    <div class="mt-3 flex items-center justify-between gap-3 text-sm text-[#087F73]">
                                        <span>تخفیف نمایندگی ({{ $planQuote->discountPercent }}٪)</span><span dir="ltr">− {{ number_format(intdiv($planQuote->discountRials, 10)) }}{{ $planQuote->discountRials % 10 === 0 ? '' : '.'.($planQuote->discountRials % 10) }} تومان</span>
                                    </div>
                                    <div class="mt-5 flex items-center justify-between gap-3 border-t border-[#CCEDE4] pt-4">
                                        <span class="text-sm font-extrabold text-[#1B2935]">مبلغ نهایی</span>
                                        <span class="dg-stat text-[#087F73]" dir="ltr">{{ number_format(intdiv($planQuote->totalPriceRials, 10)) }}{{ $planQuote->totalPriceRials % 10 === 0 ? '' : '.'.($planQuote->totalPriceRials % 10) }} <span class="text-xs font-medium">تومان</span></span>
                                    </div>
                                </div>
                                <p class="mt-4 text-xs leading-7 text-[#71818B]">مبلغ هنگام ثبت سفارش دوباره بررسی می‌شود. ثبت سفارش به‌معنای پرداخت نیست.</p>
                                @if ($confirmedQuoteHash !== null && $orderIntentKey !== null)
                                    <div class="mt-6 border-t border-[#E7ECEF] pt-6">
                                        <label for="customer-username" class="mb-2 block text-sm font-bold text-[#1B2935]">نام کاربری مشتری</label>
                                        <input id="customer-username" aria-describedby="customer-username-hint" type="text" maxlength="60" autocomplete="off" dir="auto" wire:model="customerUsername"
                                            placeholder="مثلاً customer_01"
                                            class="dg-field min-h-12 px-4" />
                                        <p id="customer-username-hint" class="mt-2 text-xs leading-7 text-[#71818B]">برای شناسایی سرویس در پنل شما؛ شماره تماس لازم نیست.</p>
                                        <button type="button" wire:click="confirmOrder" wire:loading.attr="disabled"
                                            wire:target="confirmOrder"
                                            class="mt-5 min-h-12 w-full rounded-xl bg-[#14B8A6] px-5 py-3 text-sm font-bold text-white disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#087F73]">
                                            ثبت سفارش بدون پرداخت
                                        <span wire:loading.delay wire:target="confirmOrder" role="status" class="text-xs font-semibold">در حال ثبت سفارش…</span></button>
                                        <p class="mt-2 text-center text-xs text-[#71818B]">پرداخت در مرحله بعد است.</p>
                                    </div>
                                @endif
                            @endif
                        </section>
                    @endif
                @elseif ($section === 'credit')
                    <div class="mb-6">
                        <h1 class="dg-heading">اعتبار من</h1>
                        <p class="mt-2 text-sm text-[#71818B]">موجودی و آخرین درخواست‌های شارژ</p>
                    </div>
                    <section class="dg-card-accent bg-gradient-to-bl from-[#E7F8F3] to-white p-7 md:p-9">
                        <p class="text-sm font-semibold text-[#087F73]">اعتبار قابل استفاده</p>
                        <p class="dg-stat mt-4 text-3xl" dir="ltr">{{ number_format(intdiv($wallet->balanceRials, 10)) }} <span class="text-sm font-medium text-[#687987]">تومان</span></p>
                        <p class="mt-3 text-xs leading-7 text-[#617380]">مبلغ بر اساس تراکنش‌های ثبت‌شده حساب شما محاسبه شده است.</p>
                    </section>
                    <section class="dg-card mt-5 p-5 md:p-7" aria-label="افزایش اعتبار">
                        <h2 class="dg-heading">افزایش اعتبار</h2>
                        <p class="mt-2 text-sm leading-7 text-[#71818B]">برای افزایش اعتبار، مبلغ را در بات فروش انتخاب کنید و رسید را همان‌جا ارسال کنید.</p>
                        @if ($topupBotUrl !== null)
                            <a href="{{ $topupBotUrl }}" target="_blank" rel="noopener noreferrer"
                                x-on:click="const tg = window.Telegram?.WebApp; if (tg && tg.platform !== 'unknown' && typeof tg.openTelegramLink === 'function') { $event.preventDefault(); tg.openTelegramLink($el.href); }"
                                class="dg-btn-primary mt-5 min-h-12 w-full">
                                ➕ افزایش اعتبار در بات
                            </a>
                        @else
                            <p class="mt-4 rounded-xl bg-[#F6F8FA] p-4 text-sm leading-7 text-[#71818B]">امکان انتقال مستقیم به بات فعلاً فراهم نیست. از داخل بات فروش، «اعتبار من» را انتخاب کنید.</p>
                        @endif
                        <p class="mt-4 text-xs leading-7 text-[#71818B]">اعتبار پس از تأیید واریز اضافه می‌شود. برای دیدن موجودی جدید، «تازه‌سازی اعتبار» را بزنید.</p>
                        <button type="button" wire:click="$refresh" wire:loading.attr="disabled" wire:target="$refresh"
                            class="dg-btn-secondary mt-3">تازه‌سازی اعتبار<span wire:loading.delay wire:target="$refresh" role="status" class="text-xs font-semibold">در حال تازه‌سازی…</span></button>
                    </section>
                    <section class="dg-card mt-5 p-5 md:p-7">
                        <h2 class="mb-3 text-base font-extrabold">درخواست‌های اخیر شارژ</h2>
                        @forelse ($wallet->topups as $topup)
                            @php
                                $topupStatus = match ($topup['status']) {
                                    'approved' => 'تأییدشده',
                                    'rejected' => 'ردشده',
                                    'pending' => 'در انتظار ارسال رسید',
                                    'submitted' => 'در انتظار بررسی واریز',
                                    default => 'وضعیت نامشخص',
                                };
                            @endphp
                            <div class="flex min-h-12 flex-wrap items-center justify-between gap-2 border-b border-[#F0F2F4] py-2.5 last:border-b-0">
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-[#1B2935]">{{ number_format(intdiv($topup['amount_rials'], 10)) }} تومان</p>
                                    <p class="mt-1 text-xs text-[#71818B]">{{ \App\Support\PersianCalendar::utcDateTime($topup['created_at']) }}</p>
                                </div>
                                <span class="dg-status" data-tone="{{ match ($topup['status']) { 'approved' => 'success', 'rejected' => 'danger', 'pending', 'submitted' => 'warning', default => 'neutral' } }}">{{ $topupStatus }}</span>
                            </div>
                        @empty
                            <p class="dg-empty">هنوز درخواست شارژی ثبت نشده است.</p>
                        @endforelse
                    </section>
                @elseif ($section === 'services')
                    <div class="mb-6">
                        <h1 class="dg-heading">سرویس‌های من</h1>
                        <p class="mt-2 text-sm text-[#71818B]">اشتراک‌های ثبت‌شده برای مشتریان شما</p>
                    </div>
                    @if ($selectedService !== null)
                        @php
                            $item = $selectedService->summary;
                            $localStatus = match ($item->status) {
                                'active' => 'فعال در سوابق',
                                'needs_review' => 'نیازمند بررسی',
                                'provisioning' => 'در حال آماده‌سازی',
                                'failed' => 'نیازمند پیگیری',
                                default => 'وضعیت نامشخص',
                            };
                        @endphp
                        <button type="button" wire:click="backToServices" wire:target="backToServices,confirmServiceStatus,confirmRenewalPayment" wire:loading.attr="disabled" class="dg-btn-secondary mb-5">→ بازگشت به سرویس‌ها</button>
                        <section class="dg-card p-5 sm:p-7">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold text-[#087F73]">جزئیات سرویس</p>
                                    <h2 class="dg-heading mt-2 break-words">{{ $labels[$item->orderId] ?? $item->productName }}</h2>
                                    <p class="mt-2 text-sm text-[#71818B]">{{ $item->productName }}</p>
                                </div>
                                <span class="dg-status" data-tone="{{ match ($item->status) { 'failed' => 'danger', 'needs_review', 'provisioning' => 'warning', default => 'neutral' } }}">{{ $localStatus }}</span>
                            </div>
                            <div class="mt-6 grid grid-cols-2 gap-3">
                                <div class="rounded-2xl bg-[#F6F8FA] p-4">
                                    <p class="text-xs text-[#71818B]">مدت خریداری‌شده</p>
                                    <p class="mt-2 text-lg font-extrabold text-[#1B2935]">{{ number_format($item->durationDays) }} روز</p>
                                </div>
                                <div class="rounded-2xl bg-[#F6F8FA] p-4">
                                    <p class="text-xs text-[#71818B]">حجم خریداری‌شده</p>
                                    <p class="mt-2 text-lg font-extrabold text-[#1B2935]">{{ $item->trafficGb === null ? 'نامحدود' : number_format($item->trafficGb).' گیگابایت' }}</p>
                                </div>
                            </div>
                            <dl class="mt-5 space-y-3 border-t border-[#EFF2F4] pt-5 text-sm">
                                <div class="flex flex-wrap justify-between gap-2"><dt class="text-[#71818B]">شماره سفارش</dt><dd dir="ltr" class="font-bold text-[#1B2935]">#{{ $item->orderId }}</dd></div>
                                <div class="flex flex-wrap justify-between gap-2"><dt class="text-[#71818B]">تاریخ ثبت</dt><dd class="font-semibold text-[#1B2935]">{{ \App\Support\PersianCalendar::utcDateTime($item->orderedAt) }}</dd></div>
                            </dl>
                            <p class="mt-5 text-xs leading-7 text-[#71818B]">وضعیت بالا بر اساس اطلاعات ثبت‌شده است و الزاماً به معنی اتصال فعلی نیست.</p>
                        </section>

                        <section class="dg-card mt-5 p-5 sm:p-7" aria-label="نام مشتری">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h3 class="dg-heading">نام مشتری</h3>
                                    <p class="mt-2 text-xs leading-6 text-[#71818B]">این نام فقط برای مدیریت سرویس‌های شماست.</p>
                                </div>
                                @if (! $editingServiceLabel)
                                    <button type="button" wire:click="editServiceLabel" wire:target="editServiceLabel" wire:loading.attr="disabled"
                                        class="dg-btn-secondary">ویرایش نام<span wire:loading.delay wire:target="editServiceLabel" role="status" class="text-xs font-semibold">در حال آماده‌سازی…</span></button>
                                @endif
                            </div>
                            @if ($serviceLabelError)
                                <p role="alert" class="mt-4 rounded-xl bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">{{ $serviceLabelError }}</p>
                            @endif
                            @if ($serviceLabelNotice)
                                <p role="status" class="mt-4 text-sm font-semibold text-[#087F73]">{{ $serviceLabelNotice }}</p>
                            @endif
                            @if ($editingServiceLabel)
                                <form wire:submit="saveServiceLabel" class="mt-5 space-y-3">
                                    <label for="live-customer-label" class="block text-sm font-semibold text-[#1B2935]">نام کاربری مشتری</label>
                                    <input id="live-customer-label" aria-describedby="live-customer-label-hint" type="text" wire:model="serviceLabelInput" maxlength="60" autocomplete="off"
                                        placeholder="مثلاً: مشتری شماره ۱"
                                        class="dg-field min-h-12 px-4">
                                    <p id="live-customer-label-hint" class="text-xs leading-6 text-[#71818B]">حداکثر ۶۰ نویسه · خالی بگذارید تا نام اختصاصی حذف شود.</p>
                                    <div class="flex flex-wrap gap-3">
                                        <button type="submit" wire:loading.attr="disabled" wire:target="saveServiceLabel"
                                            class="dg-btn-primary"><span>ذخیره نام</span><span wire:loading.delay wire:target="saveServiceLabel" role="status" class="text-xs font-semibold">در حال ذخیره…</span></button>
                                        <button type="button" wire:click="cancelServiceLabel" wire:target="cancelServiceLabel" wire:loading.attr="disabled"
                                            class="dg-btn-secondary">انصراف</button>
                                    </div>
                                </form>
                            @endif
                        </section>

                        <section class="dg-card mt-5 p-5 sm:p-7" aria-label="اطلاعات اتصال">
                            <h3 class="dg-heading">اطلاعات اتصال</h3>
                            <p class="mt-2 text-xs leading-7 text-[#71818B]">لینک اتصال مخصوص این سرویس است. آن را فقط برای مشتری خود ارسال کنید.</p>
                            @if ($serviceConnectionError)
                                <p role="alert" class="mt-4 rounded-xl bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">{{ $serviceConnectionError }}</p>
                            @endif
                            @if ($serviceSubscriptionUrl !== null && $serviceQrDataUri !== null)
                                <div class="mt-5" x-data="{ copied: false, copyError: false, copying: false }">
                                    <div class="flex justify-center rounded-2xl bg-white p-4">
                                        <img src="{{ $serviceQrDataUri }}" alt="کد QR اتصال سرویس" width="240" height="240" class="h-auto w-full max-w-[240px]" />
                                    </div>
                                    <label for="live-subscription-url" class="mt-4 block text-xs font-semibold text-[#536C76]">لینک اشتراک</label>
                                    <input id="live-subscription-url" type="text" x-ref="connectionLink" readonly dir="ltr"
                                        value="{{ $serviceSubscriptionUrl }}"
                                        class="dg-field mt-2 bg-[#F6F8FA] text-left text-xs"
                                        x-on:focus="$el.select()">
                                    <div class="mt-4 grid grid-cols-2 gap-3">
                                        <button type="button"
                                            x-on:click="if (copying) return; copying = true; copied = false; copyError = false; const fallback = () => { try { $refs.connectionLink.select(); return document.execCommand('copy') } catch (error) { return false } }; if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') { navigator.clipboard.writeText($refs.connectionLink.value).then(() => copied = true).catch(() => { copied = fallback(); copyError = ! copied }).finally(() => copying = false) } else { copied = fallback(); copyError = ! copied; copying = false }"
                                            x-bind:disabled="copying" class="dg-btn-primary min-h-12"><span x-show="!copying">کپی لینک</span><span x-cloak x-show="copying">در حال کپی…</span></button>
                                        <button type="button" wire:click="hideServiceConnection" wire:target="hideServiceConnection,revealServiceConnection" wire:loading.attr="disabled"
                                            class="dg-btn-secondary min-h-12">پنهان کردن</button>
                                    </div>
                                    <p x-cloak x-show="copied" role="status" class="mt-3 text-xs font-semibold text-[#087F73]">لینک کپی شد.</p>
                                    <p x-cloak x-show="copyError" role="alert" class="mt-3 text-xs leading-6 text-[#8e2929]">کپی خودکار ممکن نشد. لینک را انتخاب و دستی کپی کنید.</p>
                                </div>
                            @elseif ($selectedService->canRequestLink)
                                <button type="button" wire:click="revealServiceConnection" wire:loading.attr="disabled" wire:target="revealServiceConnection"
                                    class="dg-btn-secondary mt-5 min-h-12 w-full">مشاهده اطلاعات اتصال<span wire:loading.delay wire:target="revealServiceConnection" role="status" class="text-xs font-semibold">در حال دریافت…</span></button>
                            @else
                                <p class="mt-4 rounded-xl bg-[#F6F8FA] p-4 text-sm leading-7 text-[#71818B]">لینک اتصال این سرویس فعلاً در دسترس نیست.</p>
                            @endif
                        </section>

                        <section class="dg-card mt-5 p-5 sm:p-7" aria-label="وضعیت و مصرف سرویس">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h3 class="dg-heading">وضعیت و مصرف سرویس</h3>
                                    <p class="mt-2 text-xs leading-6 text-[#71818B]">وضعیت و مصرف هنگام درخواست از شبکه دریافت می‌شود.</p>
                                </div>
                                @if ($selectedService->canRequestLink)
                                    <button type="button" wire:click="refreshServiceUsage" wire:loading.attr="disabled" wire:target="refreshServiceUsage"
                                        class="dg-btn-secondary">بررسی وضعیت<span wire:loading.delay wire:target="refreshServiceUsage" role="status" class="text-xs font-semibold">در حال بررسی…</span></button>
                                @endif
                            </div>
                            @if ($serviceUsageError)
                                <p role="alert" class="mt-5 rounded-xl bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">{{ $serviceUsageError }}</p>
                            @elseif ($serviceLiveUsage !== null && $serviceUsageCheckedAt !== null)
                                <div class="mt-5 border-t border-[#EFF2F4] pt-5">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <span class="text-sm text-[#71818B]">وضعیت بررسی‌شده</span>
                                        <span class="dg-status" data-tone="{{ match ($serviceLiveUsage['status']) { 'active' => 'success', 'expired', 'disabled', 'exhausted' => 'danger', 'limited' => 'warning', default => 'neutral' } }}">{{ match ($serviceLiveUsage['status']) {
                                            'active' => 'فعال',
                                            'expired' => 'منقضی‌شده',
                                            'disabled' => 'غیرفعال',
                                            'exhausted' => 'حجم تمام‌شده',
                                            'limited' => 'محدودشده',
                                            default => 'نامشخص',
                                        } }}</span>
                                    </div>
                                    <div class="mt-5 grid grid-cols-2 gap-3">
                                        <div class="rounded-2xl bg-[#F6F8FA] p-4">
                                            <p class="text-xs text-[#71818B]">حجم مصرف‌شده</p>
                                            <p class="mt-2 break-words text-lg font-extrabold text-[#1B2935]"><bdi class="dg-stat text-lg">{{ number_format($serviceLiveUsage['usedBytes'] / 1073741824, 2) }}</bdi> گیگابایت</p>
                                        </div>
                                        <div class="rounded-2xl bg-[#F6F8FA] p-4">
                                            <p class="text-xs text-[#71818B]">حجم باقی‌مانده</p>
                                            <p class="mt-2 break-words text-lg font-extrabold text-[#1B2935]">{{ $serviceLiveUsage['limitBytes'] === 0 ? 'بدون سقف' : number_format(max(0, $serviceLiveUsage['limitBytes'] - $serviceLiveUsage['usedBytes']) / 1073741824, 2).' گیگابایت' }}</p>
                                        </div>
                                    </div>
                                    <div class="mt-5 flex flex-wrap justify-between gap-2 text-sm"><span class="text-[#71818B]">تاریخ انقضا</span><span class="font-bold text-[#1B2935]">{{ \App\Support\PersianCalendar::utcDateTime($serviceLiveUsage['expireAt']) }}</span></div>
                                    <p class="mt-4 text-xs leading-7 text-[#71818B]">آخرین بررسی: {{ \App\Support\PersianCalendar::utcDateTime($serviceUsageCheckedAt) }} · این اطلاعات لحظه‌ای به‌روز نمی‌شوند.</p>
                                </div>
                            @else
                                <p class="mt-5 rounded-2xl bg-[#F6F8FA] p-4 text-sm leading-7 text-[#71818B]">
                                    {{ $selectedService->canRequestLink ? 'برای دریافت وضعیت، مصرف و تاریخ انقضا، «بررسی وضعیت» را بزنید.' : 'اطلاعات به‌روز این سرویس فعلاً قابل دریافت نیست.' }}
                                </p>
                            @endif
                        </section>

                        @if ($resellerServiceStatusEnabled === true
                            && (($serviceLiveUsage['status'] ?? null) === 'active'
                                || ($serviceLiveUsage['status'] ?? null) === 'disabled'
                                || $serviceStatusConfirmToken !== null || $serviceStatusNotice !== null || $serviceStatusError !== null))
                            <section class="dg-card mt-5 p-5 sm:p-7" aria-label="مدیریت سرویس">
                                <h3 class="dg-heading">مدیریت سرویس</h3>
                                <p class="mt-2 text-xs leading-7 text-[#71818B]">توقف موقت اتصال، زمان انقضا و حجم سرویس را تغییر نمی‌دهد.</p>
                                @if ($serviceStatusError)
                                    <p role="alert" class="mt-4 rounded-xl bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">{{ $serviceStatusError }}</p>
                                @endif
                                @if ($serviceStatusNotice)
                                    <p role="status" class="mt-4 rounded-xl bg-[#EAF8F4] p-4 text-sm leading-7 text-[#087F73]">{{ $serviceStatusNotice }}</p>
                                @endif
                                @if ($serviceStatusConfirmToken !== null)
                                    <div class="mt-5 rounded-2xl border border-[#E7ECEF] bg-[#F6F8FA] p-4">
                                        <p class="text-sm font-bold text-[#1B2935]">تأیید {{ $serviceStatusConfirmMode === 'disable' ? 'غیرفعال‌سازی' : 'فعال‌سازی مجدد' }}</p>
                                        <p class="mt-2 break-words text-xs leading-7 text-[#71818B]">{{ $serviceStatusConfirmMode === 'disable' ? 'غیرفعال‌سازی' : 'فعال‌سازی مجدد' }} فقط برای سرویس «{{ $labels[$item->orderId] ?? $item->productName }}» (سفارش #{{ $item->orderId }}) درخواست می‌شود. نتیجه را با «بررسی وضعیت» دنبال کنید.</p>
                                        <div class="mt-4 flex flex-wrap gap-3">
                                            <button type="button" wire:click="confirmServiceStatus" wire:loading.attr="disabled" wire:target="confirmServiceStatus"
                                                class="dg-btn-primary">تأیید درخواست<span wire:loading.delay wire:target="confirmServiceStatus" role="status" class="text-xs font-semibold">در حال ثبت درخواست…</span></button>
                                            <button type="button" wire:click="cancelServiceStatus" wire:target="cancelServiceStatus,confirmServiceStatus" wire:loading.attr="disabled" class="dg-btn-secondary">انصراف</button>
                                        </div>
                                    </div>
                                @elseif (($serviceLiveUsage['status'] ?? null) === 'active')
                                    <button type="button" wire:click="prepareServiceStatus('disable')" wire:loading.attr="disabled" wire:target="prepareServiceStatus"
                                        class="dg-btn-secondary mt-5">⏸️ غیرفعال‌سازی سرویس<span wire:loading.delay wire:target="prepareServiceStatus" role="status" class="text-xs font-semibold">در حال بررسی…</span></button>
                                @elseif (($serviceLiveUsage['status'] ?? null) === 'disabled')
                                    @if ($serviceStatusCanResume)
                                        <button type="button" wire:click="prepareServiceStatus('enable')" wire:loading.attr="disabled" wire:target="prepareServiceStatus"
                                            class="dg-btn-primary mt-5">✅ فعال‌سازی مجدد<span wire:loading.delay wire:target="prepareServiceStatus" role="status" class="text-xs font-semibold">در حال بررسی…</span></button>
                                    @else
                                        <p class="mt-4 rounded-xl bg-[#F6F8FA] p-4 text-xs leading-7 text-[#71818B]">فعال‌سازی این سرویس نیازمند بررسی پشتیبانی است.</p>
                                    @endif
                                @endif
                            </section>
                        @endif

                        @if ($renewalAvailable || $renewalOrder !== null)
                            <section class="dg-card mt-5 p-5 sm:p-7" aria-label="تمدید سرویس">
                                <h3 class="dg-heading">تمدید سرویس</h3>
                                <p class="mt-2 text-xs leading-7 text-[#71818B]">تمدید روی همین اشتراک انجام می‌شود؛ لینک و تنظیمات اتصال مشتری تغییر نمی‌کند.</p>

                                @if ($renewalError)
                                    <p role="alert" class="mt-5 rounded-xl bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">{{ $renewalError }}</p>
                                @endif

                                @if ($renewalOrder !== null)
                                    @php
                                        $renewalStatus = match ($renewalOrder->status) {
                                            'pending_payment' => 'در انتظار پرداخت',
                                            'paid' => 'پرداخت ثبت‌شده؛ تمدید در انتظار اعمال',
                                            'fulfilled' => 'تمدید در سوابق تکمیل شده است',
                                            'needs_review' => 'نیازمند بررسی',
                                            'failed' => 'نیازمند پیگیری',
                                            default => 'وضعیت سفارش را بررسی کنید',
                                        };
                                    @endphp
                                    <div class="mt-5 rounded-2xl bg-[#F6F8FA] p-4 text-sm">
                                        <p class="font-bold text-[#1B2935]">آخرین سفارش تمدید #{{ $renewalOrder->id }}</p>
                                        <p class="dg-status mt-2" data-tone="{{ match ($renewalOrder->status) { 'needs_review', 'pending_payment', 'paid' => 'warning', 'failed' => 'danger', default => 'neutral' } }}">{{ $renewalStatus }}</p>
                                        <div class="mt-3 flex flex-wrap justify-between gap-2 border-t border-[#E7ECEF] pt-3">
                                            <span class="text-[#71818B]">مبلغ سفارش</span>
                                            <span class="font-bold text-[#087F73]">{{ number_format(intdiv($renewalOrder->totalPriceRials, 10)) }}{{ $renewalOrder->totalPriceRials % 10 === 0 ? '' : '.'.($renewalOrder->totalPriceRials % 10) }} تومان</span>
                                        </div>
                                    </div>
                                    @if ($renewalOrder->status === 'paid')
                                        <p class="mt-3 text-xs leading-7 text-[#71818B]">مبلغ از اعتبار کسر شده است؛ اعمال تمدید در شبکه ممکن است زمان ببرد. از پرداخت مجدد خودداری کنید.</p>
                                    @elseif ($renewalOrder->status === 'pending_payment')
                                        <p class="mt-3 text-xs leading-7 text-[#71818B]">تا زمان تأیید پرداخت، مبلغی از اعتبار شما کسر نمی‌شود.</p>
                                        @if ($renewalConfirmToken !== null && $renewalConfirmAmountRials !== null && $renewalConfirmBalanceRials !== null)
                                            <div class="dg-card-accent mt-5 p-4 text-sm">
                                                <h4 class="font-extrabold text-[#1B2935]">تأیید پرداخت تمدید</h4>
                                                 <p class="mt-2 break-words text-xs leading-6 text-[#536C76]">تمدید سرویس «{{ $labels[$item->orderId] ?? $item->productName }}» · سفارش #{{ $renewalOrder->id }}</p>
                                                <div class="mt-4 flex justify-between gap-2"><span>مبلغ برداشت</span><span class="font-bold">{{ number_format(intdiv($renewalConfirmAmountRials, 10)) }} تومان</span></div>
                                                <div class="mt-3 flex justify-between gap-2"><span>اعتبار فعلی</span><span class="font-bold">{{ number_format(intdiv($renewalConfirmBalanceRials, 10)) }} تومان</span></div>
                                                <div class="mt-3 flex justify-between gap-2 border-t border-[#CCEDE4] pt-3"><span>اعتبار پس از پرداخت</span><span class="font-bold">{{ number_format(intdiv($renewalConfirmBalanceRials - $renewalConfirmAmountRials, 10)) }} تومان</span></div>
                                                <p class="mt-3 text-xs leading-6 text-[#536C76]">با تأیید، مبلغ فقط یک بار برداشت می‌شود. تمدید سرویس پس از پردازش جداگانه تکمیل خواهد شد.</p>
                                                <button type="button" wire:click="confirmRenewalPayment" wire:loading.attr="disabled" wire:target="confirmRenewalPayment" class="dg-btn-primary mt-5 min-h-12 w-full">تأیید و پرداخت تمدید<span wire:loading.delay wire:target="confirmRenewalPayment" role="status" class="text-xs font-semibold">در حال ثبت پرداخت…</span></button>
                                                <button type="button" wire:click="cancelRenewal" wire:target="cancelRenewal,confirmRenewalPayment,createServiceRenewal" wire:loading.attr="disabled" class="dg-btn-secondary mt-3 w-full">انصراف</button>
                                            </div>
                                        @elseif (config('sales.renewal_enabled', false) === true && config('sales.wallet_settlement_enabled') === true)
                                            <button type="button" wire:click="prepareRenewalPayment" wire:loading.attr="disabled" wire:target="prepareRenewalPayment" class="dg-btn-primary mt-5 min-h-12 w-full">پرداخت تمدید با اعتبار<span wire:loading.delay wire:target="prepareRenewalPayment" role="status" class="text-xs font-semibold">در حال بررسی اعتبار…</span></button>
                                        @else
                                            <p class="mt-4 text-xs text-[#71818B]">پرداخت تمدید فعلاً در دسترس نیست.</p>
                                        @endif
                                    @endif
                                @endif

                                @if ($renewalPreview !== null)
                                    <div class="dg-card-accent mt-5 p-5">
                                        <h4 class="text-sm font-extrabold text-[#1B2935]">پیشنهاد تمدید</h4>
                                        <p class="mt-3 text-sm text-[#536C76]">{{ $renewalPreview['name'] }} · {{ number_format($renewalPreview['days']) }} روز{{ $renewalPreview['gb'] === null ? ' · حجم نامحدود' : ' · '.number_format($renewalPreview['gb']).' گیگابایت' }}</p>
                                        <p class="dg-stat mt-4">{{ number_format(intdiv($renewalPreview['amount'], 10)) }}{{ $renewalPreview['amount'] % 10 === 0 ? '' : '.'.($renewalPreview['amount'] % 10) }} <span class="text-xs font-medium">تومان</span></p>
                                        <p class="mt-3 text-xs leading-7 text-[#536C76]">مبلغ با نرخ فعلی محاسبه شده است. ثبت سفارش به‌معنای پرداخت نیست.</p>
                                        @if (config('sales.renewal_enabled', false) === true)
                                            <button type="button" wire:click="createServiceRenewal" wire:loading.attr="disabled" wire:target="createServiceRenewal" class="dg-btn-primary mt-5 min-h-12 w-full">ثبت سفارش تمدید بدون پرداخت<span wire:loading.delay wire:target="createServiceRenewal" role="status" class="text-xs font-semibold">در حال ثبت سفارش…</span></button>
                                        @else
                                            <p class="mt-4 text-xs text-[#845C23]">ثبت سفارش تمدید فعلاً در دسترس نیست.</p>
                                        @endif
                                        <button type="button" wire:click="cancelRenewal" wire:target="cancelRenewal,confirmRenewalPayment,createServiceRenewal" wire:loading.attr="disabled" class="dg-btn-secondary mt-3 w-full">بازگشت</button>
                                    </div>
                                @elseif ($renewalAvailable && ($renewalOrder === null || ! in_array($renewalOrder->status, ['pending_payment', 'paid'], true)))
                                    <button type="button" wire:click="previewServiceRenewal" wire:loading.attr="disabled" wire:target="previewServiceRenewal" class="dg-btn-secondary mt-5 min-h-12 w-full">بررسی قیمت تمدید<span wire:loading.delay wire:target="previewServiceRenewal" role="status" class="text-xs font-semibold">در حال بررسی قیمت…</span></button>
                                @endif
                            </section>
                        @endif
                    @else
                        @if ($serviceDetailError)
                            <p role="alert" class="mb-5 rounded-xl bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">{{ $serviceDetailError }}</p>
                        @endif
                    <div class="mb-5 grid grid-cols-2 gap-3">
                        <section class="dg-card p-4">
                            <p class="text-xs text-[#71818B]">کل سرویس‌ها</p>
                            <p class="dg-stat mt-2">{{ number_format($counts['total']) }}</p>
                        </section>
                        <section class="dg-card p-4">
                            <p class="text-xs text-[#71818B]">فعال در سوابق داخلی</p>
                            <p class="dg-stat mt-2">{{ number_format($counts['activeRecorded']) }}</p>
                        </section>
                    </div>
                    <div class="space-y-3">
                        @forelse ($services as $service)
                            @php
                                $serviceStatus = match ($service->status) {
                                    'active' => 'فعال در سوابق',
                                    'needs_review' => 'نیازمند بررسی',
                                    'provisioning' => 'در حال آماده‌سازی',
                                    'failed' => 'نیازمند پیگیری',
                                    default => 'نامشخص',
                                };
                            @endphp
                            <article wire:key="live-service-{{ $service->orderId }}" class="dg-card p-4 sm:p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h2 class="break-words text-base font-extrabold text-[#1B2935]">{{ $labels[$service->orderId] ?? $service->productName }}</h2>
                                        <p class="mt-1 break-words text-xs leading-6 text-[#71818B]">{{ $service->productName }} · {{ $service->durationDays }} روز{{ $service->trafficGb === null ? '' : ' · '.number_format($service->trafficGb).' گیگابایت' }}</p>
                                    </div>
                                    <span class="dg-status" data-tone="{{ match ($service->status) { 'failed' => 'danger', 'needs_review', 'provisioning' => 'warning', default => 'neutral' } }}">{{ $serviceStatus }}</span>
                                </div>
                                <p class="mt-2 break-words text-xs leading-6 text-[#71818B]">شماره سفارش: <span dir="ltr">{{ $service->orderId }}</span> · تاریخ ثبت: {{ \App\Support\PersianCalendar::utcDateTime($service->orderedAt) }}</p>
                                <button type="button" wire:click="openService({{ $service->orderId }})" wire:target="openService" wire:loading.attr="disabled"
                                    class="dg-btn-secondary mt-3 w-full sm:w-auto">مشاهده جزئیات</button>
                            </article>
                        @empty
                            <section class="dg-empty">
                                هنوز سرویسی برای نمایش ندارید.
                            </section>
                        @endforelse
                    </div>
                    @if ($serviceCursor !== null)
                        <button type="button" wire:click="firstServices" wire:target="firstServices" wire:loading.attr="disabled" class="dg-btn-secondary mt-5">بازگشت به اولین صفحه<span wire:loading.delay wire:target="firstServices" role="status" class="text-xs font-semibold">در حال بازگشت…</span></button>
                    @endif
                    @if ($nextServiceCursor !== null)
                        <button type="button" wire:click="nextServices({{ $nextServiceCursor }})" wire:target="nextServices" wire:loading.attr="disabled" class="dg-btn-primary mt-5">نمایش سرویس‌های بیشتر<span wire:loading.delay wire:target="nextServices" role="status" class="text-xs font-semibold">در حال دریافت…</span></button>
                    @endif
                    <p class="mt-5 text-xs leading-7 text-[#71818B]">این وضعیت‌ها از سوابق ثبت‌شده‌اند، نه بررسی لحظه‌ای اتصال.</p>
                    @endif
                @elseif ($section === 'reports')
                    <section aria-labelledby="report-page-title">
                        <div class="mb-6 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                            <div class="min-w-0">
                                <p class="mb-1 text-xs font-semibold text-[#087F73]">عملکرد نمایندگی</p>
                                <h1 id="report-page-title" class="dg-heading">گزارش فروش</h1>
                                <p class="mt-2 text-sm leading-7 text-[#617380]">مرور فروش تسویه‌شده و وضعیت فعلی حساب شما</p>
                            </div>
                            <div class="shrink-0">
                                <p class="mb-2 text-xs font-semibold text-[#617380]">بازه گزارش</p>
                                <div class="inline-flex gap-1 rounded-2xl border border-[#E7ECEF] bg-white p-1" role="group" aria-label="بازه گزارش">
                                    @foreach ([7, 30] as $days)
                                        <button type="button" wire:click="selectReportDays({{ $days }})" wire:target="selectReportDays" wire:loading.attr="disabled"
                                            class="{{ $report['days'] === $days ? 'dg-btn-primary' : 'dg-btn-secondary' }} min-w-24 px-4 transition-colors"
                                            aria-pressed="{{ $report['days'] === $days ? 'true' : 'false' }}">
                                            {{ $days }} روز
                                        </button>
                                    @endforeach
                                </div>
                                <p wire:loading.delay wire:target="selectReportDays" role="status" class="mt-2 text-xs font-semibold text-[#087F73]">در حال دریافت گزارش…</p>
                            </div>
                        </div>

                        <section class="dg-card-accent relative overflow-hidden bg-gradient-to-bl from-[#E4F8F1] via-[#F4FBF8] to-white p-6 sm:p-8" aria-labelledby="report-revenue-title">
                            <div class="pointer-events-none absolute -left-14 -top-20 h-52 w-52 rounded-full border-[24px] border-[#C9ECE2]/60" aria-hidden="true"></div>
                            <div class="relative z-10 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-[#087F73]">خلاصه عملکرد فروش</p>
                                    <h2 id="report-revenue-title" class="mt-2 text-base font-extrabold text-[#1B2935]">مجموع فروش تسویه‌شده</h2>
                                    <p class="dg-stat mt-3 block" dir="ltr">{{ number_format(intdiv($report['revenueRials'], 10)) }}</p>
                                    <p class="mt-1 text-sm font-semibold text-[#536C76]">تومان</p>
                                </div>
                                <div class="min-w-0 sm:max-w-56 sm:text-left">
                                    <span class="dg-status" data-tone="success">بازه {{ $report['days'] }} روزه</span>
                                    <p class="mt-3 break-words text-xs leading-7 text-[#536C76]">
                                        {{ \App\Support\PersianCalendar::date($report['from']) }} تا {{ \App\Support\PersianCalendar::date($report['to']) }}
                                        <span class="whitespace-nowrap">(تهران)</span>
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section class="mt-5" aria-label="شاخص‌های فروش و حساب">
                            <div class="grid grid-cols-3 gap-2.5 sm:gap-3">
                                @foreach ([
                                    ['خریدهای جدید', $report['sales'], true],
                                    ['تمدیدها', $report['renewals'], true],
                                    ['تراکنش‌های تأییدشده', $report['transactions'], true],
                                    ['سفارش‌های در انتظار پرداخت', $report['pending'], false],
                                    ['کل سرویس‌های ثبت‌شده', $report['services'], false],
                                    ['سرویس‌های دارای نام', $report['namedServices'], false],
                                ] as [$label, $value, $isHistorical])
                                    <section class="dg-card flex min-w-0 min-h-28 flex-col justify-between gap-2 rounded-[18px] p-2.5 sm:min-h-32 sm:p-4">
                                        <div class="flex min-w-0 items-start gap-1.5">
                                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ $isHistorical ? 'bg-[#14B8A6]' : 'bg-[#A9BAC3]' }}" aria-hidden="true"></span>
                                            <p class="min-w-0 break-words text-[11px] leading-4 font-semibold text-[#536C76] sm:text-xs">{{ $label }}</p>
                                        </div>
                                        <p class="text-lg font-extrabold tabular-nums text-[#172A36] sm:text-xl" dir="ltr">{{ number_format($value) }}</p>
                                    </section>
                                @endforeach
                            </div>
                        </section>
                        <p class="mt-5 text-xs leading-7 text-[#71818B]">آمار فروش مربوط به بازه انتخابی است؛ سفارش‌های در انتظار پرداخت و تعداد سرویس‌ها، وضعیت فعلی حساب را نشان می‌دهند.</p>
                    </section>
                @endif
                @if ($salesBotUrl !== null && $section !== 'overview' && ! $readError)
                    <div class="mt-8 flex justify-center border-t border-[#E7ECEF] pt-5">
                        <a href="{{ $salesBotUrl }}" target="_blank" rel="noopener noreferrer"
                            x-on:click="const tg = window.Telegram?.WebApp; if (tg && tg.platform !== 'unknown' && typeof tg.openTelegramLink === 'function') { $event.preventDefault(); tg.openTelegramLink($el.href); }"
                            class="inline-flex min-h-11 items-center rounded-xl px-4 py-2 text-xs font-semibold text-[#087F73] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14B8A6]">
                            بازگشت به بات فروش ←
                        </a>
                    </div>
                @endif
            </main>
            <footer class="hidden px-5 pb-6 text-center text-xs text-[#8A98A2] lg:block">DenaGate · پنل اختصاصی نمایندگان</footer>
        </div>
    </div>

    {{-- Native mobile app-style navigation, available without a JS drawer. --}}
    <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-[#E7ECEF] bg-white/95 px-2 pt-2 backdrop-blur-xl lg:hidden"
        style="padding-bottom: max(8px, env(safe-area-inset-bottom), var(--tg-content-safe-area-inset-bottom, 0px)); padding-inline-start: max(8px, env(safe-area-inset-right), var(--tg-content-safe-area-inset-right, 0px)); padding-inline-end: max(8px, env(safe-area-inset-left), var(--tg-content-safe-area-inset-left, 0px));"
        aria-label="دسترسی سریع پنل">
        @foreach ([
            ['overview', 'نمای کلی'],
            ['services', 'سرویس‌ها'],
            ['credit', 'اعتبار من'],
            ['reports', 'گزارش‌ها'],
        ] as [$key, $label])
            <button type="button" wire:click="showSection('{{ $key }}')" wire:target="showSection" wire:loading.attr="disabled"
                @class([
                    'mx-1 flex min-h-14 flex-col items-center justify-center gap-1 rounded-2xl px-1 text-[11px] font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-[#14B8A6]',
                    'bg-[#EAF8F4] text-[#087F73]' => $section === $key,
                    'text-[#84939D]' => $section !== $key,
                ])
                @if ($section === $key) aria-current="page" @endif>
                @if ($key === 'overview')
                    <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/></svg>
                @elseif ($key === 'services')
                    <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3" width="16" height="7" rx="2"/><rect x="4" y="14" width="16" height="7" rx="2"/><path d="M8 7h.01M8 18h.01"/></svg>
                @elseif ($key === 'credit')
                    <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="15" rx="3"/><path d="M3 10h18M16 15h2"/></svg>
                @else
                    <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h16M7 16v-5M12 16V5M17 16V9"/></svg>
                @endif
                <span>{{ $label }}</span>
            </button>
        @endforeach
    </nav>
</div>
