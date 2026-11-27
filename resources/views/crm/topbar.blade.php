<div class="border-b border-base-300 bg-base-100">
        <div class="mx-auto flex max-w-screen-2xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <a href="{{ route('crm.dashboard') }}" class="flex items-center gap-3">
                <span class="grid size-10 place-items-center rounded-xl bg-dg-navy font-black text-dg-teal">D</span>
                <span class="font-bold">DenaGate CRM</span>
            </a>
            <div class="flex items-center gap-3">
                <x-badge value="دسترسی امن" class="badge-success badge-soft hidden sm:inline-flex" />
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-button type="submit" label="خروج" icon="o-arrow-right-on-rectangle" class="btn-ghost btn-sm" />
                </form>
            </div>
        </div>
    </div>

