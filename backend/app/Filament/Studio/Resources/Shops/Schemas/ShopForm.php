<?php

namespace App\Filament\Studio\Resources\Shops\Schemas;

use App\Enums\ShopStatus;
use App\Models\Shop;
use App\Support\ShopRegistration;
use App\Templates\Templates;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ShopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            // The slug rules live with registration (step 44a) — a DNS label, never a
            // reserved platform name — because the slug is now a public hostname too.
            TextInput::make('slug')
                ->required()
                ->maxLength(40)
                ->rules(fn (?Shop $record): array => ShopRegistration::slugRules($record))
                ->helperText('Lowercase letters, numbers and single dashes, 3–40 characters — the shop\'s admin + API path segment (/admin/{slug}, /api/v1/{slug}) and its automatic storefront address {slug}.{platform domain}. Renaming it later does not move that address (edit Domains by hand), and it\'s baked into admin links and shared API URLs — so avoid changing it once live.'),
            // The shop's category (step 38): what its products carry and how its
            // admin reads. Picked once, at registration — changing it later is
            // not built (its products would keep the old template's attributes).
            Select::make('template')
                ->label('Category')
                ->options(Templates::options())
                ->default(Templates::DEFAULT)
                ->required()
                ->visibleOn('create'),
            Select::make('status')
                ->options(ShopStatus::class)
                ->default(ShopStatus::Onboarding)
                ->required()
                ->helperText('Only a Live shop is served; Onboarding/Suspended/Archived all 404 on the storefront and API. New shops start Onboarding — activate once the catalog and payment settings are ready. (Suspend-with-reason lands in the registry, PR-3.)'),
            // The owner's login — a shop-level admin, never a studio account: they
            // are attached to this one shop via shop_user and get full control of
            // it (canAccessTenant), while /studio and every other shop stay out of
            // reach. Password is hand-set here (no mail driver — design-doc §11);
            // tell the owner to change it, there is no invite or reset flow yet.
            Section::make('Owner login')
                ->description('The shop owner\'s account for /admin — full control of this shop, and only this shop. Skip it while the studio operates the shop itself.')
                ->visibleOn('create')
                ->schema([
                    Toggle::make('create_owner')
                        ->label('Create the owner\'s login now')
                        ->default(true)
                        ->live(),
                    TextInput::make('owner_name')
                        ->label('Owner name')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('create_owner'))
                        ->required(fn (Get $get): bool => (bool) $get('create_owner')),
                    TextInput::make('owner_email')
                        ->label('Owner email')
                        ->email()
                        ->maxLength(255)
                        // users are global (not tenant-owned), so a plain unique
                        // across the whole table is the correct rule here.
                        ->unique('users', 'email')
                        ->visible(fn (Get $get): bool => (bool) $get('create_owner'))
                        ->required(fn (Get $get): bool => (bool) $get('create_owner')),
                    TextInput::make('owner_password')
                        ->label('Owner password')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->visible(fn (Get $get): bool => (bool) $get('create_owner'))
                        ->required(fn (Get $get): bool => (bool) $get('create_owner')),
                ]),
        ]);
    }
}
