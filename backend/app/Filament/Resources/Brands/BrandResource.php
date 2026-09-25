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
use UnitEnum;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * A brand with products is FK-protected (step 36: products.brand_id is
     * NO ACTION on delete). Check first and steer the seller to deactivation — a
     * caught violation would abort any surrounding Postgres transaction.
     */
    public static function safeDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->action(function (Brand $record, DeleteAction $action): void {
                if ($record->products()->exists()) {
                    Notification::make()
                        ->danger()
                        ->title('This brand has fragrances')
                        ->body('Its fragrances would lose their brand, so it can\'t be deleted — deactivate it instead.')
                        ->send();

                    return;
                }

                $record->delete();

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
