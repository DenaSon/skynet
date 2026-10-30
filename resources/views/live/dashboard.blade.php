<div class="min-h-dvh bg-[#F6F8FA]">
    <div class="mx-auto flex min-h-dvh max-w-[1440px]">
        {{-- Compact desktop navigation; the same four actions stay accessible on mobile. --}}
        <aside class="hidden w-64 shrink-0 border-l border-[#E7ECEF] bg-white px-5 py-8 lg:flex lg:flex-col">
            <div class="mb-12 flex items-center gap-3 px-2">
                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#14B8A6] text-xl font-black text-white shadow-sm">D</div>
                <div>
                    <p class="text-lg font-extrabold tracking-tight text-[#1B2935]" dir="ltr">DenaGate</p>
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
                    <button type="button" wire:click="showSection('{{ $key }}')"
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
                فضای اختصاصی نمایندگان دناگیت
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 border-b border-[#E7ECEF] bg-white/95 backdrop-blur-xl">
                <div class="mx-auto flex h-[76px] max-w-6xl items-center justify-between gap-3 px-5 md:px-9">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[14px] bg-[#14B8A6] text-lg font-black text-white lg:hidden">D</span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-medium text-[#71818B]">پنل نمایندگی</p>
                            <p class="truncate text-base font-bold text-[#1B2935]">دناگیت لایو</p>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="hidden items-center gap-2 rounded-full bg-[#EAF8F4] px-3 py-1.5 text-xs font-semibold text-[#087F73] sm:inline-flex">
                            <span class="h-1.5 w-1.5 rounded-full bg-[#14B8A6]" aria-hidden="true"></span>
                            حساب نماینده
                        </span>
                        <form method="POST" action="{{ route('denagate-live.logout') }}">
                            @csrf
                            <button type="submit" class="rounded-xl border border-[#E2E8EC] bg-white px-3.5 py-2 text-xs font-semibold text-[#586A78] transition-colors hover:bg-[#F6F8FA] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14B8A6]">
                                خروج امن
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <main id="live-main" class="mx-auto w-full max-w-6xl flex-1 px-4 pb-32 pt-7 sm:px-6 md:px-9 md:pt-10 lg:pb-12" aria-live="polite">
                <div wire:loading.delay role="status" class="mb-4 inline-flex items-center rounded-full bg-[#EAF8F4] px-3 py-1 text-xs font-semibold text-[#087F73]">
                    در حال بارگذاری…
                </div>

                @if ($readError)
                    <section class="rounded-[28px] border border-[#F0E0C9] bg-white p-7 shadow-sm" role="alert">
                        <h1 class="text-xl font-extrabold text-[#1B2935]">دریافت اطلاعات امکان‌پذیر نیست</h1>
                        <p class="mt-3 text-sm leading-8 text-[#687987]">لطفاً کمی بعد دوباره تلاش کنید.</p>
                        <button type="button" wire:click="$refresh" class="mt-5 rounded-xl bg-[#14B8A6] px-5 py-2.5 text-sm font-bold text-white">تلاش دوباره</button>
                    </section>
                @elseif ($section === 'overview')
                    <section class="relative overflow-hidden rounded-[28px] border border-[#E7ECEF] bg-gradient-to-bl from-[#E9F8F3] via-white to-white p-6 shadow-[0_12px_40px_rgba(20,45,55,0.035)] md:p-10">
                        <div class="relative z-10">
                            <span class="mb-4 inline-flex items-center gap-2 rounded-full border border-[#CCEDE4] bg-white/80 px-3 py-1.5 text-xs font-semibold text-[#087F73]">
                                <span class="h-2 w-2 rounded-full bg-[#14B8A6]" aria-hidden="true"></span>
                                پنل نمایندگی
                            </span>
                            <h1 class="max-w-3xl break-words text-[25px] font-extrabold leading-[1.75] tracking-tight text-[#1B2935] md:text-4xl">
                                خوش آمدید، <bdi>{{ $name }}</bdi>
                            </h1>
                            <p class="mt-2 max-w-xl text-sm leading-8 text-[#617380]">خلاصه حساب و فروش نمایندگی شما</p>
                            <button type="button" wire:click="showSection('shop')" class="mt-6 inline-flex min-h-12 items-center justify-center rounded-2xl bg-[#14B8A6] px-6 py-3 text-sm font-bold text-white shadow-sm transition-colors hover:bg-[#0D9688] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#087F73]">
                                خرید سرویس <span class="mr-2" aria-hidden="true">←</span>
                            </button>
                        </div>
                        <div class="pointer-events-none absolute -bottom-24 -left-24 h-60 w-60 rounded-full bg-[#14B8A6]/10 blur-3xl" aria-hidden="true"></div>
                    </section>

                    <h2 class="mb-5 mt-8 text-lg font-extrabold text-[#1B2935] md:text-xl">نمای کلی</h2>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 md:gap-5">
                        <section class="rounded-[26px] border border-[#E7ECEF] bg-white p-5 shadow-[0_10px_30px_rgba(14,34,44,0.035)] md:p-7">
                            <p class="text-xs font-semibold text-[#71818B]">اعتبار قابل استفاده</p>
                            <p class="mt-3 break-words text-2xl font-extrabold text-[#1B2935]" dir="ltr">{{ number_format(intdiv($wallet->balanceRials, 10)) }} <span class="text-xs font-normal text-[#687987]">تومان</span></p>
                            <button type="button" wire:click="showSection('credit')" class="mt-4 text-xs font-bold text-[#087F73]">مشاهده اعتبار ←</button>
                        </section>
                        <section class="rounded-[26px] border border-[#E7ECEF] bg-white p-5 shadow-[0_10px_30px_rgba(14,34,44,0.035)] md:p-7">
                            <p class="text-xs font-semibold text-[#71818B]">سرویس‌های ثبت‌شده</p>
                            <p class="mt-3 text-2xl font-extrabold text-[#1B2935]">{{ number_format($counts['total']) }}</p>
                            <p class="mt-1 text-xs text-[#71818B]">فعال در سوابق: {{ number_format($counts['activeRecorded']) }}</p>
                            <button type="button" wire:click="showSection('services')" class="mt-4 text-xs font-bold text-[#087F73]">مشاهده سرویس‌ها ←</button>
                        </section>
                        <section class="rounded-[26px] border border-[#E7ECEF] bg-white p-5 shadow-[0_10px_30px_rgba(14,34,44,0.035)] md:p-7">
                            <p class="text-xs font-semibold text-[#71818B]">فروش تأییدشده · ۳۰ روز اخیر</p>
                            <p class="mt-3 break-words text-2xl font-extrabold text-[#1B2935]" dir="ltr">{{ number_format(intdiv($report['revenueRials'], 10)) }} <span class="text-xs font-normal text-[#687987]">تومان</span></p>
                            <p class="mt-1 text-xs text-[#71818B]">{{ number_format($report['transactions']) }} تراکنش تسویه‌شده</p>
                            <button type="button" wire:click="showSection('reports')" class="mt-4 text-xs font-bold text-[#087F73]">مشاهده گزارش ←</button>
                        </section>
                    </div>
                @elseif ($section === 'shop')
                    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h1 class="text-2xl font-extrabold text-[#1B2935]">خرید سرویس</h1>
                            <p class="mt-2 text-sm leading-7 text-[#71818B]">پلن مناسب را انتخاب کنید و مبلغ آن را ببینید.</p>
                        </div>
                        <button type="button" wire:click="showSection('overview')" class="min-h-11 rounded-xl border border-[#E7ECEF] bg-white px-4 py-2 text-sm font-semibold text-[#536C76] focus-visible:outline-2 focus-visible:outline-[#14B8A6]">بستن</button>
                    </div>

                    @if ($createdOrder !== null)
                        <section class="rounded-[26px] border border-[#CCEDE4] bg-white p-6 shadow-sm sm:p-8" role="status">
                            <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-[#E7F8F3] text-[#087F73]">
                                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                            </div>
                            @php
                                $orderStatusText = match ($createdOrder->status) {
                                    'pending_payment' => 'در انتظار پرداخت',
                                    'paid' => 'پرداخت تأیید شده؛ در انتظار آماده‌سازی سرویس',
                                    'fulfilled' => 'سرویس در سوابق ثبت شده است',
                                    'needs_review' => 'در انتظار بررسی',
                                    'failed' => 'نیازمند پیگیری',
                                    default => 'وضعیت سفارش را بررسی کنید',
                                };
                            @endphp
                            <h2 class="text-xl font-extrabold text-[#1B2935]">سفارش #{{ $createdOrder->id }}</h2>
                            <p class="mt-2 text-sm leading-7 text-[#71818B]">{{ $orderStatusText }}</p>
                            @if ($createdOrder->status === 'pending_payment')
                                <p class="mt-2 text-xs leading-7 text-[#71818B]">تا زمان تأیید پرداخت، مبلغی از اعتبار شما کسر نمی‌شود و سرویسی فعال نمی‌شود.</p>
                            @elseif ($createdOrder->status === 'paid')
                                <p class="mt-2 text-xs leading-7 text-[#71818B]">مبلغ پرداخت ثبت شده است؛ آماده‌سازی سرویس ممکن است زمان ببرد.</p>
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
                                    <div class="mt-5 rounded-2xl border border-[#CCEDE4] bg-[#F3FBF8] p-5">
                                        <h3 class="text-base font-extrabold text-[#1B2935]">تأیید پرداخت از اعتبار</h3>
                                        <div class="mt-4 flex flex-wrap justify-between gap-2 text-sm text-[#536C76]"><span>مبلغ برداشت</span><span dir="ltr" class="font-bold">{{ number_format(intdiv($walletConfirmAmountRials, 10)) }}{{ $walletConfirmAmountRials % 10 === 0 ? '' : '.'.($walletConfirmAmountRials % 10) }} تومان</span></div>
                                        <div class="mt-3 flex flex-wrap justify-between gap-2 text-sm text-[#536C76]"><span>اعتبار فعلی</span><span dir="ltr">{{ number_format(intdiv($walletConfirmBalanceRials, 10)) }}{{ $walletConfirmBalanceRials % 10 === 0 ? '' : '.'.($walletConfirmBalanceRials % 10) }} تومان</span></div>
                                        <div class="mt-3 flex flex-wrap justify-between gap-2 border-t border-[#CCEDE4] pt-3 text-sm font-bold text-[#1B2935]"><span>اعتبار پس از پرداخت</span><span dir="ltr">{{ number_format(intdiv($walletConfirmBalanceRials - $walletConfirmAmountRials, 10)) }}{{ ($walletConfirmBalanceRials - $walletConfirmAmountRials) % 10 === 0 ? '' : '.'.(($walletConfirmBalanceRials - $walletConfirmAmountRials) % 10) }} تومان</span></div>
                                        <p class="mt-4 text-xs leading-7 text-[#536C76]">با تأیید، مبلغ بالا یک بار از اعتبار شما کسر می‌شود.</p>
                                        <button type="button" wire:click="confirmWalletPayment" wire:loading.attr="disabled" wire:target="confirmWalletPayment"
                                            class="mt-5 min-h-12 w-full rounded-xl bg-[#14B8A6] px-5 py-3 text-sm font-bold text-white disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-[#087F73]">تأیید و پرداخت</button>
                                        <button type="button" wire:click="cancelWalletPayment" class="mt-3 min-h-11 w-full rounded-xl border border-[#D9E4E7] bg-white px-5 py-2 text-sm font-semibold text-[#536C76]">انصراف از پرداخت</button>
                                    </div>
                                @elseif (config('sales.wallet_settlement_enabled') === true)
                                    <button type="button" wire:click="prepareWalletPayment" wire:loading.attr="disabled" wire:target="prepareWalletPayment"
                                        class="mt-5 min-h-12 w-full rounded-xl bg-[#14B8A6] px-5 py-3 text-sm font-bold text-white disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-[#087F73]">پرداخت با اعتبار</button>
                                @else
                                    <p class="mt-4 text-xs leading-7 text-[#71818B]">پرداخت از اعتبار در حال حاضر در دسترس نیست. می‌توانید سفارش خود را در بات فروش پیگیری کنید.</p>
                                @endif
                            @endif
                            <button type="button" wire:click="backToPlans" class="mt-5 min-h-11 rounded-xl border border-[#E7ECEF] bg-white px-5 py-2.5 text-sm font-bold text-[#087F73]">خرید سرویس دیگر</button>
                        </section>
                    @elseif ($selectedPlan === null)
                        @if ($planUnavailable || $quoteError || $orderError)
                            <p role="alert" class="mb-5 rounded-2xl border border-[#F0E0C9] bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">
                                {{ $quoteError ?? $orderError ?? 'این پلن در دسترس نیست. لطفاً یکی از پلن‌های موجود را انتخاب کنید.' }}
                            </p>
                        @endif
                        @if (count($recentOrders) > 0)
                            <section class="mb-6 rounded-[24px] border border-[#E7ECEF] bg-white p-5 shadow-sm sm:p-6">
                                <h2 class="text-base font-extrabold text-[#1B2935]">سفارش‌های اخیر من</h2>
                                <p class="mt-2 text-xs leading-6 text-[#71818B]">برای مشاهده وضعیت یا ادامه پرداخت، سفارش خود را انتخاب کنید.</p>
                                <div class="mt-4 divide-y divide-[#EFF2F4]">
                                    @foreach ($recentOrders as $order)
                                        <button type="button" wire:key="live-order-{{ $order->id }}" wire:click="openOrder({{ $order->id }})"
                                            class="flex min-h-14 w-full items-center justify-between gap-3 py-3 text-right focus-visible:outline-2 focus-visible:outline-[#087F73]">
                                            <span class="min-w-0">
                                                <span class="block text-sm font-bold text-[#1B2935]">#{{ $order->id }} · {{ $order->productName }}</span>
                                                <span class="mt-1 block text-xs text-[#71818B]">{{ match ($order->status) {
                                                    'pending_payment' => 'در انتظار پرداخت',
                                                    'paid' => 'پرداخت‌شده؛ در انتظار آماده‌سازی',
                                                    'fulfilled' => 'ثبت فعال‌سازی در سوابق',
                                                    'needs_review' => 'در انتظار بررسی',
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
                            <section class="rounded-[24px] border border-[#E7ECEF] bg-white px-6 py-10 text-center text-sm leading-8 text-[#71818B]">
                                در حال حاضر پلنی برای ارائه وجود ندارد.
                            </section>
                        @else
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ($catalogPresets as $item)
                                    <article wire:key="live-plan-{{ $item->plan->id }}" class="flex flex-col rounded-[24px] border border-[#E7ECEF] bg-white p-5 shadow-sm">
                                        <h2 class="break-words text-base font-extrabold text-[#1B2935]">{{ $item->plan->name }}</h2>
                                        <p class="mt-2 text-xs leading-7 text-[#71818B]">
                                            {{ $item->plan->durationDays }} روز · {{ $item->plan->trafficGb === null ? 'حجم نامحدود' : number_format($item->plan->trafficGb).' گیگابایت' }}
                                        </p>
                                        <p class="mt-5 text-xl font-extrabold text-[#087F73]" dir="ltr">{{ number_format(intdiv($item->quote->totalPriceRials, 10)) }}{{ $item->quote->totalPriceRials % 10 === 0 ? '' : '.'.($item->quote->totalPriceRials % 10) }} <span class="text-xs font-medium text-[#71818B]">تومان</span></p>
                                        <p class="mt-1 text-xs text-[#71818B]">با تخفیف نمایندگی {{ $item->quote->discountPercent }}٪</p>
                                        <button type="button" wire:click="selectPlan({{ $item->plan->id }})" class="mt-5 min-h-11 rounded-xl bg-[#EAF8F4] px-5 py-2.5 text-sm font-bold text-[#087F73] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14B8A6]">مشاهده پیش‌فاکتور</button>
                                    </article>
                                @endforeach
                                @foreach ($catalogBuilders as $builder)
                                    <article wire:key="live-builder-{{ $builder->id }}" class="flex flex-col rounded-[24px] border border-[#E7ECEF] bg-white p-5 shadow-sm">
                                        <h2 class="break-words text-base font-extrabold text-[#1B2935]">{{ $builder->name }}</h2>
                                        <p class="mt-2 text-xs leading-7 text-[#71818B]">مدت دلخواه: {{ $builder->minDays }} تا {{ $builder->maxDays }} روز</p>
                                        <p class="mt-1 text-xs leading-7 text-[#71818B]">{{ $builder->kind === 'time' ? 'حجم نامحدود' : 'حجم دلخواه: '.number_format($builder->minGb).' تا '.number_format($builder->maxGb).' گیگابایت' }}</p>
                                        <p class="mt-5 text-sm font-semibold text-[#087F73]">قیمت بر اساس انتخاب شما</p>
                                        <button type="button" wire:click="selectPlan({{ $builder->id }})" class="mt-auto min-h-11 rounded-xl bg-[#EAF8F4] px-5 py-2.5 text-sm font-bold text-[#087F73] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14B8A6]">تنظیم و محاسبه قیمت</button>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <button type="button" wire:click="backToPlans" class="mb-5 min-h-11 text-sm font-bold text-[#087F73] focus-visible:outline-2 focus-visible:outline-[#14B8A6]">→ بازگشت به پلن‌ها</button>
                        <section class="rounded-[26px] border border-[#E7ECEF] bg-white p-5 shadow-sm sm:p-7">
                            <h2 class="text-xl font-extrabold text-[#1B2935]">{{ $selectedPlan->name }}</h2>
                            <p class="mt-2 text-xs text-[#71818B]">{{ $selectedPlan->mode === 'builder' ? 'انتخاب مدت و حجم سرویس' : 'جزئیات پلن انتخاب‌شده' }}</p>

                            @if ($selectedPlan->mode === 'builder')
                                <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="quote-days" class="mb-2 block text-sm font-semibold text-[#536C76]">مدت سرویس (روز)</label>
                                        <input id="quote-days" type="number" min="{{ $selectedPlan->minDays }}" max="{{ $selectedPlan->maxDays }}" step="1" inputmode="numeric" wire:model="daysInput" class="min-h-12 w-full rounded-xl border border-[#D9E4E7] bg-white px-4 text-base text-[#1B2935] focus:border-[#14B8A6] focus:outline-none" />
                                        <p class="mt-2 text-xs text-[#71818B]">{{ $selectedPlan->minDays }} تا {{ $selectedPlan->maxDays }} روز</p>
                                    </div>
                                    @if ($selectedPlan->kind !== 'time')
                                        <div>
                                            <label for="quote-gb" class="mb-2 block text-sm font-semibold text-[#536C76]">حجم سرویس (گیگابایت)</label>
                                            <input id="quote-gb" type="number" min="{{ $selectedPlan->minGb }}" max="{{ $selectedPlan->maxGb }}" step="1" inputmode="numeric" wire:model="gbInput" class="min-h-12 w-full rounded-xl border border-[#D9E4E7] bg-white px-4 text-base text-[#1B2935] focus:border-[#14B8A6] focus:outline-none" />
                                            <p class="mt-2 text-xs text-[#71818B]">{{ number_format($selectedPlan->minGb) }} تا {{ number_format($selectedPlan->maxGb) }} گیگابایت</p>
                                        </div>
                                    @else
                                        <p class="self-center text-sm font-semibold text-[#536C76]">حجم نامحدود</p>
                                    @endif
                                </div>
                            @else
                                <p class="mt-5 text-sm font-semibold text-[#536C76]">{{ $selectedPlan->durationDays }} روز · {{ $selectedPlan->trafficGb === null ? 'حجم نامحدود' : number_format($selectedPlan->trafficGb).' گیگابایت' }}</p>
                            @endif

                            <button type="button" wire:click="previewQuote" class="mt-6 min-h-12 w-full rounded-xl bg-[#14B8A6] px-5 py-3 text-sm font-bold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#087F73]">محاسبه پیش‌فاکتور</button>

                            @if ($orderError)
                                <p role="alert" class="mt-5 rounded-2xl bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">{{ $orderError }}</p>
                            @endif

                            @if ($quoteError)
                                <p role="alert" class="mt-5 rounded-2xl bg-[#FFF9F1] p-4 text-sm leading-7 text-[#845C23]">{{ $quoteError }}</p>
                            @elseif ($planQuote !== null)
                                <div class="mt-6 rounded-2xl border border-[#CCEDE4] bg-[#F3FBF8] p-5" aria-label="پیش‌فاکتور نمایندگی">
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
                                        <span class="text-xl font-extrabold text-[#087F73]" dir="ltr">{{ number_format(intdiv($planQuote->totalPriceRials, 10)) }}{{ $planQuote->totalPriceRials % 10 === 0 ? '' : '.'.($planQuote->totalPriceRials % 10) }} <span class="text-xs font-medium">تومان</span></span>
                                    </div>
                                </div>
                                <p class="mt-4 text-xs leading-7 text-[#71818B]">مبلغ هنگام ثبت سفارش دوباره بررسی می‌شود. ثبت سفارش به‌معنای پرداخت نیست.</p>
                                @if ($confirmedQuoteHash !== null && $orderIntentKey !== null)
                                    <div class="mt-6 border-t border-[#E7ECEF] pt-6">
                                        <label for="customer-username" class="mb-2 block text-sm font-bold text-[#1B2935]">نام کاربری مشتری</label>
                                        <input id="customer-username" type="text" maxlength="60" autocomplete="off" dir="auto" wire:model="customerUsername"
                                            placeholder="مثلاً customer_01"
                                            class="min-h-12 w-full rounded-xl border border-[#D9E4E7] bg-white px-4 text-base text-[#1B2935] focus:border-[#14B8A6] focus:outline-none" />
                                        <p class="mt-2 text-xs leading-7 text-[#71818B]">نامی برای شناسایی سرویس این مشتری در پنل شما؛ شماره تماس نیاز نیست.</p>
                                        <button type="button" wire:click="confirmOrder" wire:loading.attr="disabled"
                                            wire:target="confirmOrder"
                                            class="mt-5 min-h-12 w-full rounded-xl bg-[#14B8A6] px-5 py-3 text-sm font-bold text-white disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#087F73]">
                                            تأیید و ثبت سفارش
                                        </button>
                                        <p class="mt-2 text-center text-xs text-[#71818B]">بدون برداشت از اعتبار</p>
                                    </div>
                                @endif
                            @endif
                        </section>
                    @endif
                @elseif ($section === 'credit')
                    <div class="mb-6">
                        <h1 class="text-2xl font-extrabold text-[#1B2935]">اعتبار من</h1>
                        <p class="mt-2 text-sm text-[#71818B]">موجودی و درخواست‌های شارژ حساب شما</p>
                    </div>
                    <section class="rounded-[28px] border border-[#CCEDE4] bg-gradient-to-bl from-[#E7F8F3] to-white p-7 shadow-sm md:p-9">
                        <p class="text-sm font-semibold text-[#087F73]">اعتبار قابل استفاده</p>
                        <p class="mt-4 break-words text-3xl font-extrabold text-[#1B2935]" dir="ltr">{{ number_format(intdiv($wallet->balanceRials, 10)) }} <span class="text-sm font-medium text-[#687987]">تومان</span></p>
                        <p class="mt-3 text-xs leading-7 text-[#617380]">مبلغ بر اساس تراکنش‌های ثبت‌شده حساب شما محاسبه شده است.</p>
                    </section>
                    <section class="mt-5 rounded-[26px] border border-[#E7ECEF] bg-white p-5 shadow-sm md:p-7">
                        <h2 class="mb-5 text-base font-extrabold">درخواست‌های اخیر شارژ</h2>
                        @forelse ($wallet->topups as $topup)
                            @php
                                $topupStatus = match ($topup['status']) {
                                    'approved' => 'تأییدشده',
                                    'rejected' => 'ردشده',
                                    'pending' => 'در انتظار بررسی',
                                    default => 'در حال بررسی',
                                };
                            @endphp
                            <div class="flex items-center justify-between gap-3 border-b border-[#F0F2F4] py-4 last:border-b-0">
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-[#1B2935]">{{ number_format(intdiv($topup['amount_rials'], 10)) }} تومان</p>
                                    <p class="mt-1 text-xs text-[#71818B]">{{ \App\Support\PersianCalendar::utcDateTime($topup['created_at']) }}</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-[#F4F7F8] px-3 py-1.5 text-xs font-semibold text-[#536C76]">{{ $topupStatus }}</span>
                            </div>
                        @empty
                            <p class="py-6 text-center text-sm text-[#71818B]">هنوز درخواست شارژی ثبت نشده است.</p>
                        @endforelse
                    </section>
                @elseif ($section === 'services')
                    <div class="mb-6">
                        <h1 class="text-2xl font-extrabold text-[#1B2935]">سرویس‌های من</h1>
                        <p class="mt-2 text-sm text-[#71818B]">اشتراک‌های ثبت‌شده برای مشتریان شما</p>
                    </div>
                    <div class="mb-5 grid grid-cols-2 gap-3">
                        <section class="rounded-[22px] border border-[#E7ECEF] bg-white p-5">
                            <p class="text-xs text-[#71818B]">کل سرویس‌ها</p>
                            <p class="mt-2 text-2xl font-extrabold">{{ number_format($counts['total']) }}</p>
                        </section>
                        <section class="rounded-[22px] border border-[#E7ECEF] bg-white p-5">
                            <p class="text-xs text-[#71818B]">فعال در سوابق داخلی</p>
                            <p class="mt-2 text-2xl font-extrabold text-[#087F73]">{{ number_format($counts['activeRecorded']) }}</p>
                        </section>
                    </div>
                    <div class="space-y-3">
                        @forelse ($services as $service)
                            @php
                                $serviceStatus = match ($service->status) {
                                    'active' => 'فعال در سوابق',
                                    'needs_review' => 'نیازمند بررسی',
                                    'provisioning' => 'در حال آماده‌سازی',
                                    'failed' => 'ناموفق',
                                    default => 'نامشخص',
                                };
                            @endphp
                            <article wire:key="live-service-{{ $service->orderId }}" class="rounded-[24px] border border-[#E7ECEF] bg-white p-5 shadow-sm md:p-6">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h2 class="break-words text-base font-extrabold text-[#1B2935]">{{ $labels[$service->orderId] ?? $service->productName }}</h2>
                                        <p class="mt-2 text-xs text-[#71818B]">{{ $service->productName }} · {{ $service->durationDays }} روز{{ $service->trafficGb === null ? '' : ' · '.number_format($service->trafficGb).' گیگابایت' }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-[#EAF8F4] px-3 py-1.5 text-xs font-semibold text-[#087F73]">{{ $serviceStatus }}</span>
                                </div>
                                <p class="mt-4 text-xs text-[#71818B]">شماره سفارش: <span dir="ltr">{{ $service->orderId }}</span> · تاریخ ثبت: {{ \App\Support\PersianCalendar::utcDateTime($service->orderedAt) }}</p>
                            </article>
                        @empty
                            <section class="rounded-[24px] border border-[#E7ECEF] bg-white p-8 text-center text-sm text-[#71818B]">
                                سرویسی برای نمایش وجود ندارد.
                            </section>
                        @endforelse
                    </div>
                    @if ($serviceCursor !== null)
                        <button type="button" wire:click="firstServices" class="mt-5 rounded-xl border border-[#E7ECEF] bg-white px-5 py-2.5 text-sm font-semibold text-[#536C76]">بازگشت به اولین صفحه</button>
                    @endif
                    @if ($nextServiceCursor !== null)
                        <button type="button" wire:click="nextServices({{ $nextServiceCursor }})" class="mt-5 rounded-xl bg-[#14B8A6] px-5 py-2.5 text-sm font-bold text-white">موارد بیشتر</button>
                    @endif
                    <p class="mt-5 text-xs leading-7 text-[#71818B]">وضعیت‌ها بر اساس سوابق ثبت‌شده‌اند و نمایشگر وضعیت لحظه‌ای اتصال نیستند.</p>
                @elseif ($section === 'reports')
                    <div class="mb-6">
                        <h1 class="text-2xl font-extrabold text-[#1B2935]">گزارش فروش</h1>
                        <p class="mt-2 text-sm text-[#71818B]">فروش و تراکنش‌های تأییدشده نمایندگی</p>
                    </div>
                    <div class="mb-6 inline-flex gap-1 rounded-2xl border border-[#E7ECEF] bg-white p-1" aria-label="بازه گزارش">
                        @foreach ([7, 30] as $days)
                            <button type="button" wire:click="selectReportDays({{ $days }})"
                                @class([
                                    'rounded-xl px-5 py-2.5 text-sm font-semibold transition-colors',
                                    'bg-[#EAF8F4] text-[#087F73]' => $report['days'] === $days,
                                    'text-[#71818B]' => $report['days'] !== $days,
                                ]) @if ($report['days'] === $days) aria-pressed="true" @else aria-pressed="false" @endif>
                                {{ $days }} روز
                            </button>
                        @endforeach
                    </div>
                    <section class="rounded-[28px] border border-[#CCEDE4] bg-gradient-to-bl from-[#E7F8F3] to-white p-7 md:p-9">
                        <p class="text-sm font-semibold text-[#087F73]">مجموع فروش تأییدشده</p>
                        <p class="mt-3 break-words text-3xl font-extrabold text-[#1B2935]" dir="ltr">{{ number_format(intdiv($report['revenueRials'], 10)) }} <span class="text-sm font-normal text-[#687987]">تومان</span></p>
                        <p class="mt-3 text-xs text-[#617380]">{{ \App\Support\PersianCalendar::date($report['from']) }} تا {{ \App\Support\PersianCalendar::date($report['to']) }} (تهران)</p>
                    </section>
                    <div class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3">
                        @foreach ([
                            ['خریدهای جدید', $report['sales']],
                            ['تمدیدها', $report['renewals']],
                            ['تراکنش‌های تأییدشده', $report['transactions']],
                            ['سفارش‌های در انتظار پرداخت', $report['pending']],
                            ['کل سرویس‌های ثبت‌شده', $report['services']],
                            ['سرویس‌های دارای نام', $report['namedServices']],
                        ] as [$label, $value])
                            <section class="rounded-[22px] border border-[#E7ECEF] bg-white p-5">
                                <p class="text-xs leading-6 text-[#71818B]">{{ $label }}</p>
                                <p class="mt-3 text-2xl font-extrabold text-[#1B2935]">{{ number_format($value) }}</p>
                            </section>
                        @endforeach
                    </div>
                    <p class="mt-5 text-xs leading-7 text-[#71818B]">فروش مربوط به بازه انتخاب‌شده است. تعداد سرویس‌ها و سفارش‌های در انتظار پرداخت، وضعیت فعلی حساب را نشان می‌دهد.</p>
                @endif
            </main>
            <footer class="hidden px-5 pb-6 text-center text-xs text-[#8A98A2] lg:block">DenaGate Live · پنل اختصاصی نمایندگان</footer>
        </div>
    </div>

    {{-- Native mobile app-style navigation, available without a JS drawer. --}}
    <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-[#E7ECEF] bg-white/95 px-2 pt-2 backdrop-blur-xl lg:hidden"
        style="padding-bottom: max(8px, env(safe-area-inset-bottom));"
        aria-label="دسترسی سریع پنل">
        @foreach ([
            ['overview', 'نمای کلی'],
            ['services', 'سرویس‌ها'],
            ['credit', 'اعتبار من'],
            ['reports', 'گزارش‌ها'],
        ] as [$key, $label])
            <button type="button" wire:click="showSection('{{ $key }}')"
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
