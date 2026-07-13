<?php

namespace App\Filament\Resources\Fragrances\Pages;

use App\Filament\Resources\Fragrances\FragranceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFragrances extends ListRecords
{
    protected static string $resource = FragranceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
