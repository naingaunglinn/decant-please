<?php

namespace App\Filament\Resources\Brands\Schemas;

use App\Enums\BrandType;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                TextInput::make('slug')
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder('auto-generated')
                    ->helperText('Generated from the name — no need to type it.'),
                Select::make('type')
                    ->options(BrandType::class)
                    ->default(BrandType::Designer->value)
                    ->required(),
                FileUpload::make('logo_path')
                    ->label('Logo')
                    ->image()
                    ->imageEditor()
                    ->disk('public')
                    ->directory('brands')
                    ->maxSize(2048),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
