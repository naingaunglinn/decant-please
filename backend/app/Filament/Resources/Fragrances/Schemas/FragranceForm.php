<?php

namespace App\Filament\Resources\Fragrances\Schemas;

use App\Enums\BrandType;
use App\Enums\Concentration;
use App\Enums\Gender;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;

class FragranceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Identity')
                    ->schema([
                        Select::make('brand_id')
                            ->label('Brand')
                            ->relationship('brand', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique('brands', 'name'),
                                Select::make('type')
                                    ->options(BrandType::class)
                                    ->default(BrandType::Designer->value)
                                    ->required(),
                            ]),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Slug is generated automatically from the brand and name.'),
                        Select::make('concentration')
                            ->options(Concentration::class)
                            ->required(),
                        Select::make('gender')
                            ->options(Gender::class)
                            ->required(),
                        FileUpload::make('image_path')
                            ->label('Image')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['1:1'])
                            ->disk(config('filesystems.media_disk'))
                            ->directory('fragrances')
                            ->maxSize(2048)
                            ->helperText('Square (1:1) images look best on the cards.'),
                    ]),
                Section::make('Scent profile')
                    ->schema([
                        Textarea::make('notes')
                            ->rows(2)
                            ->helperText('Comma separated — e.g. Citrus, Musk, Amber, Orange, Grapefruit'),
                        Textarea::make('vibes')
                            ->rows(2)
                            ->helperText('e.g. Modern, Clean, Alluring, Classy'),
                        TextInput::make('performance')
                            ->helperText('e.g. Around 6-8 Hours'),
                        Textarea::make('description')
                            ->rows(3),
                        Toggle::make('is_active')
                            ->default(true),
                        Toggle::make('is_featured'),
                    ]),
                Section::make('Stock')
                    ->description('Track how much you have left, in millilitres — the total across every bottle of this one. Leave blank to not track it: the manual in-stock toggles below still apply.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('stock_ml')
                            ->label('Remaining')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('ml')
                            ->helperText('Blank = not tracked. Drops on its own each time an order is decanted.')
                            ->hintAction(
                                Action::make('addBottle')
                                    ->label('Add bottle')
                                    ->icon(Heroicon::OutlinedPlusCircle)
                                    ->schema([
                                        TextInput::make('ml')
                                            ->label('Bottle size')
                                            ->numeric()
                                            ->minValue(1)
                                            ->default(100)
                                            ->suffix('ml')
                                            ->datalist([30, 50, 75, 100, 125, 200])
                                            ->required(),
                                    ])
                                    ->action(fn (array $data, Get $get, Set $set): mixed => $set(
                                        'stock_ml',
                                        (int) $get('stock_ml') + (int) $data['ml'],
                                    )),
                            ),
                        TextInput::make('low_stock_threshold_ml')
                            ->label('Reorder at')
                            ->numeric()
                            ->minValue(0)
                            ->default(30)
                            ->suffix('ml')
                            ->helperText('Flag it on the dashboard once the remaining volume falls to this.'),
                    ]),
                Section::make('Decant prices')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('decantPrices')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(3)
                            ->minItems(1)
                            ->addActionLabel('Add size')
                            ->default([
                                ['size_ml' => 5, 'in_stock' => true],
                                ['size_ml' => 10, 'in_stock' => true],
                                ['size_ml' => 30, 'in_stock' => true],
                            ])
                            ->schema([
                                TextInput::make('size_ml')
                                    ->label('Size')
                                    ->numeric()
                                    ->minValue(1)
                                    ->suffix('ml')
                                    ->datalist([5, 10, 30])
                                    ->required()
                                    ->distinct()
                                    ->validationMessages(['distinct' => 'Each size can only appear once.']),
                                TextInput::make('price_mmk')
                                    ->label('Price')
                                    ->mask(RawJs::make('$money($input, \'.\', \',\', 0)'))
                                    ->stripCharacters(',')
                                    ->numeric()
                                    ->minValue(1)
                                    ->suffix('Ks')
                                    ->required(),
                                Toggle::make('in_stock')
                                    ->default(true)
                                    ->inline(false),
                            ]),
                    ]),
            ]);
    }
}
