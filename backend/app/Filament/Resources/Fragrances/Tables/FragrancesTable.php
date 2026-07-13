<?php

namespace App\Filament\Resources\Fragrances\Tables;

use App\Enums\BrandType;
use App\Enums\Concentration;
use App\Enums\Gender;
use App\Filament\Resources\Fragrances\FragranceResource;
use App\Models\Fragrance;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

class FragrancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('decantPrices')
                ->withMin(['decantPrices as min_in_stock_price' => fn (Builder $q) => $q->where('in_stock', true)], 'price_mmk'))
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Image')
                    ->disk('public'),
                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('concentration')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('gender')
                    ->badge()
                    ->color(fn (Gender $state): string => match ($state) {
                        Gender::Male => 'blue',
                        Gender::Female => 'rose',
                        Gender::Unisex => 'gray',
                    }),
                TextColumn::make('min_in_stock_price')
                    ->label('From price')
                    ->state(fn (Fragrance $record): string => $record->min_in_stock_price !== null
                        ? 'From '.Money::kyat((int) $record->min_in_stock_price)
                        : 'Out of stock'),
                TextColumn::make('sizes')
                    ->state(fn (Fragrance $record): string => $record->decantPrices
                        ->map(fn ($price) => "{$price->size_ml}ml")
                        ->implode(' · ')),
                ToggleColumn::make('is_active'),
                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('brand_type')
                    ->label('Brand type')
                    ->options(BrandType::class)
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn (Builder $q, string $type) => $q->whereHas('brand', fn (Builder $b) => $b->where('type', $type)))),
                SelectFilter::make('concentration')
                    ->options(Concentration::class),
                SelectFilter::make('gender')
                    ->options(Gender::class),
                TernaryFilter::make('is_active'),
                TernaryFilter::make('is_featured'),
                SelectFilter::make('has_size')
                    ->label('Has size')
                    ->options([5 => '5ml', 10 => '10ml', 30 => '30ml'])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn (Builder $q, string $size) => $q->whereHas('decantPrices', fn (Builder $p) => $p->where('size_ml', $size)))),
            ])
            ->recordActions([
                Action::make('viewOnSite')
                    ->label('View on site')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Fragrance $record): string => rtrim(config('app.frontend_url'), '/')."/fragrance/{$record->slug}")
                    ->openUrlInNewTab(),
                EditAction::make(),
                ReplicateAction::make()
                    ->excludeAttributes(['slug'])
                    ->after(function (Fragrance $record, Fragrance $replica): void {
                        // slug was excluded, so the HasSlug hook generated a fresh one; copy the price rows
                        $record->decantPrices->each(fn ($price) => $replica->decantPrices()->create(
                            $price->only(['size_ml', 'price_mmk', 'in_stock'])
                        ));
                    }),
                FragranceResource::safeDeleteAction(),
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
                    DeleteBulkAction::make()
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
                                    ->title("{$kept} fragrance(s) kept")
                                    ->body('They appear in orders — deactivate them instead.')
                                    ->send();
                            }

                            $action->success();
                        }),
                ]),
            ]);
    }
}
