<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Region;
use App\Models\DecantPrice;
use App\Models\DeliveryTownship;
use App\Models\Fragrance;
use App\Models\Order;
use App\Support\Money;
use App\Support\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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
                            ->required()
                            ->helperText('Checkout composes this from the structured fields; for a DM order it\'s whatever the customer typed.'),
                        Select::make('delivery_region')
                            ->label('Region')
                            ->options(Region::class)
                            // pure UI — narrows the township list, never stored
                            ->dehydrated(false)
                            ->live()
                            ->afterStateHydrated(function (Select $component, ?Order $record): void {
                                if ($record?->deliveryTownship) {
                                    $component->state($record->deliveryTownship->region->value);
                                }
                            }),
                        Select::make('delivery_township_id')
                            ->label('Township')
                            ->options(fn (Get $get): array => self::townshipOptions($get('delivery_region')))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state && ($fee = DeliveryTownship::query()->find($state)?->fee_mmk) !== null) {
                                    $set('delivery_fee_mmk', $fee);
                                }
                            })
                            // optional, deliberately: a DM order whose township is
                            // ambiguous must still be saveable with a plain address
                            ->helperText('Optional — picking one pre-fills the delivery fee in Financials, editable there like the discount.'),
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
                        Select::make('delivery_courier')
                            ->label('Courier')
                            ->options(fn (Get $get): array => DeliveryTownship::courierOptionsFor(
                                $get('delivery_township_id') ? (int) $get('delivery_township_id') : null,
                            ))
                            ->placeholder('Not decided')
                            ->visible(fn (Get $get): bool => $get('status') !== OrderStatus::AwaitingConfirmation->value)
                            ->helperText('Who carries this parcel — each option shows its recorded cost. Also settable on Accept.'),
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
                            ->columns(5)
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
                                TextInput::make('unit_cost_mmk')
                                    ->label('Unit cost')
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix('Ks')
                                    ->live(onBlur: true)
                                    ->helperText('Liquid only — auto-filled from the fragrance cost; edit freely. Blank = unknown.'),
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
                            ->live(onBlur: true)
                            // promo_code is a snapshot of where the initial discount came
                            // from — editing the amount here deliberately leaves it alone
                            ->helperText(fn ($record): ?string => $record?->promo_code
                                ? "Set by promo code {$record->promo_code} at checkout — adjust freely."
                                : null),
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
                        Placeholder::make('gross_margin')
                            ->label('Gross margin (liquid only)')
                            // Saved-state figure, from the stored snapshots — unsaved
                            // line edits show after save. Admin-eyes only.
                            ->content(function (?Order $record): string {
                                if (! $record) {
                                    return '—';
                                }

                                $record->loadMissing('items');
                                $margin = $record->liquidGrossMarginMmk();

                                if ($margin === null) {
                                    $uncosted = $record->items
                                        ->filter(fn ($item): bool => $item->line_cost_mmk === null)
                                        ->count();

                                    return $uncosted > 0 ? "unknown — {$uncosted} line(s) uncosted" : '—';
                                }

                                return Money::kyat($margin).' — excludes vial, label, spillage & delivery';
                            }),
                        Textarea::make('notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                self::paymentSection(),
            ]);
    }

    protected static function paymentSection(): Section
    {
        return Section::make('Payment')
            ->description('Payment is offline — KBZPay/Wave/bank transfer. Confirm here once it lands; this is separate from the deposit figure above.')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                Select::make('payment_method')
                    ->options(PaymentMethod::class)
                    ->default(PaymentMethod::Cod->value)
                    ->required()
                    ->helperText('How the customer is paying. Manual/DM orders default to cash on delivery.'),
                Select::make('payment_status')
                    ->options(PaymentStatus::class)
                    ->default(PaymentStatus::Unpaid->value)
                    ->required()
                    ->helperText('Setting this to Paid stamps the confirmation time automatically.'),
                FileUpload::make('payment_proof_path')
                    ->label('Payment proof')
                    ->image()
                    ->disk(config('filesystems.proofs_disk'))
                    // shops/{id}/ prefix (step 32) — see BrandForm
                    ->directory(fn (): string => 'shops/'.app(TenantContext::class)->id().'/payment-proofs')
                    ->visibility('private')
                    ->maxSize(4096)
                    // The proofs disk has no public URL, and Filament's default
                    // preview would mint a presigned temporary URL for a private
                    // s3 disk — never that (see #47). The preview instead loads
                    // through the panel's authenticated streaming route: same
                    // origin, session-auth'd, the only way a proof is served.
                    ->getUploadedFileUsing(static function (FileUpload $component, string $file): ?array {
                        $record = $component->getRecord();

                        if (! $record instanceof Order) {
                            return null;
                        }

                        return [
                            'name' => basename($file),
                            'size' => 0,
                            'type' => null,
                            'url' => route('filament.admin.orders.payment-proof', ['order' => $record]),
                        ];
                    })
                    ->hintAction(
                        Action::make('viewProof')
                            ->label('Open full size')
                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                            ->url(fn (?Order $record): ?string => $record?->payment_proof_path
                                ? route('filament.admin.orders.payment-proof', ['order' => $record])
                                : null)
                            ->openUrlInNewTab()
                            ->visible(fn (?Order $record): bool => $record?->payment_proof_path !== null),
                    )
                    ->helperText("The customer's transfer screenshot — sent by them at checkout, or attach one they DMed you."),
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

        // Cost mirrors price: pre-filled from the live reference, hand-correctable.
        // Only a real value overwrites — an uncosted fragrance keeps whatever's typed.
        $cost = Fragrance::query()->find($get('fragrance_id'))
            ?->liquidCostMmk((int) $get('size_ml'));

        if ($cost !== null) {
            $set('unit_cost_mmk', $cost);
        }
    }

    /**
     * Every township (active or not — the admin can point an order anywhere),
     * region-filtered when one is picked, suffixed with the region when not,
     * so two same-named townships stay tellable apart.
     */
    protected static function townshipOptions(?string $region): array
    {
        return DeliveryTownship::query()
            ->when($region, fn ($query, string $value) => $query->where('region', $value))
            ->orderBy('region')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (DeliveryTownship $township): array => [
                $township->id => $region
                    ? $township->optionLabel()
                    : "{$township->optionLabel()} — {$township->region->label()}",
            ])
            ->all();
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
