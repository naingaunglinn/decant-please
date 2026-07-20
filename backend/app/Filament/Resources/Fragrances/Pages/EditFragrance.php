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

    /**
     * A size added or changed while a bottle is being tracked starts from the
     * form/DB default, not the computed value (the in_stock toggle is disabled,
     * so nothing user-entered is lost here) — recompute from the active bottle
     * so it's right immediately, not only after the next pour.
     */
    protected function afterSave(): void
    {
        $this->getRecord()->syncStockFromBottle();
    }
}
