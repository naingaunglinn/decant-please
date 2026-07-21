<?php

namespace App\Filament\Resources\Fragrances;

use App\Filament\Resources\Fragrances\Pages\CreateFragrance;
use App\Filament\Resources\Fragrances\Pages\EditFragrance;
use App\Filament\Resources\Fragrances\Pages\ListFragrances;
use App\Filament\Resources\Fragrances\Schemas\FragranceForm;
use App\Filament\Resources\Fragrances\Tables\FragrancesTable;
use App\Models\Fragrance;
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

class FragranceResource extends Resource
{
    protected static ?string $model = Fragrance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Fragrances referenced by order items are FK-protected (restrictOnDelete).
     * Catch the violation and steer the decanter to deactivation instead.
     */
    public static function safeDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->action(function (Fragrance $record, DeleteAction $action): void {
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
        return FragranceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FragrancesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\BottlesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFragrances::route('/'),
            'create' => CreateFragrance::route('/create'),
            'edit' => EditFragrance::route('/{record}/edit'),
        ];
    }
}
