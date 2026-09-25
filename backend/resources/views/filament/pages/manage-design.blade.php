<x-filament-panels::page>
    @php($live = $this->getLive())

    <style>
        .dz-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr)); }
        .dz-card { display: flex; flex-direction: column; gap: 0.75rem; padding: 1rem; border: 1px solid rgba(120, 120, 120, 0.25); border-radius: 0.75rem; }
        .dz-swatches { display: flex; gap: 0.375rem; }
        .dz-swatch { width: 1.75rem; height: 1.75rem; border-radius: 9999px; border: 1px solid rgba(120, 120, 120, 0.35); }
        .dz-muted { font-size: 0.875rem; opacity: 0.7; }
        .dz-badge { display: inline-block; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; background: rgba(34, 197, 94, 0.15); color: rgb(21, 128, 61); }
        .dz-history { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .dz-history td { padding: 0.5rem 0.25rem; border-bottom: 1px solid rgba(120, 120, 120, 0.15); vertical-align: middle; }
        .dz-history td:last-child { text-align: right; }
    </style>

    <x-filament::section>
        <x-slot name="heading">Live now · အခုသုံးနေတာ</x-slot>
        <p><strong>{{ $this->describe($live) }}</strong></p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Presets · ဒီဇိုင်းများ</x-slot>
        <x-slot name="description">Start from one of these. Your products, prices and orders don't change. · ပစ္စည်း၊ ဈေးနှုန်းနဲ့ အော်ဒါတွေ မပြောင်းပါ။</x-slot>

        <div class="dz-grid">
            @foreach ($this->getPresets() as $key => $preset)
                <div class="dz-card" wire:key="preset-{{ $key }}">
                    <div>
                        <strong>{{ $preset['label'] }}</strong>
                        @if ($preset['live'])
                            <span class="dz-badge">Live · သုံးနေ</span>
                        @endif
                        <p class="dz-muted">{{ $preset['description'] }}</p>
                    </div>
                    <div class="dz-swatches" aria-hidden="true">
                        @foreach ($preset['colors'] as $color)
                            <span class="dz-swatch" style="background: {{ $color }}"></span>
                        @endforeach
                    </div>
                    <div>{{ ($this->usePresetAction)(['preset' => $key]) }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">History · မှတ်တမ်း</x-slot>
        <x-slot name="description">Every change is kept. To undo, use an older design again. · ပြောင်းတိုင်း သိမ်းထားပါတယ်။</x-slot>

        @php($history = $this->getHistory())
        @if ($history->isEmpty())
            <p class="dz-muted">No changes yet. Your shop uses the Clean design. · မပြောင်းရသေးပါ။</p>
        @else
            <table class="dz-history">
                @foreach ($history as $design)
                    <tr wire:key="design-{{ $design->id }}">
                        <td>
                            <strong>{{ $this->describe($design) }}</strong>
                            <div class="dz-muted">
                                {{ $design->created_at?->format('j M Y, g:i a') }}@if ($design->creator) · {{ $design->creator->name }}@endif
                            </div>
                        </td>
                        <td>
                            @if ($live?->is($design))
                                <span class="dz-badge">Live · သုံးနေ</span>
                            @else
                                {{ ($this->publishAction)(['design' => $design->id]) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
