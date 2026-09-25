<?php

namespace App\Filament\Resources\Brands\Tables;

use App\Enums\BrandType;
use App\Filament\Resources\Brands\BrandResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

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
                    ->badge()
                    ->color(fn (BrandType $state): string => match ($state) {
                        BrandType::Designer => 'gray',
                        BrandType::Niche => 'info',
                    }),
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
                    ->options(BrandType::class),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                EditAction::make(),
                BrandResource::safeDeleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Products outlive their brand (nullOnDelete), so nothing blocks this.
                    DeleteBulkAction::make()
                        ->modalDescription('Their fragrances stay in your catalog without a brand, hidden from the shop until you give them one again.'),
                ]),
            ]);
    }
}
