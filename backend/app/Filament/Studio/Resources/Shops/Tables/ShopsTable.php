<?php

namespace App\Filament\Studio\Resources\Shops\Tables;

use App\Models\Shop;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->badge()
                    ->searchable()
                    ->copyable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Registered')
                    ->date()
                    ->sortable(),
            ])
            ->recordActions([
                // The bridge down from the studio: step into this shop's tenant
                // panel (/admin/{slug}) to operate its catalog, orders and finance.
                Action::make('openPanel')
                    ->label('Open panel')
                    ->icon(Heroicon::OutlinedArrowRightCircle)
                    ->url(fn (Shop $record): string => Filament::getPanel('admin')->getUrl($record)),
            ])
            ->defaultSort('name');
    }
}
