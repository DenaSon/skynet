<x-card title="روند فروش و ثبت‌نام" subtitle="حداکثر ۷ روز پایانی بازه انتخابی تا امروز، به وقت تهران" class="border border-base-300">
    @if ($trend === [])
        <p class="text-sm text-base-content/70">بازه‌ای برای نمایش روند وجود ندارد.</p>
    @else
        <div class="grid gap-6 md:grid-cols-2">
            <section class="min-w-0">
                <h3 class="mb-3 text-sm font-semibold text-dg-navy">خریدهای تسویه‌شده (تومان)</h3>
                <x-chart id="crm-sales-trend" wire:model="salesChart" x-crm-chart-cleanup class="h-64 w-full" />
            </section>
            <section class="min-w-0">
                <h3 class="mb-3 text-sm font-semibold text-dg-navy">ثبت‌نام مشتریان و نمایندگان (نفر)</h3>
                <x-chart id="crm-registration-trend" wire:model="registrationsChart" x-crm-chart-cleanup class="h-64 w-full" />
            </section>
        </div>
        <table class="sr-only">
            <caption>داده‌های روزانه روند فروش و ثبت‌نام</caption>
            <thead>
                <tr><th>تاریخ</th><th>خرید تسویه‌شده</th><th>مشتری مستقیم</th><th>نماینده</th></tr>
            </thead>
            <tbody>
                @foreach ($trend as $point)
                    <tr>
                        <td>{{ \App\Support\PersianCalendar::date($point['date']) }}</td>
                        <td>{{ $money($point['revenueRials']) }}</td>
                        <td>{{ \App\Support\PersianCalendar::number(number_format($point['directRegistrations'])) }}</td>
                        <td>{{ \App\Support\PersianCalendar::number(number_format($point['resellerRegistrations'])) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <div class="mt-4 flex justify-end">
        <x-button label="مشاهده تحلیل کامل" icon="o-chart-bar" :link="route('crm.analytics')" class="btn-ghost btn-sm text-primary" />
    </div>
</x-card>
