<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Fragrances\FragranceResource;
use App\Models\Fragrance;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Reorder panel: the fragrances whose running stock has fallen to or below
 * their threshold. Warn-only — it never stops an order, it just tells the
 * decanter what to buy next. Only tracked fragrances (non-null stock_ml)
 * can appear, so untracked catalog rows never show here (see Fragrance::scopeLowStock).
 */
class LowStock extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Low stock — reorder soon')
            ->query(
                Fragrance::query()
                    ->with('brand')
                    ->lowStock()
                    ->orderBy('stock_ml')
            )
            ->emptyStateHeading('Nothing running low')
            ->emptyStateDescription('Every tracked fragrance is above its reorder threshold.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->columns([
                TextColumn::make('brand.name')
                    ->label('Brand'),
                TextColumn::make('name')
                    ->label('Fragrance')
                    ->url(fn (Fragrance $record): string => FragranceResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('stock_ml')
                    ->label('Remaining')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (int $state): string => "{$state}ml")
                    ->alignEnd(),
                TextColumn::make('low_stock_threshold_ml')
                    ->label('Reorder at')
                    ->formatStateUsing(fn (int $state): string => "{$state}ml")
                    ->alignEnd(),
            ])
            ->paginated(false);
    }
}
