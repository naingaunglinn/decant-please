<?php

namespace App\Filament\Resources\Fragrances\Pages;

use App\Filament\Resources\Fragrances\FragranceResource;
use Filament\Resources\Pages\EditRecord;

class EditFragrance extends EditRecord
{
    protected static string $resource = FragranceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FragranceResource::safeDeleteAction(),
        ];
    }
}
