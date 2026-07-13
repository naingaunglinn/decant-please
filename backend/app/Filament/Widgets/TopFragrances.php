<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Fragrance;
use App\Models\OrderItem;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TopFragrances extends TableWidget
{
    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Top fragrances — last 30 days')
            ->query(
                Fragrance::query()
                    ->addSelect(['ordered_qty' => OrderItem::query()
                        ->selectRaw('COALESCE(SUM(order_items.quantity), 0)')
                        ->join('orders', 'orders.id', '=', 'order_items.order_id')
                        ->whereColumn('order_items.fragrance_id', 'fragrances.id')
                        ->where('orders.created_at', '>=', now()->subDays(30))
                        ->whereNotIn('orders.status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value]),
                    ])
                    ->with('brand')
                    ->orderByDesc('ordered_qty')
                    ->limit(5)
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Fragrance')
                    ->state(fn (Fragrance $record): string => "{$record->brand->name} — {$record->name}"),
                TextColumn::make('ordered_qty')
                    ->label('Vials ordered')
                    ->alignEnd(),
            ])
            ->paginated(false);
    }
}
