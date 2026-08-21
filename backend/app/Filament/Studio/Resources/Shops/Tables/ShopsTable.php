<?php

namespace App\Filament\Studio\Resources\Shops\Tables;

use App\Enums\ShopStatus;
use App\Filament\Studio\Resources\Shops\ShopResource;
use App\Models\Shop;
use App\Models\ShopDomain;
use App\Support\StudioShopStats;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Studio shop registry (Step 34 PR-3). Answers, at a glance, what a registry is
 * for: which shops are alive, who owns them, and whether any need attention. Owner +
 * per-shop order figures are cross-shop reads; the order aggregate is the single
 * budgeted withoutTenancy (StudioShopStats), and owner/config resolve through
 * platform tables or the blessed ShopConfig set-context — no other bypass.
 */
class ShopsTable
{
    public static function configure(Table $table): Table
    {
        // Computed ONCE per render and captured by the columns — the single budgeted
        // cross-shop order read (design §8 ledger).
        $orderStats = StudioShopStats::orderStats();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'primaryDomain',
                // The owner is the member holding shop_owner; deterministic by id so a
                // shop with several owner logins always shows the same one.
                'users' => fn ($related) => $related->role('shop_owner')->orderBy('users.id'),
            ]))
            // Pin the panel: getUrl() otherwise resolves against the ambient panel,
            // which isn't reliably set during Livewire table renders/tests.
            ->recordUrl(fn (Shop $record): string => ShopResource::getUrl('view', ['record' => $record], panel: 'studio'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('owner')
                    ->state(fn (Shop $record): ?string => $record->users->first()?->name)
                    ->placeholder('—'),
                TextColumn::make('last_activity')
                    ->label('Last activity')
                    ->state(fn (Shop $record) => StudioShopStats::forShop($record->id, $orderStats)->last_at)
                    ->dateTime('M j, Y')
                    ->placeholder('—'),
                TextColumn::make('orders_this_month')
                    ->label('Orders this month')
                    ->state(fn (Shop $record): int => (int) StudioShopStats::forShop($record->id, $orderStats)->this_month)
                    ->badge()
                    ->color('gray'),
                TextColumn::make('slug')
                    ->badge()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('primaryDomain.host')
                    ->label('Domain')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Registered')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Default view hides archived shops; the operator can add Archived or
                // narrow to any status. Multiple-select defaulting to the live states.
                SelectFilter::make('status')
                    ->multiple()
                    ->options(ShopStatus::class)
                    ->default([
                        ShopStatus::Onboarding->value,
                        ShopStatus::Live->value,
                        ShopStatus::Suspended->value,
                    ]),
                // Needs attention (D1): onboarding OR suspended OR no payment config —
                // never archived. Payment-incomplete ids resolve lazily (only when the
                // filter is applied) through ShopConfig, not a bypass.
                Filter::make('needs_attention')
                    ->label('Needs attention')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', '!=', ShopStatus::Archived)
                        ->where(fn (Builder $query) => $query
                            ->whereIn('status', [ShopStatus::Onboarding, ShopStatus::Suspended])
                            ->orWhereIn('id', StudioShopStats::paymentIncompleteShopIds()))),
            ])
            ->recordActions([
                ActionGroup::make([
                    // The bridge down: step into this shop's tenant panel to operate it.
                    Action::make('openPanel')
                        ->label('Open panel')
                        ->icon(Heroicon::OutlinedArrowRightCircle)
                        ->url(fn (Shop $record): string => Filament::getPanel('admin')->getUrl($record)),
                    // ADR-0004 storefront domains (replace-set semantics; unverified
                    // rows never resolve publicly or enter the CORS allowlist).
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
                ]),
            ])
            ->emptyStateHeading('Register your first shop')
            ->emptyStateDescription('Each shop gets its own storefront and admin panel. Register one to see it here.')
            ->emptyStateIcon(Heroicon::OutlinedBuildingStorefront)
            ->defaultSort('name');
    }
}
