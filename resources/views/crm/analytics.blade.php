<div class="min-h-dvh">
    @php
        $section = 'analytics';
        $titles = ['dashboard' => 'داشبورد', 'analytics' => 'تحلیل و آمار', 'customers' => 'مشتریان مستقیم', 'resellers' => 'نمایندگان', 'contacts' => 'مشتریان نمایندگان'];
        $icons = ['dashboard' => 'o-squares-2x2', 'analytics' => 'o-chart-bar', 'customers' => 'o-users', 'resellers' => 'o-user-group', 'contacts' => 'o-identification'];
        $options = static fn (array $items) => collect($items)->map(fn ($name, $id) => ['id' => (string) $id, 'name' => $name])->values()->all();
        $number = static fn ($value) => \App\Support\PersianCalendar::number(number_format($value));
        $money = static fn ($rials) => $rials === null ? 'نامشخص' : \App\Support\PersianCalendar::number(number_format($rials / 10, $rials % 10 === 0 ? 0 : 1)).' تومان';
        $comparison = static function ($current, $previous) {
            if ($current === null || $previous === null || $previous === 0) {
                return 'تغییر نسبت به دوره قبل: نامشخص';
            }
            $change = ($current - $previous) / $previous * 100;
            return 'تغییر نسبت به دوره قبل: '.($change > 0 ? '+' : '').\App\Support\PersianCalendar::number(number_format($change, 1)).'٪';
        };
    @endphp
    @include('crm.topbar')
    <div class="mx-auto grid max-w-screen-2xl gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
        @include('crm.navigation')
        <main class="min-w-0 space-y-5">
            <x-header title="تحلیل و آمار" subtitle="روند عملکرد کسب‌وکار" separator />
            <x-card class="border border-base-300">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">بازه و فیلترها</h2>
                    <x-button label="پاک‌کردن فیلترها" icon="o-arrow-path" wire:click="resetFilters" class="btn-ghost btn-sm text-primary" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <x-select label="بازه گزارش" :options="$options(['today' => 'امروز', '7' => '۷ روز اخیر', '30' => '۳۰ روز اخیر', '90' => '۹۰ روز اخیر', 'all' => 'از ابتدا', 'custom' => 'بازه دلخواه'])" wire:model.live="period" />
                    @if ($period === 'custom')
                        <div>
                            <x-datetime label="از تاریخ (میلادی)" wire:model.live="from" />
                            @if ($from !== '')
                                <p class="mt-1 text-xs text-base-content/70">شمسی: <bdi dir="ltr">{{ \App\Support\PersianCalendar::date($from) }}</bdi></p>
                            @endif
                        </div>
                        <div>
                            <x-datetime label="تا تاریخ (میلادی)" wire:model.live="until" />
                            @if ($until !== '')
                                <p class="mt-1 text-xs text-base-content/70">شمسی: <bdi dir="ltr">{{ \App\Support\PersianCalendar::date($until) }}</bdi></p>
                            @endif
                        </div>
                    @endif
                    <x-select label="کانال خرید" :options="$options(['all' => 'همه', 'direct' => 'مستقیم', 'reseller' => 'نمایندگی', 'unknown' => 'تاریخی نامشخص'])" wire:model.live="channel" />
                    @if (in_array($channel, ['all', 'reseller'], true))
                        <x-input label="شناسه نماینده (اختیاری)" type="number" min="1" wire:model.live.debounce.500ms="reseller" />
                    @endif
                </div>
                <p class="mt-3 text-xs text-base-content/70">فیلتر کانال و نماینده فقط بخش «خریدهای انتخاب‌شده» را محدود می‌کند. شاخص‌های کل کسب‌وکار و ثبت‌نام مستقل‌اند.</p>
                <p wire:loading.delay class="mt-2 text-sm text-base-content/60" role="status">در حال دریافت اطلاعات...</p>
            </x-card>

            @if ($data === null)
                <x-alert icon="o-exclamation-triangle" class="alert-warning">{{ ($readFailed ?? false) ? 'دریافت اطلاعات ممکن نشد؛ دوباره تلاش کنید.' : 'بازه یا فیلتر انتخاب‌شده معتبر نیست.' }}</x-alert>
            @else
                <p class="text-xs text-base-content/70">بازه تهران: <bdi dir="ltr">{{ \App\Support\PersianCalendar::date($data['start']) }}</bdi> تا <bdi dir="ltr">{{ \App\Support\PersianCalendar::date($data['end']) }}</bdi>؛ امروز تا زمان محاسبه. مقایسه با بازه قبل هم‌طول و تا ساعت مشابه است.</p>
                <h2 class="text-base font-semibold">کل کسب‌وکار</h2>
                <div class="crm-metric-grid grid gap-3 sm:grid-cols-2">
                    <x-card class="border border-base-300 crm-tone-green">
                        <x-stat title="فروش تسویه‌شده" :value="$money($data['salesRials'])" :description="$period === 'all' ? 'از ابتدا؛ بدون مقایسه' : $comparison($data['salesRials'], $data['previousSalesRials'])" icon="o-banknotes" />
                    </x-card>
                    <x-card class="border border-base-300 crm-tone-blue">
                        <x-stat title="واریزی بانکی تأییدشده" :value="$money($data['cashRials'])" :description="$period === 'all' ? 'از ابتدا؛ بدون مقایسه' : $comparison($data['cashRials'], $data['previousCashRials'])" icon="o-wallet" />
                    </x-card>
                </div>
                <x-card title="روند مالی" subtitle="فروش و واریزی مستقل؛ خرید از اعتبار کیف پول واریزی جدید نیست" class="border border-base-300">
                    @if ($data['cashRials'] === null)
                        <p class="mb-2 text-sm text-base-content/70">اطلاعات واریزی بانکی نامشخص است.</p>
                    @endif
                    <x-chart id="analytics-financial" wire:model="charts.financial" x-crm-chart-cleanup class="h-72 w-full" />
                </x-card>
                <div class="grid gap-4 xl:grid-cols-2">
                    <x-card title="سهم کانال‌های خرید" subtitle="مبلغ تسویه‌شده کل؛ کانال تاریخی نامشخص مستقل است" class="min-w-0 border border-base-300">
                        @if (isset($charts['channels']))
                            <x-chart id="analytics-channels" wire:model="charts.channels" x-crm-chart-cleanup class="h-64 w-full" />
                        @else
                            <p class="text-sm text-base-content/70">خرید تسویه‌شده‌ای در بازه وجود ندارد.</p>
                        @endif
                    </x-card>
                    <x-card title="روند ثبت‌نام" subtitle="تفکیک نقش فعلی حساب‌های تجاری" class="min-w-0 border border-base-300">
                        <x-chart id="analytics-registrations" wire:model="charts.registrations" x-crm-chart-cleanup class="h-64 w-full" />
                    </x-card>
                </div>
                <x-card title="۱۰ نماینده برتر" subtitle="خرید تسویه‌شده نزد دناگیت، نه فروش خرده‌فروشی" class="border border-base-300">
                    @if (isset($charts['resellers']))
                        <x-chart id="analytics-resellers" wire:model="charts.resellers" x-crm-chart-cleanup class="h-80 w-full" />
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($data['top'] as $row)
                                <x-button :label="$row->name.' #'.$row->id" wire:click="selectReseller({{ (int) $row->id }})" class="btn-ghost btn-xs text-primary" />
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-base-content/70">نماینده‌ای با خرید تسویه‌شده در بازه وجود ندارد.</p>
                    @endif
                </x-card>

                <h2 class="text-base font-semibold">خریدهای انتخاب‌شده</h2>
                <div class="crm-metric-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <x-card class="border border-base-300"><x-stat title="مبلغ خرید" :value="$money($data['selectedRials'])" :description="$period === 'all' ? 'از ابتدا؛ بدون مقایسه' : $comparison($data['selectedRials'], $data['previousSelectedRials'])" icon="o-banknotes" /></x-card>
                    <x-card class="border border-base-300"><x-stat title="خریداران یکتای کل بازه" :value="$number($data['buyers'])" icon="o-users" /></x-card>
                    <x-card class="border border-base-300"><x-stat title="خرید جدید" :value="$number($data['newPurchases'])" icon="o-shopping-bag" /></x-card>
                    <x-card class="border border-base-300"><x-stat title="تمدید" :value="$number($data['renewals'])" icon="o-arrow-path" /></x-card>
                </div>
                <div class="grid gap-4 xl:grid-cols-2">
                    <x-card title="روند خرید انتخاب‌شده" subtitle="بر اساس کانال و نماینده انتخابی، تومان" class="min-w-0 border border-base-300">
                        <x-chart id="analytics-selected" wire:model="charts.selected" x-crm-chart-cleanup class="h-64 w-full" />
                    </x-card>
                    <x-card title="روند خریداران یکتا" subtitle="هر نقطه مستقل است؛ جمع نقاط برابر کل خریداران یکتا نیست" class="min-w-0 border border-base-300">
                        <x-chart id="analytics-buyers" wire:model="charts.buyers" x-crm-chart-cleanup class="h-64 w-full" />
                    </x-card>
                    <x-card title="خرید جدید و تمدید" subtitle="تعداد سفارش‌های متمایز، نه نرخ تمدید" class="min-w-0 border border-base-300">
                        @if (isset($charts['renewals']))
                            <x-chart id="analytics-renewals" wire:model="charts.renewals" x-crm-chart-cleanup class="h-64 w-full" />
                        @else
                            <p class="text-sm text-base-content/70">سفارشی با خرید معتبر در بازه وجود ندارد.</p>
                        @endif
                    </x-card>
                    <x-card title="۱۰ پلن پرفروش" subtitle="بر اساس تعداد سفارش معتبر و نام ثبت‌شده محصول" class="min-w-0 border border-base-300">
                        @if (isset($charts['products']))
                            <x-chart id="analytics-products" wire:model="charts.products" x-crm-chart-cleanup class="h-64 w-full" />
                        @else
                            <p class="text-sm text-base-content/70">محصولی با خرید معتبر در بازه وجود ندارد.</p>
                        @endif
                    </x-card>
                </div>
                <p class="text-xs text-base-content/70">تمام شاخص‌ها بر اساس سوابق ثبت‌شده‌اند؛ نه گردش زنده بانک، سود یا وضعیت زنده سرویس‌ها.</p>
            @endif
        </main>
    </div>
</div>
