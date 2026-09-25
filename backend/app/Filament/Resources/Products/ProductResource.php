<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $recordTitleAttribute = 'name';

    // The decant shop keeps seeing "Fragrances" (P3: nothing new for it). Step 37
    // takes the label from the shop's template.
    protected static ?string $modelLabel = 'fragrance';

    /**
     * Products referenced by order items are FK-protected (restrictOnDelete).
     * Catch the violation and steer the decanter to deactivation instead.
     */
    public static function safeDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->action(function (Product $record, DeleteAction $action): void {
                try {
                    $record->delete();
                } catch (QueryException) {
                    Notification::make()
                        ->danger()
                        ->title('This fragrance appears in orders')
                        ->body('Order history references it, so it can\'t be deleted — deactivate it instead.')
                        ->send();

                    return;
                }

                $action->success();
            });
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'brand.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Brand' => $record->brand->name];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('brand');
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
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
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
