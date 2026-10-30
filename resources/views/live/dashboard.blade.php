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

                @if ($section === 'overview')
                    <section class="relative overflow-hidden rounded-[28px] border border-[#E7ECEF] bg-gradient-to-bl from-[#E9F8F3] via-white to-white p-6 shadow-[0_12px_40px_rgba(20,45,55,0.035)] md:p-10">
                        <div class="relative z-10">
                            <div class="mb-5 inline-flex items-center gap-2 rounded-full border border-[#CCEDE4] bg-white/80 px-3 py-1.5 text-xs font-semibold text-[#087F73]">
                                <span class="h-2 w-2 rounded-full bg-[#14B8A6]" aria-hidden="true"></span>
                                ورود امن انجام شد
                            </div>
                            <h1 class="max-w-3xl break-words text-[25px] font-extrabold leading-[1.75] tracking-tight text-[#1B2935] md:text-4xl">
                                خوش آمدید، <bdi>{{ $name }}</bdi>
                            </h1>
                            <p class="mt-2 max-w-xl text-sm leading-8 text-[#617380]">
                                به فضای اختصاصی نمایندگی دناگیت خوش آمدید. حساب تلگرام شما با موفقیت تأیید شده است.
                            </p>
                        </div>
                        <div class="pointer-events-none absolute -bottom-24 -left-24 h-60 w-60 rounded-full bg-[#14B8A6]/10 blur-3xl" aria-hidden="true"></div>
                    </section>

                    <div class="mb-5 mt-8 flex items-center justify-between gap-3">
                        <h2 class="text-lg font-extrabold text-[#1B2935] md:text-xl">دسترسی‌های من</h2>
                        <span class="text-xs text-[#8A98A2]">پنل نمایندگی</span>
                    </div>

                    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-5">
                        <section class="col-span-2 flex min-h-44 flex-col rounded-[26px] border border-[#E7ECEF] bg-white p-5 shadow-[0_10px_30px_rgba(14,34,44,0.035)] md:col-span-1 md:min-h-56 md:p-7">
                            <span class="mb-4 flex h-11 w-11 items-center justify-center rounded-2xl bg-[#E8F8F3] text-[#098D80]" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="6" rx="2"/><rect x="4" y="15" width="16" height="6" rx="2"/><path d="M8 6h.01M8 18h.01M12 12v3"/></svg>
                            </span>
                            <h3 class="text-base font-bold text-[#1B2935]">سرویس‌ها</h3>
                            <p class="mt-1 text-xs leading-7 text-[#71818B]">دسترسی به بخش اشتراک‌های مشتریان</p>
                            <button type="button" wire:click="showSection('services')" class="mt-auto inline-flex min-h-10 items-center gap-2 self-start pt-4 text-xs font-bold text-[#087F73] focus-visible:outline-2 focus-visible:outline-[#14B8A6]">
                                مشاهده بخش <span aria-hidden="true">←</span>
                            </button>
                        </section>

                        <section class="flex min-h-48 flex-col rounded-[26px] border border-[#E7ECEF] bg-white p-5 shadow-[0_10px_30px_rgba(14,34,44,0.035)] md:min-h-56 md:p-7">
                            <span class="mb-4 flex h-11 w-11 items-center justify-center rounded-2xl bg-[#E8F8F3] text-[#098D80]" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="15" rx="3"/><path d="M3 10h18M16 15h2"/></svg>
                            </span>
                            <h3 class="text-base font-bold text-[#1B2935]">اعتبار من</h3>
                            <p class="mt-1 text-xs leading-7 text-[#71818B]">موجودی و سوابق اعتبار</p>
                            <button type="button" wire:click="showSection('credit')" class="mt-auto inline-flex min-h-10 items-center gap-2 self-start pt-4 text-xs font-bold text-[#087F73] focus-visible:outline-2 focus-visible:outline-[#14B8A6]">
                                مشاهده <span aria-hidden="true">←</span>
                            </button>
                        </section>

                        <section class="flex min-h-48 flex-col rounded-[26px] border border-[#E7ECEF] bg-white p-5 shadow-[0_10px_30px_rgba(14,34,44,0.035)] md:min-h-56 md:p-7">
                            <span class="mb-4 flex h-11 w-11 items-center justify-center rounded-2xl bg-[#E8F8F3] text-[#098D80]" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16M7 16v-5M12 16V5M17 16V9"/><path d="M5 16v4h14v-4"/></svg>
                            </span>
                            <h3 class="text-base font-bold text-[#1B2935]">گزارش‌ها</h3>
                            <p class="mt-1 text-xs leading-7 text-[#71818B]">گزارش عملکرد و فروش</p>
                            <button type="button" wire:click="showSection('reports')" class="mt-auto inline-flex min-h-10 items-center gap-2 self-start pt-4 text-xs font-bold text-[#087F73] focus-visible:outline-2 focus-visible:outline-[#14B8A6]">
                                مشاهده <span aria-hidden="true">←</span>
                            </button>
                        </section>
                    </div>

                    <div class="mt-5 flex items-start gap-3 rounded-[22px] border border-[#D8F0E9] bg-[#EFFAF6] p-4 md:p-5">
                        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-white font-bold text-[#087F73]" aria-hidden="true">i</span>
                        <p class="text-xs leading-7 text-[#4C736B]">اطلاعات واقعی سرویس‌ها، اعتبار و گزارش‌ها در مرحله بعد به این پنل متصل می‌شوند. تا آن زمان، عملیات فروش از بات دناگیت در دسترس است.</p>
                    </div>
                @else
                    @php
                        $titles = ['services' => 'سرویس‌ها', 'credit' => 'اعتبار من', 'reports' => 'گزارش‌ها'];
                    @endphp
                    <button type="button" wire:click="showSection('overview')"
                        class="mb-6 inline-flex min-h-10 items-center gap-2 rounded-xl px-2 text-sm font-semibold text-[#687987] hover:text-[#087F73] focus-visible:outline-2 focus-visible:outline-[#14B8A6]">
                        <span aria-hidden="true">→</span> بازگشت به نمای کلی
                    </button>
                    <section class="max-w-3xl rounded-[28px] border border-[#E7ECEF] bg-white p-7 shadow-[0_10px_30px_rgba(14,34,44,0.035)] md:p-10">
                        <span class="inline-flex rounded-full bg-[#EAF8F4] px-3 py-1 text-xs font-semibold text-[#087F73]">دناگیت لایو</span>
                        <h1 class="mb-4 mt-5 text-2xl font-extrabold text-[#1B2935]">{{ $titles[$section] ?? 'نمای کلی' }}</h1>
                        <p class="text-sm leading-8 text-[#677987]">این بخش در مرحله بعد با اطلاعات واقعی حساب شما فعال می‌شود. تا آن زمان می‌توانید همه عملیات را از بات فروش دناگیت انجام دهید.</p>
                    </section>
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
