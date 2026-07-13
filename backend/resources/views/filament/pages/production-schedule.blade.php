@use('App\Filament\Resources\Orders\OrderResource')

<x-filament-panels::page>
    <style>
        .ps-days { display: flex; flex-direction: column; gap: 1.5rem; margin-top: 1.5rem; }
        .ps-range { display: flex; flex-wrap: wrap; gap: 1rem; align-items: end; }
        .ps-range label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.25rem; }
        .ps-range input { border: 1px solid rgba(120, 120, 120, 0.4); border-radius: 0.5rem; padding: 0.375rem 0.75rem; background: transparent; }
        .ps-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .ps-table th { text-align: left; font-weight: 600; padding: 0.5rem 0.75rem; border-bottom: 1px solid rgba(120, 120, 120, 0.3); }
        .ps-table td { padding: 0.5rem 0.75rem; border-bottom: 1px solid rgba(120, 120, 120, 0.15); vertical-align: top; }
        .ps-qty { font-weight: 700; white-space: nowrap; }
        .ps-orders summary { cursor: pointer; }
        .ps-orders a { text-decoration: underline; }
        .ps-empty { opacity: 0.6; font-size: 0.875rem; }
    </style>

    <div class="ps-range">
        <div>
            <label for="ps-from">From</label>
            <input id="ps-from" type="date" wire:model.live="from" />
        </div>
        <div>
            <label for="ps-to">To</label>
            <input id="ps-to" type="date" wire:model.live="to" />
        </div>
    </div>

    <div class="ps-days">
        @foreach ($this->getDays() as $day)
            <x-filament::section>
                <x-slot name="heading">
                    {{ $day['date']->isToday() ? 'Today — ' : '' }}{{ $day['date']->format('l, j M Y') }}
                </x-slot>

                @if ($day['groups']->isEmpty())
                    <p class="ps-empty">Nothing to decant.</p>
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
                                        <details>
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
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
