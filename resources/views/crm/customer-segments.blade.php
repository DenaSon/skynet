@php
    $segmentLabels = [
        'all' => 'همه مشتریان مستقیم',
        'valuable' => 'مشتریان ارزشمند',
        'loyal' => 'مشتریان وفادار',
        'new_customer' => 'مشتریان جدید',
        'trial_only' => 'تست فعال‌شده بدون خرید',
        'wallet_positive' => 'دارای اعتبار کیف پول',
        'at_risk' => 'در معرض ریزش',
        'old_interaction' => 'تعامل قدیمی',
        'unknown_interaction' => 'تعامل نامشخص',
    ];
@endphp
<x-card class="border border-base-300">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-dg-navy">دسته‌بندی هوشمند مشتریان</h2>
            <p class="mt-1 text-xs text-base-content/70">تحلیل رفتار خرید در کل سابقه، مستقل از بازه گزارش.</p>
        </div>
        @if ($segment === 'off')
            <x-button label="نمایش گروه‌ها" icon="o-chart-bar" wire:click="$set('segment', 'all')" class="btn-primary btn-sm" />
        @else
            <x-button label="بستن تحلیل" wire:click="$set('segment', 'off')" class="btn-ghost btn-sm" />
        @endif
    </div>
    @if ($segment !== 'off')
        <div class="mt-4">
            <x-select label="گروه مشتریان" :options="$options($segmentLabels)" wire:model.live="segment" />
        </div>
        @if (isset($data['segments']))
            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                @foreach ($segmentLabels as $key => $label)
                    <x-button wire:key="crm-segment-{{ $key }}" wire:click="$set('segment', '{{ $key }}')" :class="$segment === $key ? 'btn-primary h-auto min-h-16 whitespace-normal' : 'btn-outline h-auto min-h-16 whitespace-normal'" >
                        <span class="flex flex-col items-center gap-1 py-2">
                            <span class="text-xs">{{ $label }}</span>
                            <span class="text-lg font-semibold">{{ \App\Support\PersianCalendar::number(number_format($data['segments'][$key === 'all' ? 'total' : $key])) }}</span>
                        </span>
                    </x-button>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-base-content/70">تعداد گروه‌ها در کل جامعه مشتریان مستقیم فعال محاسبه می‌شود (همه شامل حساب‌های غیرفعال نیز هست). گروه‌ها هم‌پوشانی دارند؛ فیلترهای دیگر روی نتایج جدول اعمال می‌شوند.</p>
            <details class="mt-3 text-xs leading-6 text-base-content/70">
                <summary class="cursor-pointer font-semibold">معیار دسته‌بندی و امتیازدهی</summary>
                <p>تازگی خرید (R): ۵ تا ۱ برای ۷، ۱۴، ۳۰، ۶۰ روز اخیر و قدیمی‌تر. تکرار خرید (F): تعداد خرید معتبر تا سقف ۵. ارزش خرید (M): امتیاز ۱ تا ۵ با مرزهای ۱۰۰، ۳۰۰، ۶۰۰ هزار و یک میلیون تومان.</p>
                <p>ارزشمند: حداقل R=۳ و F=۳ و M=۴؛ وفادار: حداقل ۳ خرید؛ جدید: ثبت‌نام در ۷ روز اخیر؛ در معرض ریزش: آخرین خرید قدیمی‌تر از ۳۰ روز. تعامل قدیمی: شاهد تعامل بات فروش قدیمی‌تر از ۳۰ روز؛ نبود شاهد، جداگانه نامشخص است. این گروه‌ها پیش‌بینی قطعی رفتار نیستند.</p>
            </details>
        @endif
    @endif
</x-card>
