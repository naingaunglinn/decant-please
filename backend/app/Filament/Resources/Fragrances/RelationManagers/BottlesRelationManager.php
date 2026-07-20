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
                    ->suffix(' ml'),
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
                            ->minValue(1)
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
