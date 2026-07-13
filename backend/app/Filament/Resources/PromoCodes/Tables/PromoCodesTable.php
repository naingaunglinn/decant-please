<?php

namespace App\Filament\Resources\PromoCodes\Tables;

use App\Enums\PromoType;
use App\Models\PromoCode;
use App\Support\Money;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class PromoCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->searchable(),
                TextColumn::make('deal')
                    ->state(function (PromoCode $record): string {
                        $deal = $record->type === PromoType::Percent
                            ? "{$record->value}% off"
                            : Money::kyat($record->value).' off';

                        if ($record->type === PromoType::Percent && $record->max_discount_mmk) {
                            $deal .= ' (max '.Money::kyat($record->max_discount_mmk).')';
                        }

                        return $deal;
                    }),
                TextColumn::make('min_order_mmk')
                    ->label('Min order')
                    ->formatStateUsing(fn (int $state): string => Money::kyat($state))
                    ->placeholder('—'),
                TextColumn::make('usage')
                    ->state(fn (PromoCode $record): string => $record->times_used.' / '.($record->usage_limit ?? '∞')),
                TextColumn::make('expires_at')
                    ->date()
                    ->sortable()
                    ->placeholder('Never'),
                ToggleColumn::make('is_active'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_active'),
                SelectFilter::make('type')
                    ->options(PromoType::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->icon(Heroicon::OutlinedEye)
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
