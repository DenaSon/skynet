@props(['section', 'demo' => false])

@php
    $navigationItems = [
        ['overview', 'نمای کلی'],
        ['services', 'سرویس‌ها'],
        ['credit', 'اعتبار من'],
        ['reports', 'گزارش‌ها'],
    ];
@endphp

<div class="min-h-dvh bg-[#F6F8FA]">
    <div class="mx-auto flex min-h-dvh max-w-[1440px]">
        {{-- Compact desktop navigation; the same four actions stay accessible on mobile. --}}
        <aside class="hidden w-64 shrink-0 border-l border-[#E7ECEF] bg-white px-5 py-8 lg:flex lg:flex-col">
            <div class="mb-12 flex items-center gap-3 px-2">
                <img src="/images/denagate-logo.png" alt="لوگوی دناگیت" width="44" height="44" class="h-11 w-11 shrink-0 rounded-2xl object-contain" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-lg font-extrabold tracking-tight text-[#1B2935]" dir="ltr">DenaGate</p>
                        <span class="dg-status whitespace-nowrap px-2 py-1 text-[11px]" data-tone="{{ $demo ? 'neutral' : 'success' }}"><span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $demo ? 'bg-[#A9BAC3]' : 'bg-[#14B8A6] motion-safe:animate-pulse' }}" aria-hidden="true"></span>{{ $demo ? 'حالت دمو' : 'فعال' }}</span>
                    </div>
                    <p class="text-xs font-medium text-[#647787]">{{ $demo ? 'پیش‌نمایش نمایندگی' : 'پنل نمایندگی' }}</p>
                </div>
            </div>

            <nav class="flex flex-col gap-2" aria-label="منوی پنل نمایندگی">
                @foreach ($navigationItems as [$key, $label])
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
                {{ $demo ? 'آشنایی با امکانات پنل نمایندگی' : 'مدیریت حساب و سرویس‌های نمایندگی' }}
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 border-b border-[#E7ECEF] bg-white/95 backdrop-blur-xl">
                <div class="mx-auto flex h-[76px] max-w-6xl items-center justify-between gap-3 px-5 md:px-9">
                    <div class="flex min-w-0 items-center gap-3">
                        <img src="/images/denagate-logo.png" alt="لوگوی دناگیت" width="40" height="40" class="h-10 w-10 shrink-0 rounded-[14px] object-contain lg:hidden" />
                        <div class="min-w-0">
                            <p class="text-[11px] font-medium text-[#71818B]">{{ $demo ? 'پیش‌نمایش نمایندگی' : 'پنل نمایندگی' }}</p>
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <p class="truncate text-base font-bold text-[#1B2935]" dir="ltr">DenaGate</p>
                                <span class="dg-status whitespace-nowrap px-2 py-1 text-[11px]" data-tone="{{ $demo ? 'neutral' : 'success' }}"><span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $demo ? 'bg-[#A9BAC3]' : 'bg-[#14B8A6] motion-safe:animate-pulse' }}" aria-hidden="true"></span>{{ $demo ? 'حالت دمو' : 'فعال' }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="hidden items-center gap-2 rounded-full bg-[#EAF8F4] px-3 py-1.5 text-xs font-semibold text-[#087F73] sm:inline-flex">
                            <span class="h-1.5 w-1.5 rounded-full bg-[#14B8A6]" aria-hidden="true"></span>
                            {{ $demo ? 'حالت نمایشی' : 'حساب نماینده' }}
                        </span>
                    </div>
                </div>
            </header>

            <main id="live-main" class="mx-auto w-full max-w-6xl flex-1 px-4 pb-32 pt-7 sm:px-6 md:px-9 md:pt-10 lg:pb-12" aria-label="محتوای پنل نمایندگی">
                {{ $slot }}
            </main>
            <footer class="hidden px-5 pb-6 text-center text-xs text-[#8A98A2] lg:block">DenaGate · {{ $demo ? 'پیش‌نمایش نمایندگی' : 'پنل اختصاصی نمایندگان' }}</footer>
        </div>
    </div>

    {{-- Native mobile app-style navigation, available without a JS drawer. --}}
    <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-[#E7ECEF] bg-white/95 px-2 pt-2 backdrop-blur-xl lg:hidden"
        style="padding-bottom: max(8px, env(safe-area-inset-bottom), var(--tg-content-safe-area-inset-bottom, 0px)); padding-inline-start: max(8px, env(safe-area-inset-right), var(--tg-content-safe-area-inset-right, 0px)); padding-inline-end: max(8px, env(safe-area-inset-left), var(--tg-content-safe-area-inset-left, 0px));"
        aria-label="دسترسی سریع پنل">
        @foreach ($navigationItems as [$key, $label])
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
