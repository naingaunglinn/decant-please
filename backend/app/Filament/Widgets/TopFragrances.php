<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TopFragrances extends TableWidget
{
    protected static ?int $sort = 3;

    /**
     * The ranking query, shared with decant:probe-postgres: it carries the two
     * hazards SQLite can't vet — the tenant scope's shop_id inside a join
     * (ambiguous on Postgres unless qualified, ADR-002) and an ORDER BY on a
     * select alias (the original portability bug class). Keeping the probe on
     * this exact builder means the two can never drift apart.
     */
    public static function rankingQuery(): Builder
    {
        return Product::query()
            ->addSelect(['ordered_qty' => OrderItem::query()
                ->selectRaw('COALESCE(SUM(order_items.quantity), 0)')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereColumn('order_items.product_id', 'products.id')
                ->where('orders.created_at', '>=', now()->subDays(30))
                ->whereNotIn('orders.status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value]),
            ])
            ->with('brand')
            ->orderByDesc('ordered_qty')
            ->limit(5);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Top fragrances — last 30 days')
            ->query(self::rankingQuery())
            ->columns([
                TextColumn::make('name')
                    ->label('Fragrance')
                    ->state(fn (Product $record): string => $record->brand ? "{$record->brand->name} — {$record->name}" : $record->name),
                TextColumn::make('ordered_qty')
                    ->label('Vials ordered')
                    ->alignEnd(),
            ])
            ->paginated(false);
    }
}
