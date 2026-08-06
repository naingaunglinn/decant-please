<x-filament-widgets::widget>
    <x-filament::section
        heading="Discount cost — this month"
        description="What codes are giving away, from the order snapshots. Cancelled and rejected orders never count."
    >
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No discounts given this month.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="pb-2 font-medium">Code</th>
                        <th class="pb-2 text-right font-medium">Orders</th>
                        <th class="pb-2 text-right font-medium">Given away</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="py-1">
                                @if ($row['code'])
                                    <span class="font-mono">{{ $row['code'] }}</span>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">Hand-edited (no code)</span>
                                @endif
                            </td>
                            <td class="py-1 text-right tabular-nums">{{ $row['orders'] }}</td>
                            <td class="py-1 text-right tabular-nums">{{ \App\Support\Money::kyat($row['given_mmk']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                @if ($rows->count() > 1)
                    <tfoot>
                        <tr class="border-t border-gray-200 font-medium dark:border-gray-700">
                            <td class="pt-2">Total</td>
                            <td class="pt-2 text-right tabular-nums">{{ $rows->sum('orders') }}</td>
                            <td class="pt-2 text-right tabular-nums">{{ \App\Support\Money::kyat($total) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
