<?php

namespace App\Filament\Studio\Resources\Shops\Schemas;

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
            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->rules(['alpha_dash'])
                ->unique(ignoreRecord: true)
                ->helperText('Lowercase letters, numbers and dashes. It becomes the shop\'s admin + API URL (/admin/{slug}), and the storefront\'s NEXT_PUBLIC_SHOP_SLUG must match — so avoid changing it once live.'),
            Toggle::make('is_active')
                ->default(true)
                ->helperText('An inactive shop 404s on its storefront and API.'),
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
