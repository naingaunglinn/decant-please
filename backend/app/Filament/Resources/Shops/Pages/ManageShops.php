<?php

namespace App\Filament\Resources\Shops\Pages;

use App\Filament\Resources\Shops\ShopResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShops extends ManageRecords
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Register a shop'),
        ];
    }
}
