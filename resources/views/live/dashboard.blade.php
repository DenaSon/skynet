<div class="min-h-dvh" x-data="{ menuOpen: false }">
    <div class="mx-auto flex min-h-dvh max-w-[1500px]">
        {{-- Desktop sidebar --}}
        <aside class="hidden w-64 shrink-0 border-l border-white/10 bg-[#101b2a] p-6 lg:flex lg:flex-col">
            <div class="mb-12 flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#14B8A6] text-2xl font-black text-[#0B1220]">D</div>
                <div>
                    <p class="text-lg font-bold tracking-tight">DenaGate</p>
                    <p class="text-xs font-medium tracking-wide text-[#14B8A6]">LIVE · RESELLER</p>
                </div>
            </div>
            <nav class="flex flex-col gap-2" aria-label="منوی پنل نمایندگی">
                @foreach ([
                    ['overview', '◈', 'نمای کلی'],
                    ['services', '▦', 'سرویس‌ها'],
                    ['credit', '◉', 'اعتبار من'],
                    ['reports', '▥', 'گزارش‌ها'],
                ] as [$key, $symbol, $label])
                    <button type="button" wire:click="showSection('{{ $key }}')"
                        @class([
                            'flex w-full items-center gap-3 rounded-xl px-4 py-3 text-right text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14B8A6]',
                            'bg-[#14B8A6]/15 text-[#5DE4D4]' => $section === $key,
                            'text-slate-400 hover:bg-white/5 hover:text-white' => $section !== $key,
                        ])
                        @if ($section === $key) aria-current="page" @endif>
                        <span aria-hidden="true" class="text-xl">{{ $symbol }}</span>{{ $label }}
                    </button>
                @endforeach
            </nav>
            <p class="mt-auto border-t border-white/10 pt-5 text-xs leading-6 text-slate-500">محیط اختصاصی نمایندگان دناگیت</p>
        </aside>

        {{-- Main viewport --}}
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-20 items-center justify-between border-b border-white/10 bg-[#0B1220]/95 px-5 backdrop-blur md:px-9">
                <div class="flex items-center gap-3">
                    <button type="button" class="rounded-xl border border-white/10 p-2 text-slate-200 lg:hidden"
                        aria-label="بازکردن منو" aria-controls="live-mobile-menu" :aria-expanded="menuOpen.toString()"
                        @click="menuOpen = !menuOpen">
                        <span aria-hidden="true" class="text-xl">☰</span>
                    </button>
                    <div>
                        <p class="text-xs text-slate-400">پنل نمایندگی</p>
                        <p class="text-base font-bold">دناگیت لایو</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="hidden rounded-full border border-[#14B8A6]/25 bg-[#14B8A6]/10 px-3 py-1 text-xs text-[#5DE4D4] sm:inline-flex">حساب نماینده</span>
                    <form method="POST" action="{{ route('denagate-live.logout') }}">
                        @csrf
                        <button type="submit" class="rounded-xl border border-white/15 px-3 py-2 text-xs text-slate-300 transition hover:border-white/30 hover:text-white">خروج امن</button>
                    </form>
                </div>
            </header>
            <div x-cloak x-show="menuOpen" x-transition.opacity class="fixed inset-0 z-30 bg-black/60 lg:hidden" @click="menuOpen = false"></div>
            <nav id="live-mobile-menu" x-cloak x-show="menuOpen" x-transition
                class="fixed inset-y-0 right-0 z-40 w-72 max-w-[82vw] border-l border-white/10 bg-[#101b2a] p-6 shadow-2xl lg:hidden"
                aria-label="منوی موبایل">
                <div class="mb-8 flex items-center justify-between">
                    <strong class="text-lg">DenaGate Live</strong>
                    <button type="button" aria-label="بستن منو" @click="menuOpen = false" class="rounded-lg border border-white/10 px-3 py-2">✕</button>
                </div>
                @foreach ([
                    ['overview', 'نمای کلی'],
                    ['services', 'سرویس‌ها'],
                    ['credit', 'اعتبار من'],
                    ['reports', 'گزارش‌ها'],
                ] as [$key, $label])
                    <button type="button" wire:click="showSection('{{ $key }}')" @click="menuOpen = false"
                        @class([
                            'mb-2 block w-full rounded-xl px-4 py-3 text-right text-sm font-medium',
                            'bg-[#14B8A6]/15 text-[#5DE4D4]' => $section === $key,
                            'text-slate-300' => $section !== $key,
                        ])>{{ $label }}</button>
                @endforeach
            </nav>

            <main id="live-main" class="w-full flex-1 px-5 py-8 md:px-9 md:py-10" aria-live="polite">
                <div wire:loading.delay class="mb-4 inline-flex items-center gap-2 rounded-full border border-[#14B8A6]/25 bg-[#14B8A6]/10 px-3 py-1 text-xs text-[#5DE4D4]" role="status">در حال بارگذاری…</div>

                @if ($section === 'overview')
                    <div class="mb-9">
                        <span class="mb-3 inline-flex items-center gap-2 rounded-full border border-[#14B8A6]/20 bg-[#14B8A6]/10 px-3 py-1 text-xs text-[#5DE4D4]"><span class="h-1.5 w-1.5 rounded-full bg-[#14B8A6]"></span> ورود امن انجام شد</span>
                        <h1 class="mb-3 text-3xl font-extrabold tracking-tight md:text-4xl">خوش آمدید، {{ $name }}</h1>
                        <p class="max-w-2xl text-sm leading-8 text-slate-400">به فضای اختصاصی نمایندگان دناگیت خوش آمدید. دسترسی شما به حساب تلگرام تأیید شده است.</p>
                    </div>
                    <div class="grid gap-4 md:grid-cols-3">
                        <section class="rounded-3xl border border-white/10 bg-[#111e2e] p-6">
                            <span class="text-2xl" aria-hidden="true">▦</span>
                            <h2 class="mt-6 text-lg font-bold">سرویس‌ها</h2>
                            <p class="mt-2 text-sm leading-7 text-slate-400">مدیریت اشتراک‌های مشتریان از همین پنل</p>
                            <button type="button" wire:click="showSection('services')" class="mt-7 text-sm font-semibold text-[#5DE4D4]">مشاهده بخش ←</button>
                        </section>
                        <section class="rounded-3xl border border-white/10 bg-[#111e2e] p-6">
                            <span class="text-2xl" aria-hidden="true">◉</span>
                            <h2 class="mt-6 text-lg font-bold">اعتبار من</h2>
                            <p class="mt-2 text-sm leading-7 text-slate-400">مشاهده موجودی و سوابق اعتبار</p>
                            <button type="button" wire:click="showSection('credit')" class="mt-7 text-sm font-semibold text-[#5DE4D4]">مشاهده بخش ←</button>
                        </section>
                        <section class="rounded-3xl border border-white/10 bg-[#111e2e] p-6">
                            <span class="text-2xl" aria-hidden="true">▥</span>
                            <h2 class="mt-6 text-lg font-bold">گزارش‌ها</h2>
                            <p class="mt-2 text-sm leading-7 text-slate-400">مرور عملکرد و فروش نمایندگی</p>
                            <button type="button" wire:click="showSection('reports')" class="mt-7 text-sm font-semibold text-[#5DE4D4]">مشاهده بخش ←</button>
                        </section>
                    </div>
                    <section class="mt-5 rounded-3xl border border-[#14B8A6]/20 bg-gradient-to-l from-[#123536] to-[#102234] p-6 md:p-8">
                        <h2 class="mb-2 text-lg font-bold">پنل شما آماده اتصال به امکانات فروش است</h2>
                        <p class="text-sm leading-8 text-slate-300">اطلاعات واقعی سرویس‌ها، موجودی و گزارش‌ها پس از اتصال بخش‌های مربوطه نمایش داده خواهند شد. همه محاسبات و عملیات همچنان از سیستم فعلی دناگیت انجام می‌شوند.</p>
                    </section>
                @else
                    @php
                        $titles = ['services' => 'سرویس‌ها', 'credit' => 'اعتبار من', 'reports' => 'گزارش‌ها'];
                    @endphp
                    <button type="button" wire:click="showSection('overview')" class="mb-8 rounded-xl border border-white/10 px-4 py-2 text-sm text-slate-300 hover:text-white">← بازگشت به نمای کلی</button>
                    <section class="max-w-3xl rounded-3xl border border-white/10 bg-[#111e2e] p-7 md:p-10">
                        <p class="mb-3 text-xs font-semibold tracking-wide text-[#5DE4D4]">DenaGate Live</p>
                        <h1 class="mb-4 text-2xl font-bold">{{ $titles[$section] ?? 'نمای کلی' }}</h1>
                        <p class="text-sm leading-8 text-slate-400">این بخش در مرحله بعد با اطلاعات واقعی حساب شما فعال می‌شود. تا آن زمان می‌توانید همه عملیات را از بات فروش دناگیت انجام دهید.</p>
                    </section>
                @endif
            </main>
            <footer class="px-5 pb-6 text-center text-xs text-slate-600 md:px-9">DenaGate Live · پنل اختصاصی نمایندگان</footer>
        </div>
    </div>
</div>
