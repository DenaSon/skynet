<x-card title="روند فروش و ثبت‌نام" subtitle="حداکثر ۷ روز پایانی بازه انتخابی تا امروز، به وقت تهران" class="border border-base-300">
    @if ($trend === [])
        <p class="text-sm text-base-content/70">بازه‌ای برای نمایش روند وجود ندارد.</p>
    @else
        <div class="grid gap-6 md:grid-cols-2">
            @foreach (['revenueRials' => 'خریدهای تسویه‌شده', 'registrations' => 'ثبت‌نام مشتریان و نمایندگان'] as $metric => $label)
                @php($max = max(1, max(array_column($trend, $metric))))
                <section class="min-w-0">
                    <h3 class="mb-3 text-sm font-semibold text-dg-navy">{{ $label }}</h3>
                    <div class="space-y-3">
                        @foreach ($trend as $point)
                            <div>
                                <div class="mb-1 flex flex-wrap items-center justify-between gap-1 text-xs">
                                    <bdi dir="ltr" class="text-base-content/70">{{ \App\Support\PersianCalendar::date($point['date']) }}</bdi>
                                    <span class="font-medium">{{ $metric === 'revenueRials' ? $money($point[$metric]) : \App\Support\PersianCalendar::number(number_format($point[$metric])).' نفر' }}</span>
                                </div>
                                <progress class="progress {{ $metric === 'revenueRials' ? 'progress-primary' : 'progress-info' }} h-2 w-full" value="{{ max(0, $point[$metric]) }}" max="{{ $max }}" aria-label="{{ $label }}"></progress>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</x-card>
