<?php

namespace App\Filament\Studio\Resources\Shops;

use App\Filament\Studio\Resources\Shops\Pages\ManageShops;
use App\Filament\Studio\Resources\Shops\Schemas\ShopForm;
use App\Filament\Studio\Resources\Shops\Tables\ShopsTable;
use App\Models\Shop;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The studio's shop registry, living in the studio panel (/studio) — the
 * super-admin home that is not inside any shop. Shop is the tenant *root*, not a
 * tenant-owned model, so this lists every shop. Two gates, belt and braces:
 * User::canAccessPanel keeps non-studio users out of the whole panel, and
 * canAccess() below keeps this resource studio-only even if it were ever
 * re-registered elsewhere.
 */
class ShopResource extends Resource
{
    protected static ?string $model = Shop::class;

    // Moot in the (tenant-free) studio panel, but a correct claim wherever this
    // resource lands: Shop must never be narrowed to a current tenant.
    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_studio;
    }

    public static function form(Schema $schema): Schema
    {
        return ShopForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShopsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShops::route('/'),
        ];
    }
}
