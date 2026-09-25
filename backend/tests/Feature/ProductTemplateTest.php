<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ShopSetting;
use App\Templates\Attribute;
use App\Templates\DecantTemplate;
use App\Templates\Template;
use App\Templates\Templates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 37: code-defined templates, attributes in products.attributes, search over
 * products.search_text, the shop's default template, and per-shop categories.
 */
class ProductTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Templates::forget('test-bags');
        Templates::forget('test-cake');

        parent::tearDown();
    }

    private function allure(array $attributes = []): Product
    {
        $brand = Brand::firstOrCreate(['name' => 'Chanel'], ['type' => 'designer']);

        $product = $brand->products()->create([
            'name' => 'Allure Homme Sport',
            'attributes' => [
                'concentration' => 'cologne', 'gender' => 'male', 'notes' => 'Orange, Sea Notes, Musk',
                'vibes' => 'Fresh, Sporty', 'performance' => 'Around 4-6 Hours', ...$attributes,
            ],
        ]);
        $product->variants()->create(['size_ml' => 10, 'price_mmk' => 55000]);

        return $product;
    }

    private function search(string $query): array
    {
        return collect($this->getJson("/api/v1/decant-please/products?{$query}")->assertOk()->json('data'))
            ->pluck('name')->all();
    }

    public function test_attributes_round_trip_through_the_api_in_template_order_with_labels(): void
    {
        $this->allure(['vibes' => null]);

        $this->getJson('/api/v1/decant-please/products/chanel-allure-homme-sport')
            ->assertOk()
            ->assertJsonPath('data.template', 'decant')
            ->assertJsonPath('data.attributes', [
                ['key' => 'concentration', 'label' => 'Concentration', 'value' => 'cologne', 'display' => 'Cologne'],
                ['key' => 'gender', 'label' => 'Gender', 'value' => 'male', 'display' => 'Male'],
                ['key' => 'notes', 'label' => 'Scent notes', 'value' => 'Orange, Sea Notes, Musk', 'display' => 'Orange, Sea Notes, Musk'],
                ['key' => 'performance', 'label' => 'Performance', 'value' => 'Around 4-6 Hours', 'display' => 'Around 4-6 Hours'],
            ])
            // the pre-37 flat keys still answer, from attributes
            ->assertJsonPath('data.concentration_label', 'Cologne')
            ->assertJsonPath('data.vibes', null);
    }

    public function test_search_runs_on_search_text_case_insensitively_with_literal_wildcards(): void
    {
        $product = $this->allure();
        $this->assertSame('chanel allure homme sport orange, sea notes, musk', $product->search_text);

        $this->assertSame(['Allure Homme Sport'], $this->search('q=CHANEL'));
        $this->assertSame(['Allure Homme Sport'], $this->search('q=sea%20notes'));
        $this->assertSame(['Allure Homme Sport'], $this->search('notes=MUSK'));
        $this->assertSame([], $this->search('q=%25'));   // a literal %, not "anything"
        $this->assertSame([], $this->search('q=a_lure'));  // a literal _, not "any one character"
        // vibes aren't searchable in the decant template
        $this->assertSame([], $this->search('q=sporty'));
    }

    public function test_editing_an_attribute_or_the_name_rebuilds_search_text(): void
    {
        $product = $this->allure();

        $product->update(['attributes' => [...$product->attributes, 'notes' => 'Vetiver']]);
        $this->assertSame(['Allure Homme Sport'], $this->search('q=vetiver'));
        $this->assertSame([], $this->search('q=musk'));

        $product->update(['name' => 'Allure Édition Blanche']);
        $this->assertSame(['Allure Édition Blanche'], $this->search('q=%C3%A9dition')); // "édition"
    }

    public function test_renaming_a_brand_rebuilds_its_products_search_text(): void
    {
        $this->allure();
        $archived = Brand::where('name', 'Chanel')->first()->products()->create([
            'name' => 'Old Scent', 'attributes' => ['concentration' => 'edt', 'gender' => 'male'], 'is_active' => false,
        ]);

        Brand::where('name', 'Chanel')->first()->update(['name' => 'Maison Chanel']);

        $this->assertSame(['Allure Homme Sport'], $this->search('q=maison'));
        $this->assertStringStartsWith('maison chanel ', $archived->fresh()->search_text);

        Brand::where('name', 'Maison Chanel')->first()->update(['name' => 'Coco']);
        $this->assertSame([], $this->search('q=maison'));
        $this->assertSame(['Allure Homme Sport'], $this->search('q=coco'));
    }

    public function test_only_the_templates_filterable_attributes_filter(): void
    {
        $this->allure();
        $brand = Brand::firstOrCreate(['name' => 'Chanel'], ['type' => 'designer']);
        $brand->products()->create(['name' => 'Coco', 'attributes' => ['concentration' => 'edp', 'gender' => 'female']])
            ->variants()->create(['size_ml' => 10, 'price_mmk' => 60000]);

        $this->assertSame(['Coco'], $this->search('gender=female'));
        $this->getJson('/api/v1/decant-please/products?gender=robot')->assertUnprocessable();
        // concentration is not filterable for decant: the parameter is ignored
        $this->assertCount(2, $this->search('concentration=edp'));
    }

    public function test_meta_filters_come_from_the_template_and_the_old_lists_are_unchanged(): void
    {
        $meta = $this->getJson('/api/v1/decant-please/meta')->assertOk()->json();

        $this->assertSame([
            ['key' => 'gender', 'label' => 'Gender', 'type' => 'select', 'options' => [
                ['value' => 'male', 'label' => 'Male'],
                ['value' => 'female', 'label' => 'Female'],
                ['value' => 'unisex', 'label' => 'Unisex'],
            ]],
            ['key' => 'notes', 'label' => 'Scent notes', 'type' => 'text', 'options' => []],
        ], $meta['filters']);
        $this->assertSame($meta['filters'][0]['options'], $meta['genders']);
    }

    public function test_a_product_takes_the_shops_default_template_on_create(): void
    {
        $this->assertSame('decant', $this->allure()->template);

        Templates::register(TestBagsTemplate::class);
        ShopSetting::current()->update(['template' => 'test-bags']);

        $bag = Product::create(['name' => 'Tote', 'attributes' => ['material' => 'Canvas']]);
        $this->assertSame('test-bags', $bag->fresh()->template);
        $this->assertSame('tote canvas', $bag->search_text);

        // decant and bags are both group 1, so the shop may still sell a decant
        $this->assertSame('decant', Product::create(['name' => 'Mixed', 'template' => 'decant'])->fresh()->template);
    }

    public function test_a_template_outside_the_shops_group_is_refused(): void
    {
        Templates::register(TestCakeTemplate::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside this shop\'s group');

        Product::create(['name' => 'Birthday cake', 'template' => 'test-cake']);
    }

    public function test_an_unknown_template_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Product::create(['name' => 'Mystery', 'template' => 'nope']);
    }

    public function test_deleting_a_category_leaves_its_products_uncategorised(): void
    {
        $category = Category::create(['name' => 'Summer']);
        $product = $this->allure();
        $product->update(['category_id' => $category->id]);

        $category->delete();

        $this->assertNotNull($product->fresh());
        $this->assertNull($product->fresh()->category_id);
    }

    public function test_the_admin_form_is_built_from_the_template(): void
    {
        $this->actingAs($this->studioUser());
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        Livewire::test(CreateProduct::class)
            ->assertFormFieldExists('attributes.concentration')
            ->assertFormFieldExists('attributes.performance')
            ->fillForm([
                'brand_id' => $brand->id,
                'name' => 'Allure Homme Sport',
                'attributes' => ['concentration' => 'cologne', 'gender' => 'male', 'notes' => 'Musk'],
                'variants' => [['size_ml' => 10, 'price_mmk' => 55000, 'in_stock' => true]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('name', 'Allure Homme Sport')->firstOrFail();
        $this->assertSame('cologne', $product->attr('concentration'));
        $this->assertSame('Musk', $product->attr('notes'));
        $this->assertSame('decant', $product->template);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormSet(['attributes.gender' => 'male'])
            ->fillForm(['attributes.gender' => 'unisex'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('unisex', $product->fresh()->attr('gender'));

        // required attributes are required
        Livewire::test(CreateProduct::class)
            ->fillForm(['brand_id' => $brand->id, 'name' => 'Nameless', 'attributes' => ['gender' => 'male']])
            ->call('create')
            ->assertHasFormErrors(['attributes.concentration' => 'required']);
    }

    public function test_the_backfill_copies_the_five_columns_losslessly(): void
    {
        $migration = require database_path('migrations/2026_09_27_000000_add_templates_attributes_and_categories.php');

        $row = (object) ['concentration' => 'edp', 'gender' => 'female', 'notes' => 'ဂျက်စမင်, Rose', 'vibes' => null, 'performance' => '8h+'];

        $this->assertSame(
            ['concentration' => 'edp', 'gender' => 'female', 'notes' => 'ဂျက်စမင်, Rose', 'vibes' => null, 'performance' => '8h+'],
            $migration::attributesFrom($row),
        );
        $this->assertSame(
            'creed love in white ဂျက်စမင်, rose',
            (new DecantTemplate)->searchText('Creed', 'Love In White', $migration::attributesFrom($row)),
        );
    }
}

class TestBagsTemplate extends Template
{
    public function key(): string
    {
        return 'test-bags';
    }

    public function group(): int
    {
        return 1;
    }

    public function attributes(): array
    {
        return [Attribute::text('material', 'Material', searchable: true)];
    }

    public function variantOptions(): array
    {
        return ['Color'];
    }

    public function productNouns(): array
    {
        return ['bag', 'bags'];
    }

    public function statusLabels(): array
    {
        return [];
    }

    public function defaultModules(): array
    {
        return [];
    }
}

class TestCakeTemplate extends TestBagsTemplate
{
    public function key(): string
    {
        return 'test-cake';
    }

    public function group(): int
    {
        return 2;
    }
}
