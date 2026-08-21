<div>
    @if ($active)
        <div
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-2 text-sm font-medium text-white {{ $inControl ? 'bg-danger-600' : 'bg-primary-600' }}"
        >
            <span class="flex items-center gap-2">
                <x-filament::icon
                    :icon="$inControl ? 'heroicon-o-pencil-square' : 'heroicon-o-eye'"
                    class="h-5 w-5 shrink-0"
                />
                <span>
                    Studio &mdash; you are viewing <strong>{{ $shopName }}</strong>,
                    {{ $inControl ? 'in control (every change is recorded in the audit log).' : 'read-only.' }}
                </span>
            </span>

            @if ($inControl)
                <x-filament::button size="xs" color="gray" icon="heroicon-o-lock-closed" wire:click="release">
                    Return to read-only
                </x-filament::button>
            @else
                <x-filament::button size="xs" color="warning" icon="heroicon-o-lock-open" wire:click="takeControl">
                    Take control
                </x-filament::button>
            @endif
        </div>
    @endif
</div>
