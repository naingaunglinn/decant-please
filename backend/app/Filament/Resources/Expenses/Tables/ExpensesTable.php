<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Enums\ExpenseCategory;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('spent_on')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge(),
                TextColumn::make('amount_mmk')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => Money::kyat($state))
                    ->summarize(Sum::make()->formatStateUsing(fn ($state): string => Money::kyat((int) $state)))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('note')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->defaultSort('spent_on', 'desc')
            ->filters([
                SelectFilter::make('category')
                    ->options(ExpenseCategory::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
