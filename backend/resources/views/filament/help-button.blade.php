{{-- Step 45: the admin top bar's Help button (App\Support\StudioHelp). Rendered only when a channel is set. --}}
<x-filament::dropdown placement="bottom-end" teleport class="fi-help-menu">
    <x-slot name="trigger">
        <x-filament::icon-button
            icon="heroicon-o-question-mark-circle"
            color="gray"
            size="lg"
            label="Help · အကူအညီ"
            tooltip="Help · အကူအညီ"
        />
    </x-slot>

    <x-filament::dropdown.header icon="heroicon-o-chat-bubble-left-right">
        Message us · ကျွန်ုပ်တို့ကို စာပို့ပါ
    </x-filament::dropdown.header>

    @if ($shop)
        {{-- Inline style: the panel has no custom Tailwind theme to compile utilities into. --}}
        <div style="padding: 0 0.75rem 0.5rem; font-size: 0.75rem; opacity: 0.8">
            Say which shop · ဆိုင်အမည် ပြောပါ:
            <strong>{{ $shop->name }} ({{ $shop->slug }})</strong>
        </div>
    @endif

    <x-filament::dropdown.list>
        @foreach ($links as $link)
            <x-filament::dropdown.list.item
                tag="a"
                :href="$link['url']"
                :target="$link['new_tab'] ? '_blank' : null"
                :rel="$link['new_tab'] ? 'noopener' : null"
                icon="heroicon-o-chat-bubble-oval-left"
            >
                {{ $link['label'] }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
