@use('App\Filament\Resources\Orders\OrderResource')
@use('Illuminate\Support\Str')

<x-filament-panels::page>
    @php($day = $this->getDay())
    @php($vials = $day['groups']->sum('quantity'))

    <style>
        .ps-day-nav { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; justify-content: space-between; }
        .ps-day-nav-steps { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .ps-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .ps-table th { text-align: left; font-weight: 600; padding: 0.5rem 0.75rem; border-bottom: 1px solid rgba(120, 120, 120, 0.3); }
        .ps-table td { padding: 0.5rem 0.75rem; border-bottom: 1px solid rgba(120, 120, 120, 0.15); vertical-align: top; }
        .ps-qty { font-weight: 700; white-space: nowrap; }
        .ps-orders summary { cursor: pointer; }
        .ps-orders a { text-decoration: underline; }
        .ps-empty-state { text-align: center; padding: 3rem 1rem; }
        .ps-empty-state .ps-empty-title { font-weight: 600; }
        .ps-empty-state .ps-empty-hint { margin-top: 0.25rem; font-size: 0.875rem; opacity: 0.6; }
        .ps-print-only { display: none; }

        /* Print: this page IS the bench sheet. Same conventions as the A5
           invoice (resources/views/pdf/invoice.blade.php): A5 page and 11×12mm
           margins, uppercase letterspaced letterhead and column labels, black
           hairlines on white — and none of the panel chrome. */
        @media print {
            @page { size: A5; margin: 11mm 12mm; }
            :root { color-scheme: light; }
            /* fi-sidebar-close-overlay too: it's only hidden by an `lg:` rule,
               and A5 is narrower than lg — with the sidebar store still open
               from the desktop viewport it washes the whole sheet gray. */
            .fi-sidebar, .fi-sidebar-close-overlay, .fi-topbar-ctn, .fi-header, .ps-no-print { display: none !important; }
            .fi-main-ctn, .fi-main { padding: 0 !important; margin: 0 !important; max-width: none !important; width: 100% !important; }
            body, .fi-body, .fi-page { background: #fff !important; color: #111 !important; }
            .fi-section { box-shadow: none !important; background: transparent !important; border: none !important; border-radius: 0 !important; }
            .fi-section-content-ctn, .fi-section-content { padding: 0 !important; }
            .ps-print-only { display: block; }
            span.ps-print-only { display: inline; }

            .ps-letterhead { margin: 0; font-size: 10pt; font-weight: bold; text-transform: uppercase; letter-spacing: 3px; }
            .ps-doc-title { margin: 1.5mm 0 0; font-size: 15pt; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; }
            .ps-print-meta { margin: 3mm 0 0; font-size: 9pt; }
            .ps-print-meta + .ps-print-meta { margin-top: 0; color: #666; }

            .ps-table { margin-top: 4mm; font-size: 9pt; line-height: 1.45; }
            .ps-table th { padding: 0 1.5mm 1mm 0; font-size: 7pt; text-transform: uppercase; letter-spacing: 1.5px; color: #666; border-bottom: 1px solid #111; }
            .ps-table td { padding: 1mm 1.5mm 1mm 0; border-bottom: 1px solid #e3e3e3; }
            .ps-empty-state { padding: 6mm 0; text-align: left; }
        }
    </style>

    {{-- Screen chrome: step between days, back out to the month, print. --}}
    <div class="ps-day-nav ps-no-print">
        <div class="ps-day-nav-steps">
            <x-filament::button tag="a" color="gray" outlined href="{{ $this->previousDayUrl() }}">
                ← {{ $day['date']->subDay()->format('D j M') }}
            </x-filament::button>
            <x-filament::button tag="a" color="gray" outlined href="{{ $this->calendarUrl() }}">
                Calendar
            </x-filament::button>
            <x-filament::button tag="a" color="gray" outlined href="{{ $this->nextDayUrl() }}">
                {{ $day['date']->addDay()->format('D j M') }} →
            </x-filament::button>
        </div>
        <x-filament::button x-data="{}" x-on:click="window.print()">
            Print
        </x-filament::button>
    </div>

    {{-- Print letterhead — stands in for the hidden panel header on paper. --}}
    <div class="ps-print-only">
        <p class="ps-letterhead">Decant Please!</p>
        <h1 class="ps-doc-title">Production schedule</h1>
        <p class="ps-print-meta">{{ $day['date']->format('l, j M Y') }} · {{ $vials }} {{ Str::plural('vial', $vials) }}</p>
        @unless ($day['date']->isToday())
            <p class="ps-print-meta">Printed {{ now()->format('j M Y') }}</p>
        @endunless
    </div>

    <x-filament::section>
        @if ($day['groups']->isEmpty())
            <div class="ps-empty-state">
                <p class="ps-empty-title">Nothing to decant</p>
                <p class="ps-empty-hint">No orders have vials scheduled for this day.</p>
            </div>
        @else
            <table class="ps-table">
                <thead>
                    <tr>
                        <th>Fragrance</th>
                        <th>Size</th>
                        <th>Vials to fill</th>
                        <th>Orders</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($day['groups'] as $group)
                        <tr>
                            <td>{{ $group['label'] }}</td>
                            <td>{{ $group['size_ml'] }}ml</td>
                            <td class="ps-qty">× {{ $group['quantity'] }}</td>
                            <td class="ps-orders">
                                <details class="ps-no-print">
                                    <summary>{{ $group['orders']->count() }} order(s)</summary>
                                    <ul>
                                        @foreach ($group['orders'] as $order)
                                            <li>
                                                <a href="{{ OrderResource::getUrl('edit', ['record' => $order]) }}">
                                                    #{{ $order->id }} — {{ $order->customer_name }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                                {{-- A closed <details> can't be forced open by CSS,
                                     so paper gets the order list inline instead. --}}
                                <span class="ps-print-only">
                                    {{ $group['orders']->map(fn ($order) => '#'.$order->id.' '.$order->customer_name)->implode(' · ') }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
