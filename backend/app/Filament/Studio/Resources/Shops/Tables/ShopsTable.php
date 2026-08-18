<?php

namespace App\Filament\Studio\Resources\Shops\Tables;

use App\Models\Shop;
use App\Models\ShopDomain;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ShopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('primaryDomain'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->badge()
                    ->searchable()
                    ->copyable(),
                TextColumn::make('primaryDomain.host')
                    ->label('Domain')
                    ->placeholder('—')
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
                // ADR-0004: the hosts this shop's storefront answers on. The modal
                // is the whole set (replace-set semantics): rows removed here are
                // deleted, exactly one row is primary, and unverified rows never
                // resolve publicly or enter the CORS allowlist.
                Action::make('domains')
                    ->label('Domains')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->modalHeading(fn (Shop $record): string => "Storefront domains — {$record->name}")
                    ->modalDescription('Host only (client-a.com, shop.decantplease.com, localhost:3001) — no scheme, no path. Exactly one primary; unverified rows never resolve on the storefront.')
                    ->fillForm(fn (Shop $record): array => [
                        'domains' => $record->domains()
                            ->orderByDesc('is_primary')->orderBy('host')
                            ->get()
                            ->map(fn (ShopDomain $domain): array => [
                                'host' => $domain->host,
                                'is_primary' => $domain->is_primary,
                                'verified' => $domain->verified_at !== null,
                            ])->all(),
                    ])
                    ->schema([
                        Repeater::make('domains')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('host')
                                    ->required()
                                    ->maxLength(255),
                                Toggle::make('is_primary')
                                    ->label('Primary'),
                                Toggle::make('verified')
                                    ->label('Verified'),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Add domain'),
                    ])
                    ->action(fn (Shop $record, array $data) => $record->syncDomains($data['domains'] ?? [])),
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
