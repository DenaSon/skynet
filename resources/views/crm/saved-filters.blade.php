<x-card class="border border-base-300">
    <h2 class="text-sm font-semibold text-dg-navy">فیلترهای ذخیره‌شده</h2>
    @if ($savedFilters === null)
        <p class="mt-2 text-sm text-base-content/70">ذخیره فیلترها در حال حاضر در دسترس نیست. فیلترهای عادی همچنان قابل استفاده‌اند.</p>
    @else
        <p class="mt-1 text-xs text-base-content/70">معیارهای فعلی را برای استفاده دوباره ذخیره کنید. متن جست‌وجو ذخیره نمی‌شود.</p>
        <div class="mt-4 grid items-end gap-3 sm:grid-cols-2">
            <x-select label="فیلتر ذخیره‌شده" :options="$savedFilters" placeholder="انتخاب فیلتر" wire:model.live="savedFilterId" />
            <x-input label="نام فیلتر" maxlength="80" wire:model="savedFilterName" placeholder="مثلاً مشتریان آماده خرید" />
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
            <x-button label="اعمال فیلتر" icon="o-funnel" wire:click="applySavedFilter" :disabled="$savedFilterId === ''" class="btn-primary btn-sm" />
            <x-button label="ذخیره فیلتر جدید" icon="o-plus" wire:click="createSavedFilter" :disabled="count($savedFilters) >= 30" class="btn-outline btn-sm" />
            <x-button label="به‌روزرسانی" icon="o-pencil-square" wire:click="updateSavedFilter" wire:confirm="نام و معیارهای فیلتر انتخاب‌شده با مقادیر فعلی جایگزین شوند؟" :disabled="$savedFilterId === ''" class="btn-ghost btn-sm" />
            <x-button label="حذف" icon="o-trash" wire:click="deleteSavedFilter" wire:confirm="این فیلتر ذخیره‌شده حذف شود؟" :disabled="$savedFilterId === ''" class="btn-ghost btn-sm text-error" />
        </div>
        <p class="mt-2 text-xs text-base-content/70">{{ \App\Support\PersianCalendar::number((string) count($savedFilters)) }} از ۳۰ فیلتر شخصی</p>
        @if ($savedFilterMessage !== '')
            <p class="mt-2 text-sm text-success" role="status">{{ $savedFilterMessage }}</p>
        @endif
        @error('savedFilter')
            <p class="mt-2 text-sm text-error" role="alert">{{ $message }}</p>
        @enderror
    @endif
</x-card>
