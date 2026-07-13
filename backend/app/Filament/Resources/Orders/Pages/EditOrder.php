<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OrderResource::acceptAction(),
            OrderResource::rejectAction(),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->record->recalculateTotal();
    }
}
