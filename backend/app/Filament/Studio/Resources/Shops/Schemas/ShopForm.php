<?php

namespace App\Filament\Resources\Shops\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
        ]);
    }
}
