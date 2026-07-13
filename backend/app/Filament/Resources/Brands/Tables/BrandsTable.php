<?php

namespace App\Filament\Resources\Brands\Tables;

use App\Enums\BrandType;
use App\Filament\Resources\Brands\BrandResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo_path')
                    ->label('Logo')
                    ->disk('public')
                    ->circular(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (BrandType $state): string => match ($state) {
                        BrandType::Designer => 'gray',
                        BrandType::Niche => 'info',
                    }),
                TextColumn::make('fragrances_count')
                    ->label('Fragrances')
                    ->counts('fragrances'),
                ToggleColumn::make('is_active'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('type')
                    ->options(BrandType::class),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                EditAction::make(),
                BrandResource::safeDeleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalDescription('Deleting brands also deletes all of their fragrances and decant prices. This cannot be undone.')
                        ->action(function (Collection $records, DeleteBulkAction $action): void {
                            $kept = 0;

                            foreach ($records as $record) {
                                try {
                                    $record->delete();
                                } catch (QueryException) {
                                    $kept++;
                                }
                            }

                            if ($kept > 0) {
                                Notification::make()
                                    ->warning()
                                    ->title("{$kept} brand(s) kept")
                                    ->body('Their fragrances appear in orders — deactivate those brands instead.')
                                    ->send();
                            }

                            $action->success();
                        }),
                ]),
            ]);
    }
}
