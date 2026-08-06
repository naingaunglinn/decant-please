<?php

namespace App\Support;

use App\Enums\ExpenseCategory;
use App\Enums\OrderStatus;
use App\Models\Expense;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * One month's P&L, computed from the records the app already keeps and labeled
 * with its own limits (prompts/29): income from line snapshots − discounts
 * (never total_mmk, which contains the courier's fee), liquid COGS over
 * fully-costed orders with N-of-M coverage, operating expenses as entered, a
 * delivery result line (fees collected − courier paid — FINANCE.md gap 4,
 * measured), and stock purchases below the line as inventory.
 *
 * §4 everywhere: cancelled and rejected orders never count. Summed in PHP —
 * a month of rows is tiny (the RevenueChart precedent).
 */
final class MonthlyPnl
{
    /**
     * @param array<string, int> $operatingByCategory category value => Kyat; stock_purchase and delivery excluded
     */
    private function __construct(
        public readonly int $salesIncomeMmk,
        public readonly int $cogsMmk,
        public readonly int $grossMarginMmk,
        public readonly array $operatingByCategory,
        public readonly int $operatingTotalMmk,
        public readonly int $deliveryFeesCollectedMmk,
        public readonly int $courierPaidMmk,
        public readonly int $deliveryResultMmk,
        public readonly int $netOperatingMmk,
        public readonly int $stockPurchasesMmk,
        public readonly int $discountsGivenMmk,
        public readonly int $costedOrders,
        public readonly int $totalOrders,
    ) {}

    public static function for(int $year, int $month): self
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $end = $start->addMonth();

        $orders = Order::query()
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->with('items')
            ->get();

        $income = $discounts = $fees = $cogs = $costed = 0;

        foreach ($orders as $order) {
            $items = (int) $order->items->sum('line_total_mmk');
            $income += $items - $order->discount_mmk;
            $discounts += $order->discount_mmk;
            $fees += $order->delivery_fee_mmk;

            // Fully-costed orders only — the #71 trap: a bare SUM(line_cost_mmk)
            // would count partially-costed orders' non-null lines.
            $fullyCosted = $order->items->isNotEmpty()
                && ! $order->items->contains(fn ($item): bool => $item->line_cost_mmk === null);

            if ($fullyCosted) {
                $costed++;
                $cogs += (int) $order->items->sum('line_cost_mmk');
            }
        }

        $byCategory = Expense::query()
            ->whereDate('spent_on', '>=', $start->toDateString())
            ->whereDate('spent_on', '<', $end->toDateString())
            ->get()
            ->groupBy(fn (Expense $expense): string => $expense->category->value)
            ->map(fn ($group): int => (int) $group->sum('amount_mmk'));

        $stock = (int) ($byCategory[ExpenseCategory::StockPurchase->value] ?? 0);
        $courier = (int) ($byCategory[ExpenseCategory::Delivery->value] ?? 0);

        // Operating block excludes stock_purchase (inventory — the enum's
        // no-double-count rule) AND delivery: courier pay is subtracted inside
        // the delivery result line, and listing it here too would count it twice.
        $operating = $byCategory->except([
            ExpenseCategory::StockPurchase->value,
            ExpenseCategory::Delivery->value,
        ]);

        $opex = (int) $operating->sum();
        $gross = $income - $cogs;
        $deliveryResult = $fees - $courier;

        return new self(
            salesIncomeMmk: $income,
            cogsMmk: $cogs,
            grossMarginMmk: $gross,
            operatingByCategory: $operating->all(),
            operatingTotalMmk: $opex,
            deliveryFeesCollectedMmk: $fees,
            courierPaidMmk: $courier,
            deliveryResultMmk: $deliveryResult,
            netOperatingMmk: $gross - $opex + $deliveryResult,
            stockPurchasesMmk: $stock,
            discountsGivenMmk: $discounts,
            costedOrders: $costed,
            totalOrders: $orders->count(),
        );
    }
}
