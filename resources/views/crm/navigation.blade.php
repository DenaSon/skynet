<aside>
            <x-card class="border border-base-300">
                <x-menu>
                    @foreach ($titles as $key => $title)
                        <x-menu-item :title="$title" :icon="$icons[$key]" :link="route('crm.'.$key)" :active="$key === ($section ?? ($reseller ? 'resellers' : 'customers'))" />
                    @endforeach
                </x-menu>
                <div class="mt-4 text-xs text-base-content/60">حالت فقط مشاهده</div>
            </x-card>
        </aside>

