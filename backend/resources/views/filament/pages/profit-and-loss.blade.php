<x-filament-panels::page>
    <div class="flex items-center justify-between">
        <x-filament::button color="gray" wire:click="previousMonth">← Previous</x-filament::button>
        <span class="text-lg font-bold">{{ $label }}</span>
        <x-filament::button color="gray" wire:click="nextMonth">Next →</x-filament::button>
    </div>

    <x-filament::section>
        <table class="w-full text-sm">
            <tbody>
                <tr>
                    <td class="py-2">
                        Sales income
                        <span class="block text-xs text-gray-500 dark:text-gray-400">From line snapshots − discounts; the delivery fee is not income</span>
                    </td>
                    <td class="py-2 text-right font-medium tabular-nums">{{ \App\Support\Money::kyat($pnl->salesIncomeMmk) }}</td>
                </tr>
                <tr>
                    <td class="py-2">
                        − COGS (liquid only)
                        <span class="block text-xs text-gray-500 dark:text-gray-400">On {{ $pnl->costedOrders }} of {{ $pnl->totalOrders }} orders — vial, label & spillage not included</span>
                    </td>
                    <td class="py-2 text-right tabular-nums">−{{ \App\Support\Money::kyat($pnl->cogsMmk) }}</td>
                </tr>
                <tr class="border-t border-gray-200 dark:border-gray-700">
                    <td class="py-2 font-semibold">Gross margin (liquid only)</td>
                    <td class="py-2 text-right font-semibold tabular-nums">{{ \App\Support\Money::kyat($pnl->grossMarginMmk) }}</td>
                </tr>

                <tr>
                    <td class="pt-4 pb-1 font-medium">
                        − Operating expenses
                        <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">As entered — a missing expense inflates net. Courier pay sits in the delivery line; stock purchases below the line.</span>
                    </td>
                    <td class="pt-4 pb-1 text-right tabular-nums">−{{ \App\Support\Money::kyat($pnl->operatingTotalMmk) }}</td>
                </tr>
                @foreach ($pnl->operatingByCategory as $category => $amount)
                    <tr>
                        <td class="py-0.5 pl-6 text-xs text-gray-500 dark:text-gray-400">{{ \App\Enums\ExpenseCategory::from($category)->label() }}</td>
                        <td class="py-0.5 text-right text-xs tabular-nums text-gray-500 dark:text-gray-400">−{{ \App\Support\Money::kyat($amount) }}</td>
                    </tr>
                @endforeach
                @if ($pnl->operatingByCategory === [])
                    <tr>
                        <td class="py-0.5 pl-6 text-xs text-gray-500 dark:text-gray-400">Nothing entered this month</td>
                        <td class="py-0.5 text-right text-xs tabular-nums text-gray-500 dark:text-gray-400">0 Ks</td>
                    </tr>
                @endif

                <tr>
                    <td class="py-2">
                        + Delivery result
                        <span class="block text-xs text-gray-500 dark:text-gray-400">Fees collected {{ \App\Support\Money::kyat($pnl->deliveryFeesCollectedMmk) }} − courier paid {{ \App\Support\Money::kyat($pnl->courierPaidMmk) }}</span>
                    </td>
                    <td class="py-2 text-right tabular-nums">{{ \App\Support\Money::kyat($pnl->deliveryResultMmk) }}</td>
                </tr>

                <tr class="border-t-2 border-gray-300 dark:border-gray-600">
                    <td class="py-3 font-bold">
                        Net operating profit
                        <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">Liquid COGS; expenses as entered</span>
                    </td>
                    <td @class([
                        'py-3 text-right font-bold tabular-nums',
                        'text-danger-600 dark:text-danger-400' => $pnl->netOperatingMmk < 0,
                    ])>{{ \App\Support\Money::kyat($pnl->netOperatingMmk) }}</td>
                </tr>
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Below the line">
        <table class="w-full text-sm">
            <tbody>
                <tr>
                    <td class="py-1">
                        Cash into stock
                        <span class="block text-xs text-gray-500 dark:text-gray-400">Inventory, never an expense — it becomes COGS as it pours</span>
                    </td>
                    <td class="py-1 text-right tabular-nums">{{ \App\Support\Money::kyat($pnl->stockPurchasesMmk) }}</td>
                </tr>
                <tr>
                    <td class="py-1">
                        Discounts given
                        <span class="block text-xs text-gray-500 dark:text-gray-400">Already netted out of sales income</span>
                    </td>
                    <td class="py-1 text-right tabular-nums">{{ \App\Support\Money::kyat($pnl->discountsGivenMmk) }}</td>
                </tr>
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
