<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\DecantPrice;
use App\Models\Fragrance;
use App\Models\Order;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Customer')
                    ->schema([
                        TextInput::make('customer_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->required()
                            ->maxLength(255),
                        Textarea::make('address')
                            ->rows(2)
                            ->required(),
                        Select::make('order_from')
                            ->options(OrderSource::class)
                            ->default(OrderSource::Tiktok->value)
                            ->required(),
                    ]),
                Section::make('Schedule')
                    ->schema([
                        Select::make('status')
                            ->options(OrderStatus::class)
                            ->default(OrderStatus::Pending->value)
                            ->required()
                            ->live(),
                        DatePicker::make('decant_date')
                            ->required()
                            ->visible(fn (Get $get): bool => $get('status') !== OrderStatus::AwaitingConfirmation->value)
                            ->helperText('The day you physically decant this order.'),
                        DatePicker::make('delivery_date')
                            ->afterOrEqual('decant_date')
                            ->visible(fn (Get $get): bool => $get('status') !== OrderStatus::AwaitingConfirmation->value),
                        Placeholder::make('awaiting_hint')
                            ->hiddenLabel()
                            ->content('Dates are set by the Accept action while an order awaits confirmation.')
                            ->visible(fn (Get $get): bool => $get('status') === OrderStatus::AwaitingConfirmation->value),
                        Textarea::make('rejection_reason')
                            ->rows(2)
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => $get('status') === OrderStatus::Rejected->value),
                        TextInput::make('tracking_code')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?Order $record): bool => $record !== null)
                            ->helperText('Customers use this (plus their phone) to track the order.'),
                    ]),
                Section::make('Items')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(4)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Add item')
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::withSnapshot($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::withSnapshot($data))
                            ->schema([
                                Select::make('fragrance_id')
                                    ->label('Fragrance')
                                    ->options(fn (): array => Fragrance::query()
                                        ->with('brand')
                                        ->get()
                                        ->mapWithKeys(fn (Fragrance $fragrance) => [
                                            $fragrance->id => "{$fragrance->brand->name} — {$fragrance->name}",
                                        ])
                                        ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                                        ->all())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::autofillUnitPrice($get, $set)),
                                TextInput::make('size_ml')
                                    ->label('Size')
                                    ->numeric()
                                    ->minValue(1)
                                    ->suffix('ml')
                                    ->required()
                                    ->datalist(fn (Get $get): array => DecantPrice::query()
                                        ->where('fragrance_id', $get('fragrance_id'))
                                        ->orderBy('size_ml')
                                        ->pluck('size_ml')
                                        ->all())
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::autofillUnitPrice($get, $set)),
                                TextInput::make('unit_price_mmk')
                                    ->label('Unit price')
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix('Ks')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->helperText('Auto-filled from the catalog — edit freely.'),
                                TextInput::make('quantity')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true),
                            ]),
                    ]),
                Section::make('Financials')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextInput::make('delivery_fee_mmk')
                            ->label('Delivery fee')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->suffix('Ks')
                            ->live(onBlur: true),
                        TextInput::make('discount_mmk')
                            ->label('Discount')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->suffix('Ks')
                            ->live(onBlur: true),
                        TextInput::make('deposit_mmk')
                            ->label('Deposit paid')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->suffix('Ks')
                            ->live(onBlur: true),
                        Placeholder::make('total')
                            ->content(fn (Get $get): string => Money::kyat(self::liveTotal($get))),
                        Placeholder::make('balance_due')
                            ->content(fn (Get $get): string => Money::kyat(
                                max(0, self::liveTotal($get) - (int) ($get('deposit_mmk') ?: 0))
                            )),
                        Textarea::make('notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * The stored total is recalculated server-side on save; this is just the live preview.
     */
    protected static function liveTotal(Get $get): int
    {
        $items = collect($get('items') ?? [])
            ->sum(fn (array $item): int => (int) ($item['unit_price_mmk'] ?: 0) * (int) ($item['quantity'] ?: 0));

        return max(0, $items + (int) ($get('delivery_fee_mmk') ?: 0) - (int) ($get('discount_mmk') ?: 0));
    }

    protected static function autofillUnitPrice(Get $get, Set $set): void
    {
        $price = DecantPrice::query()
            ->where('fragrance_id', $get('fragrance_id'))
            ->where('size_ml', $get('size_ml'))
            ->value('price_mmk');

        if ($price !== null) {
            $set('unit_price_mmk', $price);
        }
    }

    /**
     * Manual admin entries bypass Order::newFromCheckout(), so the snapshot is taken here.
     */
    protected static function withSnapshot(array $data): array
    {
        $fragrance = Fragrance::with('brand')->find($data['fragrance_id'] ?? null);

        if ($fragrance) {
            $data['fragrance_name_snapshot'] = "{$fragrance->brand->name} {$fragrance->name}";
        }

        return $data;
    }
}
