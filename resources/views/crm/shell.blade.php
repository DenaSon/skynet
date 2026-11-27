<div class="min-h-dvh">
    @php
        $titles = ['dashboard' => 'داشبورد', 'analytics' => 'تحلیل و آمار', 'customers' => 'مشتریان مستقیم', 'resellers' => 'نمایندگان', 'contacts' => 'مشتریان نمایندگان'];
        $icons = ['dashboard' => 'o-squares-2x2', 'analytics' => 'o-chart-bar', 'customers' => 'o-users', 'resellers' => 'o-user-group', 'contacts' => 'o-identification'];
        $periods = ['today' => 'امروز', 'yesterday' => 'دیروز', '7' => '۷ روز اخیر', '30' => '۳۰ روز اخیر', 'all' => 'از ابتدا', 'custom' => 'بازه دلخواه'];
        $options = static fn (array $items) => collect($items)->map(fn ($name, $id) => ['id' => (string) $id, 'name' => $name])->values()->all();
        $yesNo = $options(['all' => 'همه', 'yes' => 'دارد', 'no' => 'ندارد']);
        $money = static fn ($rials) => \App\Support\PersianCalendar::number(number_format($rials / 10, $rials % 10 === 0 ? 0 : 1)).' تومان';
    @endphp

    @include('crm.topbar')

    <div class="mx-auto grid max-w-screen-2xl gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
        @include('crm.navigation')

        <main class="min-w-0 space-y-5">
            <x-header :title="$titles[$section]" subtitle="پنل مدیریت مشتریان" separator />

            <x-card class="border border-base-300">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-dg-navy">فیلترهای گزارش</h2>
                    <x-button label="پاک‌کردن فیلترها" icon="o-arrow-path" wire:click="resetFilters" class="btn-ghost btn-sm text-primary" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <x-select label="{{ $section === 'contacts' ? 'بازه تاریخ سفارش' : 'بازه گزارش' }}" :options="$options($periods)" wire:model.live="period" />
                    @if ($period === 'custom')
                        <div>
                            <x-datetime label="از تاریخ (میلادی)" wire:model.live="from" />
                            @if ($from !== '')
                                <p class="mt-1 text-xs text-base-content/70" dir="rtl">شمسی: <bdi dir="ltr">{{ \App\Support\PersianCalendar::date($from) }}</bdi></p>
                            @endif
                        </div>
                        <div>
                            <x-datetime label="تا تاریخ (میلادی)" wire:model.live="until" />
                            @if ($until !== '')
                                <p class="mt-1 text-xs text-base-content/70" dir="rtl">شمسی: <bdi dir="ltr">{{ \App\Support\PersianCalendar::date($until) }}</bdi></p>
                            @endif
                        </div>
                    @endif
                    @if ($section !== 'dashboard')
                        <x-input label="جست‌وجو" icon="o-magnifying-glass" wire:model.live.debounce.400ms="search" maxlength="80" clearable />
                    @endif
                    @if (in_array($section, ['customers', 'resellers']))
                        <x-select label="وضعیت حساب" :options="$options(['all' => 'همه', 'active' => 'فعال', 'inactive' => 'غیرفعال'])" wire:model.live="status" />
                    @endif
                    @if ($section === 'customers')
                        <x-select label="اتصال تلگرام" :options="$options(['all' => 'همه', 'linked' => 'متصل', 'unlinked' => 'متصل نیست'])" wire:model.live="telegram" />
                        <x-select label="سابقه خرید معتبر" :options="$yesNo" wire:model.live="purchase" />
                        <div>
                            <x-datetime label="عضویت از تاریخ (میلادی)" wire:model.live="registeredFrom" />
                            @if ($registeredFrom !== '')
                                <p class="mt-1 text-xs text-base-content/70" dir="rtl">شمسی: <bdi dir="ltr">{{ \App\Support\PersianCalendar::date($registeredFrom) }}</bdi></p>
                            @endif
                        </div>
                        <div>
                            <x-datetime label="عضویت تا تاریخ (میلادی)" wire:model.live="registeredUntil" />
                            @if ($registeredUntil !== '')
                                <p class="mt-1 text-xs text-base-content/70" dir="rtl">شمسی: <bdi dir="ltr">{{ \App\Support\PersianCalendar::date($registeredUntil) }}</bdi></p>
                            @endif
                        </div>
                    @elseif ($section === 'contacts')
                        <x-input label="شناسه نماینده" type="number" min="1" wire:model.live.debounce.400ms="reseller" />
                        <x-input label="محصول" wire:model.live.debounce.400ms="product" maxlength="80" />
                        <x-checkbox label="گروه‌بندی بر اساس شماره تماس هر نماینده" wire:model.live="groupContacts" />
                        <x-select label="شماره تماس" :options="$yesNo" wire:model.live="phone" />
                        <x-select label="خرید تسویه‌شده" :options="$yesNo" wire:model.live="purchase" />
                        <x-select label="وضعیت سفارش" :options="$options(['all' => 'همه', 'pending_payment' => 'در انتظار پرداخت', 'paid' => 'پرداخت‌شده', 'fulfilled' => 'تکمیل‌شده', 'refunded' => 'برگشت‌خورده', 'cancelled' => 'لغوشده'])" wire:model.live="orderStatus" />
                    @endif
                </div>
                @if ($section === 'customers')
                    @include('crm.advanced-filters')
                @endif
                <div wire:loading.delay class="mt-3 text-sm text-base-content/60" role="status">در حال دریافت اطلاعات...</div>
            </x-card>

            @if ($data === null)
                <x-alert icon="o-exclamation-triangle" class="alert-warning">{{ ($readFailed ?? false) ? 'دریافت اطلاعات ممکن نشد؛ دوباره تلاش کنید.' : 'بازه زمانی یا فیلتر انتخاب‌شده معتبر نیست.' }}</x-alert>
            @elseif ($section === 'dashboard')
                @include('crm.overview', ['data' => $data, 'money' => $money])
            @elseif ($section === 'contacts')
                @include($groupContacts ? 'crm.grouped-contacts' : 'crm.contacts', ['rows' => $data['rows']])
            @else
                @include('crm.accounts', ['rows' => $data['rows'], 'money' => $money, 'resellers' => $section === 'resellers'])
            @endif
        </main>
    </div>
</div>
