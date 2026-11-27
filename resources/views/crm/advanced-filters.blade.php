<div class="mt-5 space-y-3 border-t border-base-300 pt-4">
    @php
        $fields = [
            'orders' => 'تعداد خرید معتبر در بازه گزارش',
            'transactions' => 'تعداد تراکنش تسویه در بازه گزارش',
            'revenue' => 'مبلغ خرید تسویه‌شده در بازه (تومان)',
            'wallet' => 'مانده فعلی کیف پول (تومان)',
            'wallet_known' => 'وجود رکورد کیف پول',
            'active' => 'فعال بودن حساب',
            'telegram' => 'اتصال تلگرام',
            'trial' => 'شاهد فعال‌سازی تست',
            'seen' => 'شاهد تعامل با بات فروش',
            'last_seen' => 'تاریخ آخرین تعامل بات فروش (میلادی)',
        ];
    @endphp
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 class="text-sm font-semibold text-dg-navy">فیلتر پیشرفته</h2>
            <p class="mt-1 text-xs text-base-content/70">حداکثر ۵ شرط؛ در کنار فیلترهای بالا اعمال می‌شود.</p>
        </div>
        <x-button label="افزودن شرط" icon="o-plus" wire:click="addAdvancedCondition" class="btn-primary btn-sm" :disabled="count($advancedConditions) >= 5" />
    </div>
    @if ($advancedConditions !== [])
        <x-select label="ترکیب شرط‌ها" :options="$options(['and' => 'همه شرط‌ها (و)', 'or' => 'حداقل یک شرط (یا)'])" wire:model.live="advancedMode" />
        @foreach (array_slice($advancedConditions, 0, 5, true) as $index => $condition)
            @php
                $field = data_get($condition, 'field');
                $binary = in_array($field, ['active', 'telegram', 'wallet_known', 'trial', 'seen'], true);
                $operators = $binary ? ['eq' => 'برابر'] : ($field === 'last_seen' ? ['eq' => 'در تاریخ', 'gte' => 'از تاریخ', 'lte' => 'تا تاریخ'] : ['eq' => 'برابر', 'gte' => 'بیشتر یا برابر', 'lte' => 'کمتر یا برابر']);
            @endphp
            <div wire:key="advanced-condition-{{ $index }}-{{ count($advancedConditions) }}" class="grid items-end gap-2 rounded-xl bg-base-200/50 p-3 sm:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr_auto]">
                <x-select label="معیار" :options="$options($fields)" wire:model.live="advancedConditions.{{ $index }}.field" />
                <x-select label="عملگر" :options="$options($operators)" wire:model.live="advancedConditions.{{ $index }}.operator" />
                @if ($binary)
                    <x-select label="مقدار" :options="$options(['1' => 'بله', '0' => 'خیر'])" wire:model.live="advancedConditions.{{ $index }}.value" />
                @elseif ($field === 'last_seen')
                    <x-datetime label="تاریخ (میلادی)" wire:model.live="advancedConditions.{{ $index }}.value" />
                @else
                    <x-input label="مقدار" type="number" step="1" :min="$field === 'wallet' ? -1000000000000 : 0" wire:model.live.debounce.400ms="advancedConditions.{{ $index }}.value" />
                @endif
                <x-button label="حذف شرط" icon="o-trash" wire:click="removeAdvancedCondition({{ (int) $index }})" class="btn-ghost btn-sm text-error" />
            </div>
        @endforeach
        <p class="text-xs text-base-content/70">مانده کیف پول، تست و تعامل مستقل از بازه گزارش‌اند. نبود شواهد تعامل یا رکورد کیف پول به معنی صفر نیست.</p>
    @endif
</div>
