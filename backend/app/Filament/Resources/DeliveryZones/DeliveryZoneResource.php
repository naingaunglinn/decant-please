<?php

namespace App\Filament\Resources\DeliveryZones;

use App\Filament\Resources\DeliveryZones\Pages\ManageDeliveryZones;
use App\Filament\Resources\DeliveryZones\Schemas\DeliveryZoneForm;
use App\Filament\Resources\DeliveryZones\Tables\DeliveryZonesTable;
use App\Models\DeliveryTownship;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The rate table behind checkout's delivery fee. Settings, not a top-level
 * group: one resource doesn't earn a menu beside Catalog/Sales/Finance, and
 * a rate table is configuration.
 */
class DeliveryZoneResource extends Resource
{
    protected static ?string $model = DeliveryTownship::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'delivery zone';

    protected static ?string $pluralModelLabel = 'delivery zones';

    public static function form(Schema $schema): Schema
    {
        return DeliveryZoneForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryZonesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDeliveryZones::route('/'),
        ];
    }
}
