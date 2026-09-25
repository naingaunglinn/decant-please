{{-- Step 45: help under the login and sign-up forms, where a stuck seller has no other way to reach us. --}}
<div class="fi-help-links" style="text-align: center; font-size: 0.875rem">
    Need help? · အကူအညီ လိုပါသလား?
    @foreach ($links as $link)
        <x-filament::link
            :href="$link['url']"
            :target="$link['new_tab'] ? '_blank' : null"
            :rel="$link['new_tab'] ? 'noopener' : null"
        >{{ $link['label'] }}</x-filament::link>@if (! $loop->last) · @endif
    @endforeach
</div>
