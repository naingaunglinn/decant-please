<?php

namespace App\Filament\Resources\Brands;

use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\Brands\Schemas\BrandForm;
use App\Filament\Resources\Brands\Tables\BrandsTable;
use App\Models\Brand;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use UnitEnum;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Deleting a brand cascades to its fragrances — which the DB blocks (FK restrict)
     * the moment any of them appears in an order. Fail friendly, suggest deactivating.
     */
    public static function safeDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(fn (Brand $record): string => "Deleting \"{$record->name}\" also deletes its {$record->fragrances()->count()} fragrance(s) and their decant prices. This cannot be undone.")
            ->action(function (Brand $record, DeleteAction $action): void {
                try {
                    $record->delete();
                } catch (QueryException) {
                    Notification::make()
                        ->danger()
                        ->title('This brand has order history')
                        ->body('One of its fragrances appears in orders, so it can\'t be deleted — deactivate the brand instead.')
                        ->send();

                    return;
                }

                $action->success();
            });
    }

    public static function form(Schema $schema): Schema
    {
        return BrandForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BrandsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBrands::route('/'),
            'create' => CreateBrand::route('/create'),
            'edit' => EditBrand::route('/{record}/edit'),
        ];
    }
}
