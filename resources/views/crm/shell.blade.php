<div class="min-h-dvh">
    <header class="border-b border-base-300 bg-base-100">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <a href="{{ route('crm.dashboard') }}" class="flex items-center gap-3 no-underline">
                <span class="grid size-11 place-items-center rounded-2xl bg-dg-navy text-lg font-black text-dg-teal">D</span>
                <span class="leading-tight">
                    <span class="block text-base font-bold text-base-content">DenaGate</span>
                    <span class="block text-xs tracking-wide text-base-content/60">CRM</span>
                </span>
            </a>

            <div class="flex items-center gap-3">
                <span class="badge badge-success badge-outline hidden sm:inline-flex">دسترسی تأیید شده</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-button type="submit" label="خروج" class="btn-ghost btn-sm" />
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:py-12">
        <div class="mb-8 max-w-3xl space-y-3">
            <p class="text-sm font-semibold text-primary">DenaGate</p>
            <h1 class="text-2xl font-bold tracking-tight text-base-content sm:text-3xl">پنل مدیریت مشتریان</h1>
            <p class="text-sm leading-7 text-base-content/65 sm:text-base">
                محیط مدیریتی آماده است. اطلاعات مشتریان و نمایندگان پس از تکمیل بخش‌های مربوط، در همین پنل نمایش داده می‌شوند.
            </p>
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            <x-card class="border border-base-300 bg-base-100 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-success/10 text-success">
                        <x-icon name="o-shield-check" class="size-5" />
                    </div>
                    <div class="space-y-1">
                        <h2 class="font-semibold text-base-content">دسترسی امن</h2>
                        <p class="text-sm leading-6 text-base-content/65">ورود فقط برای مدیر کل تأییدشده مجاز است.</p>
                    </div>
                </div>
            </x-card>

            <x-card class="border border-base-300 bg-base-100 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary">
                        <x-icon name="o-eye" class="size-5" />
                    </div>
                    <div class="space-y-1">
                        <h2 class="font-semibold text-base-content">حالت فقط مشاهده</h2>
                        <p class="text-sm leading-6 text-base-content/65">در این نسخه هیچ تغییری در حساب‌ها، اطلاعات تماس یا سوابق ثبت نمی‌شود.</p>
                    </div>
                </div>
            </x-card>

            <x-card class="border border-base-300 bg-base-100 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-base-200 text-base-content">
                        <x-icon name="o-squares-2x2" class="size-5" />
                    </div>
                    <div class="space-y-1">
                        <h2 class="font-semibold text-base-content">ساختار پنل</h2>
                        <p class="text-sm leading-6 text-base-content/65">بخش‌های مشتریان، نمایندگان و گزارش‌ها به‌تدریج به همین محیط اضافه می‌شوند.</p>
                    </div>
                </div>
            </x-card>
        </div>
    </main>
</div>
