<?php

namespace App\Filament\Studio\Resources\Shops\Pages;

use App\Enums\ShopStatus;
use App\Filament\Studio\Resources\Shops\ShopResource;
use App\Models\Shop;
use App\Support\ShopRegistration;
use App\Templates\Templates;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShops extends ManageRecords
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Registering a shop is one domain method (step 44a) — the shop, its
            // category, the optional owner login, its {slug}.cornerarea.me address
            // and its delivery geography. The self-serve sign-up calls the same
            // method, so the two can never drift.
            CreateAction::make()
                ->label('Register a shop')
                ->using(fn (array $data): Shop => ShopRegistration::register(
                    name: $data['name'],
                    slug: $data['slug'],
                    template: $data['template'] ?? Templates::DEFAULT,
                    status: $data['status'] instanceof ShopStatus ? $data['status'] : ShopStatus::from($data['status']),
                    owner: ($data['create_owner'] ?? false) ? [
                        'name' => $data['owner_name'],
                        'email' => $data['owner_email'],
                        'password' => $data['owner_password'],
                    ] : null,
                )),
        ];
    }
}
