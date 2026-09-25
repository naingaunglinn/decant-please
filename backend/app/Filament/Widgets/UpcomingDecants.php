<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Pages\ProductionSchedule;
use App\Models\Order;
use App\Support\Money;
use App\Templates\Templates;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class UpcomingDecants extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Upcoming decants — next 7 days')
            ->query(
                Order::query()
                    ->with('items')
                    ->whereBetween('prep_date', [today(), today()->addDays(7)])
                    ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])
                    ->orderBy('prep_date')
            )
            ->headerActions([
                Action::make('open_schedule')
                    ->label('Open production schedule')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->url(ProductionSchedule::getUrl()),
            ])
            ->columns([
                TextColumn::make('prep_date')
                    ->label(fn (): string => Templates::forShop()->prepDateLabel())
                    ->date(),
                TextColumn::make('customer_name')
                    ->label('Customer'),
                TextColumn::make('items_summary')
                    ->label('Items')
                    ->state(fn (Order $record): string => $record->items
                        ->map(fn ($item) => "{$item->variantLabel()} × {$item->quantity}")
                        ->implode(', ')),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('total_mmk')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => Money::kyat($state))
                    ->alignEnd(),
            ])
            ->paginated(false);
    }
}
