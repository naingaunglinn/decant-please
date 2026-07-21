<?php

namespace App\Filament\Resources\Fragrances\RelationManagers;

use App\Models\Bottle;
use App\Models\Fragrance;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Bottle history on the Fragrance edit page. Rows are never edited or deleted
 * here — the only way in is "Log new bottle" (Bottle::logFor), which is what
 * keeps "one active bottle per fragrance" true and stock in sync. A mistyped
 * bottle is corrected by logging a new one with the right amount.
 */
class BottlesRelationManager extends RelationManager
{
    protected static string $relationship = 'bottles';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Bottles')
            ->columns([
                TextColumn::make('total_ml')
                    ->label('Bottle size')
                    ->suffix(' ml'),
                TextColumn::make('remaining_ml')
                    ->label('Remaining')
                    ->suffix(' ml')
                    ->color(fn (Bottle $record): ?string => $record->is_active
                        && $this->getOwnerRecord()->hasSpentBottle() ? 'danger' : null)
                    ->description(fn (Bottle $record): ?string => match (true) {
                        ! $record->is_active || ! $this->getOwnerRecord()->hasSpentBottle() => null,
                        $record->remaining_ml === 0 => 'Fully decanted',
                        default => 'Too little for any size',
                    }),
                TextColumn::make('opened_at')
                    ->label('Opened')
                    ->date(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No bottle logged yet')
            ->emptyStateDescription('Stock stays on the manual toggles until the first bottle is logged.')
            ->headerActions([
                Action::make('logNewBottle')
                    ->label('Log new bottle')
                    ->icon(Heroicon::OutlinedBeaker)
                    ->modalHeading('Log new bottle')
                    ->modalDescription('Starts stock tracking from this bottle. The current active bottle (if any) is retired — its leftovers do not carry over.')
                    ->schema([
                        TextInput::make('total_ml')
                            ->label('Volume')
                            ->numeric()
                            // A bottle that can't fill even one of this fragrance's
                            // decants would put every size out of stock the moment
                            // it's logged — refuse it with an explanation instead.
                            ->minValue(fn (): int => max(1, (int) $this->getOwnerRecord()->decantPrices()->min('size_ml')))
                            ->validationMessages([
                                'min' => function (): string {
                                    $smallest = (int) $this->getOwnerRecord()->decantPrices()->min('size_ml');

                                    return $smallest > 0
                                        ? "Smaller than the smallest decant size ({$smallest}ml) — this bottle couldn't fill a single decant, so every size would go straight out of stock."
                                        : 'Must be at least 1ml.';
                                },
                            ])
                            ->suffix('ml')
                            ->required()
                            ->helperText('If the bottle is already part-used, enter what\'s actually left.'),
                        DatePicker::make('opened_at')
                            ->label('Opened on')
                            ->default(today())
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        /** @var Fragrance $fragrance */
                        $fragrance = $this->getOwnerRecord();

                        Bottle::logFor($fragrance, (int) $data['total_ml'], $data['opened_at']);

                        Notification::make()
                            ->success()
                            ->title('Bottle logged — stock now tracks it automatically.')
                            ->send();
                    }),
            ]);
    }
}
