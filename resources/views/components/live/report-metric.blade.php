@props(['label', 'value', 'historical' => false])

<section class="dg-card flex min-w-0 min-h-28 flex-col justify-between gap-2 rounded-[18px] p-2.5 sm:min-h-32 sm:p-4">
    <div class="flex min-w-0 items-start gap-1.5">
        <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ $historical ? 'bg-[#14B8A6]' : 'bg-[#A9BAC3]' }}" aria-hidden="true"></span>
        <p class="min-w-0 break-words text-[11px] leading-4 font-semibold text-[#536C76] sm:text-xs">{{ $label }}</p>
    </div>
    <p class="w-full text-center text-2xl font-extrabold tabular-nums leading-tight text-[#172A36] sm:text-3xl" dir="ltr">{{ number_format($value) }}</p>
</section>
