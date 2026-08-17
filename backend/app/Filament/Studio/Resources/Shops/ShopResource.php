<?php

namespace App\Filament\Resources\Shops;

use App\Filament\Resources\Shops\Pages\ManageShops;
use App\Filament\Resources\Shops\Schemas\ShopForm;
use App\Filament\Resources\Shops\Tables\ShopsTable;
use App\Models\Shop;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Multi-tenancy Step 25a: the studio's shop registry. Shop is the tenant *root*, not
 * a tenant-owned model, so this resource is NOT scoped to the current tenant — it
 * lists every shop. Studio founders only (canAccess): a shop owner never sees or
 * manages another shop.
 */
class ShopResource extends Resource
{
    protected static ?string $model = Shop::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Studio';

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
