<?php

namespace App\Filament\Resources\Brands\Schemas;

use App\Enums\BrandType;
use App\Support\TenantContext;
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
                    // scopedUnique, not unique: it validates through the
                    // tenant-scoped Brand query, so a duplicate in THIS shop is a
                    // form error (instead of a raw QueryException off the
                    // (shop_id, name) composite) while another shop's "Chanel"
                    // doesn't block this one.
                    ->scopedUnique(ignoreRecord: true)
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
                    ->disk(config('filesystems.media_disk'))
                    // shops/{id}/ prefix (step 32): per-shop archive/delete stays
                    // surgical. A closure, so the tenant is read at upload time.
                    ->directory(fn (): string => 'shops/'.app(TenantContext::class)->id().'/brands')
                    ->maxSize(2048),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
