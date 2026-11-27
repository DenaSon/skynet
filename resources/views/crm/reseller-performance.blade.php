<div class="mt-6 border-t border-base-300 pt-5">
    <x-header title="رتبه عملکرد نماینده" subtitle="ارزیابی ۳۰ روز کامل اخیر؛ مستقل از فیلتر گزارش" />
    @php
        $performance = $data['performance'] ?? null;
        $row = $performance['row'] ?? null;
        $n = static fn ($value, $digits = 0) => \App\Support\PersianCalendar::number(number_format($value, $digits));
        $tierLabels = ['diamond' => 'الماس', 'gold' => 'طلایی', 'silver' => 'نقره‌ای', 'bronze' => 'برنزی', 'ordinary' => 'عادی'];
        $state = $row['state'] ?? 'unknown';
    @endphp
    @if ($performance !== null)
        <p class="mb-3 text-xs text-base-content/70">بازه: <bdi dir="ltr">{{ \App\Support\PersianCalendar::date($performance['start']) }}</bdi> تا <bdi dir="ltr">{{ \App\Support\PersianCalendar::date($performance['end']) }}</bdi>، به وقت تهران</p>
    @endif
    @if ($state !== 'ranked')
        <p class="text-sm text-base-content/70">{{ match ($state) {
            'inactive' => 'حساب غیرفعال در رتبه‌بندی شرکت نمی‌کند.',
            'evaluating' => 'در حال ارزیابی؛ هنوز ۳۰ روز کامل از شروع ثبت‌شده نمایندگی نگذشته است.',
            default => 'اطلاعات لازم برای رتبه‌بندی نامشخص است.',
        } }}</p>
    @else
        <div class="crm-metric-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <x-stat title="رتبه عملکرد" :value="$n($row['rank']).' از '.$n($performance['eligibleCount'])" icon="o-trophy" class="crm-tone-green" />
            <x-stat title="امتیاز عملکرد" :value="$n($row['score'], 2).' از ۱۰۰'" icon="o-chart-bar" />
            <x-stat title="سطح عملکرد" :value="$tierLabels[$row['tier']]" icon="o-star" class="crm-tone-violet" />
        </div>
        @if ($row['previousRank'] !== null)
            <p class="mt-3 text-sm text-base-content/70">رتبه دوره قبل: {{ $n($row['previousRank']) }}؛ با محاسبه مجدد روی جامعه فعلی نمایندگان واجد شرایط.</p>
        @else
            <p class="mt-3 text-sm text-base-content/70">رتبه دوره قبل: نامشخص؛ بازه یا جامعه قابل مقایسه نیست.</p>
        @endif
        <div class="mt-4 overflow-x-auto">
            <table class="table table-sm">
                <thead><tr><th>معیار</th><th>مقدار</th><th>امتیاز از وزن معیار</th></tr></thead>
                <tbody>
                    @foreach ([
                        ['مبلغ خرید تسویه‌شده', $money($row['revenueRials']), 'revenue', 35],
                        ['تعداد خرید معتبر', $n($row['purchases']), 'purchases', 20],
                        ['استمرار خرید', $n($row['purchaseDays']).' روز', 'consistency', 15],
                        ['رشد خرید', $row['growthPercent'] === null ? 'نامشخص' : $n($row['growthPercent'], 1).'٪', 'growth', 10],
                        ['آخرین خرید', $row['lastPurchaseAt'] === null ? 'بدون خرید در بازه' : \App\Support\PersianCalendar::utcDateTime((string) $row['lastPurchaseAt']), 'recency', 10],
                        ['میانگین مانده پایان روز کیف پول', $row['averageWalletRials'] === null ? 'بدون کیف پول ثبت‌شده' : $money((int) round($row['averageWalletRials'])), 'wallet', 10],
                    ] as [$label, $value, $key, $weight])
                        <tr><td>{{ $label }}</td><td>{{ $value }}</td><td>{{ $n($row['points'][$key], 2) }} از {{ $n($weight) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-base-content/70">مبلغ، تعداد و میانگین اعتبار نسبت به بیشترین مقدار مثبت جامعه محاسبه می‌شوند. رشد نامشخص برای نماینده دارای خرید، سهم خنثی ۵ از ۱۰ دارد. بدون خرید معتبر امتیاز صفر است. این ارزیابی اثری بر قیمت، تخفیف یا دسترسی ندارد.</p>
    @endif
</div>
