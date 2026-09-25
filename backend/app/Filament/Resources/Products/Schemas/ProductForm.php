<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\BrandType;
use App\Models\Brand;
use App\Models\Product;
use App\Support\Modules;
use App\Support\StockUnit;
use App\Support\TenantContext;
use App\Templates\Attribute;
use App\Templates\Template;
use App\Templates\Templates;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
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

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        // The product's own template when editing, the shop's default when creating.
        $record = $schema->getRecord();
        $template = $record instanceof Product ? $record->catalogTemplate() : Templates::forShop();
        // The running-amount stock and cost screens are for pooled stock; a
        // per-variant template counts and costs each variant instead (step 40).
        // Decant keeps its bottle words; a weighed product buys by the sack (40b).
        $pooled = $template->pooledStock();
        $unit = (string) $template->measure();
        $bottle = $unit === StockUnit::ML;
        // A shop with the module off sees no stock or cost field (step 41). Left
        // out, not hidden, like the other mode's section: the stored figures stay.
        $stock = Modules::on(Modules::STOCK);

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
                            ->required($template->brandRequired())
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    // Tenant-scoped: the raw ->unique('brands','name')
                                    // checked the whole table, so it blocked any name
                                    // another shop's catalog already carries.
                                    ->scopedUnique(Brand::class),
                                Select::make('type')
                                    ->options(BrandType::class)
                                    ->default(BrandType::Designer->value)
                                    ->visible($template->brandTypes())
                                    ->required($template->brandTypes()),
                            ]),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Slug is generated automatically from the brand and name.'),
                        FileUpload::make('image_path')
                            ->label('Image')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['1:1'])
                            ->disk(config('filesystems.media_disk'))
                            // shops/{id}/ prefix (step 32) — see BrandForm
                            ->directory(fn (): string => 'shops/'.app(TenantContext::class)->id().'/fragrances')
                            ->maxSize(2048)
                            ->helperText('Square (1:1) images look best on the cards.'),
                    ]),
                Section::make('Details')
                    ->schema([
                        ...self::attributeFields($template),
                        Textarea::make('description')
                            ->rows(3),
                        Toggle::make('is_active')
                            ->default(true),
                        Toggle::make('is_featured'),
                    ]),
                // One stock section per mode: both bind low_stock_threshold, so the
                // other mode's is left out, not hidden (a hidden field still fills).
                ...($stock && $pooled ? [
                    Section::make('Stock')
                        ->description($bottle
                            ? 'Track how much you have left, in millilitres — the total across every bottle of this one. Leave blank to not track it: the manual in-stock toggles below still apply.'
                            : "Track how much you have left, in {$unit} — everything you have of this one. Leave blank to not track it: the manual in-stock toggles below still apply.")
                        ->columnSpanFull()
                        ->columns(2)
                        ->schema([
                            TextInput::make('stock_amount')
                                ->label('Remaining')
                                ->numeric()
                                ->minValue(0)
                                ->suffix($unit)
                                ->helperText(trim('Blank = not tracked. Drops on its own each time an order is '.mb_strtolower($template->preparedLabel()).'. '.StockUnit::help($unit)))
                                ->hintAction(
                                    Action::make('addBottle')
                                        ->label($bottle ? 'Add bottle' : 'Add stock')
                                        ->icon(Heroicon::OutlinedPlusCircle)
                                        ->schema([
                                            TextInput::make('ml')
                                                ->label($bottle ? 'Bottle size' : 'Amount')
                                                ->numeric()
                                                ->minValue(1)
                                                ->default($bottle ? 100 : null)
                                                ->suffix($unit)
                                                ->datalist($bottle ? [30, 50, 75, 100, 125, 200] : [])
                                                ->helperText(StockUnit::help($unit))
                                                ->required(),
                                        ])
                                        ->action(fn (array $data, Get $get, Set $set): mixed => $set(
                                            'stock_amount',
                                            (int) $get('stock_amount') + (int) $data['ml'],
                                        )),
                                ),
                            TextInput::make('low_stock_threshold')
                                ->label('Reorder at')
                                ->numeric()
                                ->minValue(0)
                                ->default(30)
                                ->suffix($unit)
                                ->helperText('Flag it on the dashboard once the remaining '.($bottle ? 'volume' : 'amount').' falls to this.'),
                        ]),
                ] : []),
                ...($stock && ! $pooled ? [
                    Section::make('Stock')
                        ->description('Count each option in its row below ("In stock"). Leave a count blank to not track it: the in-stock toggles still apply.')
                        ->columnSpanFull()
                        ->columns(2)
                        ->schema([
                            TextInput::make('low_stock_threshold')
                                ->label('Reorder at')
                                ->numeric()
                                ->minValue(0)
                                ->default(2)
                                ->suffix('pcs')
                                ->helperText('Flag an option on the dashboard once its count falls to this.'),
                        ]),
                ] : []),
                // Stock off: the reorder line still gets its mode's default on
                // create (the column's 30 would flag every size once counting starts),
                // and an edit writes the stored value back unchanged.
                ...(! $stock ? [Hidden::make('low_stock_threshold')->default($pooled ? 30 : 2)] : []),
                Section::make('Cost')
                    ->visible($pooled && Modules::on(Modules::COST_MARGIN))
                    ->description($bottle
                        ? 'What you pay for the juice — one bottle\'s price and its size. Liquid only: vials, labels and spillage aren\'t in this number. Leave both blank to not track cost; margin shows only for orders whose lines all have one.'
                        : 'What you pay for the goods — one purchase\'s price and how much it was. Goods only: bags, labels and waste aren\'t in this number. Leave both blank to not track cost; margin shows only for orders whose lines all have one.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('reference_cost_mmk')
                            ->label($bottle ? 'Bottle cost' : 'Purchase cost')
                            ->mask(RawJs::make('$money($input, \'.\', \',\', 0)'))
                            ->stripCharacters(',')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('Ks')
                            ->requiredWith('reference_amount')
                            ->helperText('Update by hand when you rebuy at a new price — past orders keep the cost they were created with.'),
                        TextInput::make('reference_amount')
                            ->label($bottle ? 'Bottle size' : 'Purchase amount')
                            ->numeric()
                            ->minValue(1)
                            ->suffix($unit)
                            ->datalist($bottle ? [30, 50, 75, 100, 125, 200] : [])
                            ->helperText(StockUnit::help($unit))
                            ->requiredWith('reference_cost_mmk'),
                    ]),
                Section::make($template->variantsHeading())
                    ->columnSpanFull()
                    ->schema([self::variantsRepeater($template)]),
            ]);
    }

    /**
     * A product's variants (step 38): ml sizes for decant, exactly as before; a
     * weight per variant for a weighed template (step 40b); for a template with
     * no measure, one text field per variant option (Size, Color), an optional
     * photo per variant, and drag-to-reorder into `position`.
     */
    public static function variantsRepeater(Template $template): Repeater
    {
        $measured = $template->measure() === StockUnit::ML;

        // Saved variants are archived, never removed: a variant on a placed order
        // can't be deleted (order_items restricts it), so only a row that isn't
        // saved yet ("record-{id}" keys are saved rows) gets a delete button —
        // "Selling" off hides a variant from the shop.
        $repeater = Repeater::make('variants')
            ->relationship()
            ->hiddenLabel()
            ->columns(4)
            ->minItems(1)
            ->deleteAction(fn (Action $action): Action => $action
                ->visible(fn (array $arguments, Repeater $component): bool => $component->isDeletable()
                    && ! str_starts_with((string) ($arguments['item'] ?? ''), 'record-')));

        $price = TextInput::make('price_mmk')
            ->label('Price')
            ->mask(RawJs::make('$money($input, \'.\', \',\', 0)'))
            ->stripCharacters(',')
            ->numeric()
            ->minValue(1)
            ->suffix('Ks')
            ->required();

        if ($measured) {
            return $repeater
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
                    $price,
                    Toggle::make('in_stock')
                        ->default(true)
                        ->inline(false),
                    Toggle::make('is_active')
                        ->label('Selling')
                        ->helperText('Off hides this size from the shop. Past orders keep it.')
                        ->default(true)
                        ->inline(false),
                ]);
        }

        // Per-variant stock and cost (step 40): pieces, and what one costs you.
        $counted = [
            TextInput::make('stock_qty')
                ->label('In stock')
                ->numeric()
                ->minValue(0)
                ->suffix('pcs')
                ->helperText('Blank = not counted. Drops on its own when an order is '.mb_strtolower($template->preparedLabel()).'.')
                ->visible(! $template->pooledStock() && Modules::on(Modules::STOCK)),
            TextInput::make('unit_cost_mmk')
                ->label('Cost')
                ->mask(RawJs::make('$money($input, \'.\', \',\', 0)'))
                ->stripCharacters(',')
                ->numeric()
                ->minValue(0)
                ->suffix('Ks')
                ->helperText('What one costs you. Blank = unknown; margin shows only when every line has a cost.')
                ->visible(! $template->pooledStock() && Modules::on(Modules::COST_MARGIN)),
        ];

        if ($template->measure() !== null) {
            // A weighed variant is its amount, whole kyatthar; it labels itself
            // ("1 viss") from that (ProductVariant::booted).
            return $repeater
                ->addActionLabel('Add '.mb_strtolower($template->variantOptions()[0]))
                ->defaultItems(1)
                ->schema([
                    TextInput::make('measure')
                        ->label($template->variantOptions()[0])
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->suffix($template->measure())
                        ->helperText(StockUnit::help($template->measure()))
                        ->required()
                        ->distinct()
                        ->validationMessages(['distinct' => 'Each amount can only appear once.']),
                    $price,
                    Toggle::make('in_stock')
                        ->default(true)
                        ->inline(false),
                    Toggle::make('is_active')
                        ->label('Selling')
                        ->helperText('Off hides this amount from the shop. Past orders keep it.')
                        ->default(true)
                        ->inline(false),
                    ...$counted,
                ]);
        }

        $options = $template->variantOptions();

        return $repeater
            ->addActionLabel('Add '.mb_strtolower(implode(' + ', $options)))
            ->defaultItems(1)
            // S, M, L, XL isn't alphabetical: the seller drags them into order.
            ->orderColumn('position')
            ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($options): void {
                $seen = [];

                foreach ((array) $value as $item) {
                    $combination = mb_strtolower(implode('|', array_map(
                        fn (string $option): string => trim((string) ($item['options'][$option] ?? '')),
                        $options,
                    )));

                    if (isset($seen[$combination])) {
                        $fail('Each '.mb_strtolower(implode(' and ', $options)).' can only appear once.');

                        return;
                    }

                    $seen[$combination] = true;
                }
            }])
            ->schema([
                ...array_map(fn (string $option): TextInput => TextInput::make("options.{$option}")
                    ->label($option)
                    ->required()
                    ->maxLength(50), $options),
                $price,
                Toggle::make('in_stock')
                    ->default(true)
                    ->inline(false),
                Toggle::make('is_active')
                    ->label('Selling')
                    ->helperText('Off hides this option from the shop. Past orders keep it.')
                    ->default(true)
                    ->inline(false),
                ...$counted,
                FileUpload::make('image_path')
                    ->label('Photo')
                    ->image()
                    ->disk(config('filesystems.media_disk'))
                    // shops/{id}/ prefix (step 32), like the product image
                    ->directory(fn (): string => 'shops/'.app(TenantContext::class)->id().'/variants')
                    ->maxSize(2048)
                    ->helperText('Optional — shown when a customer picks this colour.')
                    ->visible($template->variantPhotos())
                    ->columnSpanFull(),
            ]);
    }

    /**
     * One field per template attribute, bound to products.attributes.{key} (step 37).
     *
     * @return list<Field>
     */
    public static function attributeFields(Template $template): array
    {
        return array_map(function (Attribute $attribute): Field {
            $field = match (true) {
                $attribute->type === Attribute::SELECT => Select::make("attributes.{$attribute->key}")->options($attribute->options),
                $attribute->type === Attribute::NUMBER => TextInput::make("attributes.{$attribute->key}")->numeric(),
                $attribute->long => Textarea::make("attributes.{$attribute->key}")->rows($attribute->section ? 5 : 2),
                default => TextInput::make("attributes.{$attribute->key}"),
            };

            return $field
                ->label($attribute->label)
                ->required($attribute->required)
                ->helperText($attribute->help);
        }, $template->attributes());
    }
}
