<?php

namespace App\Filament\Resources\DeliveryZones\Tables;

use App\Enums\Courier;
use App\Enums\Region;
use App\Models\DeliveryTownship;
use App\Models\DeliveryTownshipCourier;
use App\Support\DeliveryZoneImport;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class DeliveryZonesTable
{
    /**
     * The bulk actions are the point of this page: a district or region
     * filter plus one action prices a whole area in two clicks.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('couriers'))
            ->columns([
                TextColumn::make('name')
                    ->description(fn (DeliveryTownship $record): ?string => $record->name_mm)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('district')
                    ->sortable()
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('fee_mmk')
                    ->label('Fee')
                    ->formatStateUsing(fn (int $state): string => Money::kyat($state))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('coverage')
                    ->badge()
                    // One pill per courier route; a suspended route says so, and
                    // a dead zone reads "No courier" — never a silently absent row.
                    ->state(fn (DeliveryTownship $record): array => $record->couriers->isEmpty()
                        ? ['No courier']
                        : $record->couriers
                            ->map(fn (DeliveryTownshipCourier $courier): string => $courier->courier->label()
                                .($courier->is_available ? '' : ' — suspended'))
                            ->all())
                    ->color(fn (string $state): string => match (true) {
                        $state === 'No courier' => 'gray',
                        str_contains($state, 'suspended') => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('cheapest_cost')
                    ->label('Cheapest cost')
                    ->state(fn (DeliveryTownship $record): ?string => ($cost = $record->cheapestAvailableCostMmk()) === null
                        ? null
                        : Money::kyat($cost))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('best_case_margin')
                    ->label('Best-case margin')
                    // blank when every cost is unknown — blank, never 0 (the v19 rule)
                    ->state(fn (DeliveryTownship $record): ?string => ($margin = $record->bestCaseMarginMmk()) === null
                        ? null
                        : Money::kyat($margin))
                    ->color(fn (DeliveryTownship $record): string => ($margin = $record->bestCaseMarginMmk()) !== null && $margin < 0
                        ? 'danger'
                        : 'gray')
                    ->tooltip('Fee − cheapest recorded courier cost. Display only — net profit\'s courier side stays the delivery expense category.')
                    ->alignEnd()
                    ->toggleable(),
                ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->defaultGroup(
                Group::make('region')
                    ->getTitleFromRecordUsing(fn (DeliveryTownship $record): string => $record->region->label())
            )
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('region')
                    ->options(Region::class),
                SelectFilter::make('district')
                    ->options(fn (): array => DeliveryTownship::query()
                        ->whereNotNull('district')
                        ->distinct()
                        ->orderBy('district')
                        ->pluck('district', 'district')
                        ->all()),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                SelectFilter::make('served_by')
                    ->label('Served by')
                    ->options(Courier::class)
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, string $courier) => $q->whereHas('couriers', fn (Builder $c) => $c->where('courier', $courier)),
                    )),
                // the decanter finds the gaps deliberately, not by stumbling on them
                Filter::make('no_courier')
                    ->label('No courier')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('couriers')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                self::importCsvAction(),
                Action::make('downloadCsvTemplate')
                    ->label('CSV template')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn () => response()->streamDownload(
                        function (): void {
                            echo DeliveryZoneImport::template();
                        },
                        'delivery-zones-template.csv',
                        ['Content-Type' => 'text/csv'],
                    )),
                BulkActionGroup::make([
                    BulkAction::make('setFee')
                        ->label('Set fee')
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->modalHeading('Set delivery fee for selected townships')
                        ->modalDescription('One customer-facing fee per township, applied to every selected row. 0 is real free delivery.')
                        ->schema([
                            TextInput::make('fee_mmk')
                                ->label('Fee')
                                ->mask(RawJs::make('$money($input, \'.\', \',\', 0)'))
                                ->stripCharacters(',')
                                ->numeric()
                                ->minValue(0)
                                ->suffix('Ks')
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each->update(['fee_mmk' => (int) $data['fee_mmk']]);

                            Notification::make()->success()
                                ->title('Fee set on '.$records->count().' township(s).')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('setCost')
                        ->label('Set cost for a courier')
                        ->icon(Heroicon::OutlinedTruck)
                        ->modalHeading('Record a courier cost for selected townships')
                        ->modalDescription('Records this courier\'s cost on every selected township — and marks the courier as serving any township that didn\'t have the route yet (its spelling defaults to the township name; edit per township where the courier spells it differently). Reference only: never enters a fee, a total, or the P&L.')
                        ->schema([
                            Select::make('courier')
                                ->options(Courier::class)
                                ->required(),
                            TextInput::make('cost_mmk')
                                ->label('Cost')
                                ->mask(RawJs::make('$money($input, \'.\', \',\', 0)'))
                                ->stripCharacters(',')
                                ->numeric()
                                ->minValue(1)
                                ->suffix('Ks')
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            foreach ($records as $record) {
                                $route = $record->couriers()->firstOrNew(['courier' => $data['courier']]);

                                if (! $route->exists) {
                                    $route->courier_name = $record->name;
                                    $route->is_available = true;
                                }

                                $route->cost_mmk = (int) $data['cost_mmk'];
                                $route->save();
                            }

                            Notification::make()->success()
                                ->title('Cost recorded on '.$records->count().' township(s).')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
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

    protected static function importCsvAction(): Action
    {
        return Action::make('importCsv')
            ->label('Import CSV')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Import delivery zones from CSV')
            ->modalDescription('Same columns as the seed file: region, name, name_mm, fee_mmk. Existing townships update from non-blank cells only; new ones arrive inactive until you price and activate them. Courier routes and costs are never imported — edit them per township or use the set-cost bulk action.')
            ->schema([
                FileUpload::make('file')
                    ->label('Zones CSV')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data) {
                try {
                    $import = DeliveryZoneImport::run($data['file']->get());
                } catch (InvalidArgumentException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Nothing imported')
                        ->body($e->getMessage())
                        ->send();

                    return null;
                }

                $summary = collect([
                    "{$import->created} created",
                    $import->updated ? "{$import->updated} updated" : null,
                    $import->failures ? count($import->failures).' failed' : null,
                ])->filter()->implode(', ');

                if ($import->failures === []) {
                    Notification::make()->success()->title('Import finished')->body($summary)->send();

                    return null;
                }

                // The failed rows come back as a CSV to fix and re-upload —
                // safe, because a re-import only overwrites from non-blank cells.
                Notification::make()
                    ->warning()
                    ->title('Import finished — some rows failed')
                    ->body("{$summary}. The failed rows are downloading now; fix the error column and re-upload.")
                    ->persistent()
                    ->send();

                $failures = $import->failuresCsv();

                return response()->streamDownload(
                    function () use ($failures): void {
                        echo $failures;
                    },
                    'delivery-zones-import-failures.csv',
                    ['Content-Type' => 'text/csv'],
                );
            });
    }
}
