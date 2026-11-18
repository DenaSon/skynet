@props(['code' => 'unknown', 'label' => 'نامشخص', 'large' => false])

@php
    $tone = match ($code) {
        'healthy' => 'text-[#087F73]',
        'suspected' => 'text-[#A65B0D]',
        'confirmed' => 'text-[#C23B4A]',
        default => 'text-[#617380]',
    };
@endphp

<span class="inline-flex items-center gap-1.5 font-extrabold {{ $large ? 'text-lg' : 'text-xs' }} {{ $tone }}">
    <svg class="h-4 w-4 shrink-0 {{ $large ? 'h-5 w-5' : '' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        @if ($code === 'healthy')
            <circle cx="12" cy="12" r="10" />
            <path d="m7.5 12 3 3 6-6" />
        @elseif ($code === 'suspected')
            <path d="m10.3 3.9-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3.1l-8-14a2 2 0 0 0-3.4 0Z" />
            <path d="M12 9v4m0 4h.01" />
        @elseif ($code === 'confirmed')
            <circle cx="12" cy="12" r="10" />
            <path d="m9 9 6 6m0-6-6 6" />
        @else
            <circle cx="12" cy="12" r="10" />
            <path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 2-2.5 2-2.5 4m0 4h.01" />
        @endif
    </svg>
    <span>{{ $label }}</span>
</span>
