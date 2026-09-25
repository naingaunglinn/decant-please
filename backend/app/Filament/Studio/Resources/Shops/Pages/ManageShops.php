<?php

namespace App\Filament\Studio\Resources\Shops\Pages;

use App\Filament\Studio\Resources\Shops\ShopResource;
use App\Models\Shop;
use App\Models\User;
use App\Support\NationalGeography;
use App\Templates\Templates;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ManageShops extends ManageRecords
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Registering a shop is three facts, not one (prompts/25 §25a):
            //   1. the shop row;
            //   2. its owner's login (optional) — attached via shop_user and
            //      carrying only the shop_owner role, so they fully control this
            //      shop and see nothing else. Never studio_admin: that role means
            //      "sees every shop".
            //   2b. its category (step 38) — shop_settings.template, written
            //      under the new shop's context;
            //   3. its delivery geography (after-hook) — all inactive at fee 0;
            //      activation and pricing are the owner's go-live steps.
            // Shop + owner commit together: a shop whose owner creation failed
            // half-way would be a login that guards nothing.
            CreateAction::make()
                ->label('Register a shop')
                ->using(function (array $data): Shop {
                    return DB::transaction(function () use ($data): Shop {
                        $shop = Shop::create(Arr::only($data, ['name', 'slug', 'status']));
                        Templates::assignToShop($shop, $data['template'] ?? Templates::DEFAULT);

                        if ($data['create_owner'] ?? false) {
                            $owner = User::create([
                                'name' => $data['owner_name'],
                                'email' => $data['owner_email'],
                                'password' => $data['owner_password'], // hashed cast
                            ]);
                            $owner->shops()->attach($shop);
                            // Step 34: the owner login carries the shop_owner
                            // capability role (Shield). WHICH shop stays the
                            // membership above + canAccessTenant/BelongsToShop.
                            $owner->assignRole('shop_owner');
                        }

                        return $shop;
                    });
                })
                ->after(fn (Shop $record) => NationalGeography::seed($record)),
        ];
    }
}
