<?php

namespace App\Filament\Resources\Brands\Tables;

use App\Enums\BrandType;
use App\Filament\Resources\Brands\BrandResource;
use App\Templates\Templates;
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

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo_path')
                    ->label('Logo')
                    ->disk(config('filesystems.media_disk'))
                    ->circular(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->visible(fn (): bool => Templates::forShop()->brandTypes()) // designer / niche: decant only (step 38b)
                    ->badge()
                    ->color(fn (?BrandType $state): string => $state === BrandType::Niche ? 'info' : 'gray'),
                TextColumn::make('products_count')
                    ->label('Fragrances')
                    ->counts('products'),
                ToggleColumn::make('is_active'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('type')
                    ->visible(fn (): bool => Templates::forShop()->brandTypes())
                    ->options(BrandType::class),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                EditAction::make(),
                BrandResource::safeDeleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // A brand with products is FK-protected (NO ACTION on delete): keep it.
                    DeleteBulkAction::make()
                        ->action(function (Collection $records, DeleteBulkAction $action): void {
                            $kept = 0;

                            foreach ($records as $record) {
                                if ($record->products()->exists()) {
                                    $kept++;

                                    continue;
                                }

                                $record->delete();
                            }

                            if ($kept > 0) {
                                Notification::make()
                                    ->warning()
                                    ->title("{$kept} brand(s) kept")
                                    ->body('They have fragrances — deactivate them instead.')
                                    ->send();
                            }

                            $action->success();
                        }),
                ]),
            ]);
    }
}
