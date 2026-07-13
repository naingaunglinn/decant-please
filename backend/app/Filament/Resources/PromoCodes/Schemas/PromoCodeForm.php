<?php

namespace App\Filament\Resources\PromoCodes\Schemas;

use App\Enums\PromoType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;

class PromoCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Code')
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(64)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                            ->helperText('Stored uppercase; customers can type it in any case.'),
                        Select::make('type')
                            ->options(PromoType::class)
                            ->default(PromoType::Percent->value)
                            ->required()
                            ->live(),
                        TextInput::make('value')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(fn (Get $get): ?int => $get('type') === PromoType::Percent->value ? 100 : null)
                            ->suffix(fn (Get $get): string => $get('type') === PromoType::Percent->value ? '%' : 'Ks'),
                        TextInput::make('max_discount_mmk')
                            ->label('Max discount')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('Ks')
                            ->visible(fn (Get $get): bool => $get('type') === PromoType::Percent->value)
                            ->helperText('Caps what a percent code can pay out on a large cart.'),
                    ]),
                Section::make('Rules')
                    ->schema([
                        TextInput::make('min_order_mmk')
                            ->label('Minimum order')
                            ->mask(RawJs::make('$money($input, \'.\', \',\', 0)'))
                            ->stripCharacters(',')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('Ks'),
                        TextInput::make('usage_limit')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Leave empty for unlimited uses.'),
                        DatePicker::make('starts_at'),
                        DatePicker::make('expires_at')
                            ->afterOrEqual('starts_at'),
                        Toggle::make('is_active')
                            ->default(true),
                    ]),
            ]);
    }
}
