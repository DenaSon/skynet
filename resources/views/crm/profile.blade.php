<div class="min-h-dvh">
    @php
        $titles = ['dashboard' => 'داشبورد', 'customers' => 'مشتریان مستقیم', 'resellers' => 'نمایندگان', 'contacts' => 'مشتریان نمایندگان'];
        $icons = ['dashboard' => 'o-squares-2x2', 'customers' => 'o-users', 'resellers' => 'o-user-group', 'contacts' => 'o-identification'];
        $options = static fn (array $items) => collect($items)->map(fn ($name, $id) => ['id' => (string) $id, 'name' => $name])->values()->all();
        $money = static fn ($rials) => number_format($rials / 10, $rials % 10 === 0 ? 0 : 1).' تومان';
        $tabs = ['overview' => 'نمای کلی', 'orders' => 'سفارش‌ها', 'payments' => 'تسویه‌ها', 'services' => 'سرویس‌ها'];
        if ($reseller) {
            $tabs['contacts'] = 'مشتریان ثبت‌شده';
        }
    @endphp

    @include('crm.topbar')
    <div class="mx-auto grid max-w-screen-2xl gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
        @include('crm.navigation')
        <main class="min-w-0 space-y-5">
            <x-button label="بازگشت به فهرست" icon="o-arrow-right" :link="route($reseller ? 'crm.resellers' : 'crm.customers')" class="btn-ghost btn-sm" />
            <x-header :title="$reseller ? 'پرونده نماینده' : 'پرونده مشتری'" :subtitle="$data ? $data['account']->name : 'پنل مدیریت مشتریان'" separator />
            @if ($data)
                <x-card class="border border-base-300">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="font-bold">{{ $data['account']->name }}</span>
                        <x-badge :value="$data['account']->is_active ? 'فعال' : 'غیرفعال'" :class="$data['account']->is_active ? 'badge-success badge-soft' : 'badge-error badge-soft'" />
                        <span class="text-sm">شناسه: {{ $data['account']->id }}</span>
                        <span class="text-sm">تلگرام: <span dir="ltr">{{ $data['account']->telegram_user_id ?? '—' }}</span></span>
                        <span class="text-sm">عضویت: {{ \Illuminate\Support\Carbon::parse($data['account']->created_at, 'UTC')->timezone('Asia/Tehran')->format('Y-m-d') }}</span>
                    </div>
                </x-card>
            @endif

            <x-card class="border border-base-300">
                <x-tabs wire:model.live="tab">
                    @foreach ($tabs as $key => $label)
                        <x-tab :name="$key" :label="$label">
                            @if ($tab === $key)
                                @if ($tab !== 'services')
                                    <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                        <x-select label="{{ $tab === 'payments' ? 'تاریخ تسویه' : 'بازه گزارش' }}" :options="$options(['today' => 'امروز', 'yesterday' => 'دیروز', '7' => '۷ روز اخیر', '30' => '۳۰ روز اخیر', 'all' => 'از ابتدا', 'custom' => 'بازه دلخواه'])" wire:model.live="period" />
                                        @if ($period === 'custom')
                                            <x-datetime label="از تاریخ" wire:model.live="from" />
                                            <x-datetime label="تا تاریخ" wire:model.live="until" />
                                        @endif
                                        @if (in_array($tab, ['orders', 'contacts']))
                                            <x-input label="جست‌وجو" icon="o-magnifying-glass" wire:model.live.debounce.400ms="search" maxlength="80" clearable />
                                            <x-select label="وضعیت سفارش" :options="$options(['all' => 'همه', 'pending_payment' => 'در انتظار پرداخت', 'paid' => 'پرداخت‌شده', 'fulfilled' => 'تکمیل‌شده', 'refunded' => 'برگشت‌خورده', 'cancelled' => 'لغوشده'])" wire:model.live="status" />
                                        @endif
                                        @if ($tab === 'contacts')
                                            <x-select label="شماره تماس" :options="$options(['all' => 'همه', 'yes' => 'دارد', 'no' => 'ندارد'])" wire:model.live="phone" />
                                            <x-select label="تسویه معتبر" :options="$options(['all' => 'همه', 'yes' => 'دارد', 'no' => 'ندارد'])" wire:model.live="purchase" />
                                            <x-checkbox label="گروه‌بندی شماره‌ها" wire:model.live="groupContacts" />
                                        @endif
                                    </div>
                                @endif
                                <div wire:loading.delay class="mb-3 text-sm text-base-content/60" role="status">در حال دریافت اطلاعات...</div>
                                @if ($error)
                                    <x-alert icon="o-exclamation-triangle" class="alert-warning">{{ $error }}</x-alert>
                                @elseif ($tab === 'overview')
                                    @include('crm.profile-overview')
                                @elseif ($tab === 'contacts')
                                    @include($groupContacts ? 'crm.grouped-contacts' : 'crm.contacts', ['rows' => $data['rows']])
                                @else
                                    @include('crm.profile-history', ['rows' => $data['rows']])
                                @endif
                            @endif
                        </x-tab>
                    @endforeach
                    @if (! array_key_exists($tab, $tabs))
                        <x-alert class="alert-warning">بخش انتخاب‌شده معتبر نیست. یکی از تب‌ها را انتخاب کنید.</x-alert>
                    @endif
                </x-tabs>
            </x-card>
        </main>
    </div>
</div>
