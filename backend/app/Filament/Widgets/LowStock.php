<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Templates\Templates;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Reorder panel: the products whose stock has fallen to or below their reorder
 * line, in either stock mode (step 40) — a pooled product's running amount, or a
 * per-variant product's options by name ("M / Blue: 1"). Warn-only — it never
 * stops an order, it just tells the seller what to buy next. Only tracked counts
 * can appear (see Product::scopeLowStock). Scoping: the BelongsToShop scope on
 * Product (a widget is outside Filament's resource tenancy).
 */
class LowStock extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        // The shop's own word ("fragrance"), so a decant shop reads what it always did.
        $noun = Templates::forShop()->productNouns()[0];

        return $table
            ->heading('Low stock — reorder soon')
            ->query(
                Product::query()
                    ->with(['brand', 'variants'])
                    ->lowStock()
                    // Per-variant products (no pooled amount) list after pooled
                    // ones on both engines: NULLs sort differently on SQLite and
                    // Postgres, so the order is spelled out.
                    ->orderByRaw('CASE WHEN products.stock_amount IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('products.stock_amount')
                    ->orderBy('products.name')
            )
            ->emptyStateHeading('Nothing running low')
            ->emptyStateDescription("Every tracked {$noun} is above its reorder threshold.")
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->columns([
                TextColumn::make('brand.name')
                    ->label('Brand'),
                TextColumn::make('name')
                    ->label(ucfirst($noun))
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('stock_amount')
                    ->label('Remaining')
                    ->badge()
                    ->color('danger')
                    ->state(fn (Product $record): string => $record->pooledStock()
                        ? $record->formatAmount((int) $record->stock_amount)
                        : $record->lowVariants()->map(fn ($variant): string => "{$variant->label()}: {$variant->stock_qty}")->implode(', '))
                    ->alignEnd(),
                TextColumn::make('low_stock_threshold')
                    ->label('Reorder at')
                    ->formatStateUsing(fn (Product $record, int $state): string => $record->pooledStock() ? $record->formatAmount($state) : "{$state} pcs")
                    ->alignEnd(),
            ])
            ->paginated(false);
    }
}
