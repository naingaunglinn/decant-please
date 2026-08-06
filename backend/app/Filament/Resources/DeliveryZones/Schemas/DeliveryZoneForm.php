<?php

namespace App\Filament\Resources\DeliveryZones\Schemas;

use App\Enums\Courier;
use App\Enums\Region;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;

class DeliveryZoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('region')
                    ->options(Region::class)
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Canonical English — the stable identity; each courier\'s own spelling lives on their route row below.'),
                TextInput::make('name_mm')
                    ->label('Burmese name')
                    ->maxLength(255)
                    ->helperText('Shows beside the English name in the checkout dropdown and prints on the parcel address. Leave blank rather than guess.'),
                TextInput::make('district')
                    ->maxLength(255)
                    ->helperText('Admin grouping and bulk pricing only — customers never pick a district.'),
                TextInput::make('fee_mmk')
                    ->label('Delivery fee')
                    ->mask(RawJs::make('$money($input, \'.\', \',\', 0)'))
                    ->stripCharacters(',')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->suffix('Ks')
                    ->required()
                    ->helperText('What the customer is charged, in cash to the courier. 0 is real free delivery — a township you\'re not ready to sell stays inactive instead.'),
                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower shows first within its region at checkout.'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Offered at checkout — but only when at least one courier route below is open.'),
                Repeater::make('couriers')
                    ->label('Courier routes')
                    ->relationship()
                    ->columnSpanFull()
                    ->columns(4)
                    ->defaultItems(0)
                    ->addActionLabel('Add courier route')
                    ->helperText('Who can carry a parcel here, at what recorded cost. Reference only — a courier cost never enters the fee, a total, a margin, or the P&L, and none of this is ever shown to customers.')
                    ->schema([
                        Select::make('courier')
                            ->options(Courier::class)
                            ->required(),
                        TextInput::make('courier_name')
                            ->label("Courier's spelling")
                            ->required()
                            ->maxLength(255)
                            ->helperText('As it appears on their statements — the reconciliation key.'),
                        TextInput::make('cost_mmk')
                            ->label('Cost')
                            ->mask(RawJs::make('$money($input, \'.\', \',\', 0)'))
                            ->stripCharacters(',')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('Ks')
                            ->helperText('Blank = unknown, never 0.'),
                        Toggle::make('is_available')
                            ->label('Route open')
                            ->default(true)
                            ->inline(false)
                            ->helperText('Off = suspended: the route exists but is closed for now.'),
                    ]),
            ]);
    }
}
