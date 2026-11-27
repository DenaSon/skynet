<aside>
            <x-card class="border border-base-300">
                <x-menu activate-by-route>
                    @foreach ($titles as $key => $title)
                        <x-menu-item :title="$title" :icon="$icons[$key]" :link="route('crm.'.$key)" :active="request()->routeIs('crm.'.$key, 'crm.'.$key.'.show')" />
                    @endforeach
                </x-menu>
                <div class="mt-4 text-xs text-base-content/60">حالت فقط مشاهده</div>
            </x-card>
        </aside>

