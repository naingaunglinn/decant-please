<?php

namespace Tests\Feature;

use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Brand;
use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->studioUser());
    }

    public function test_brand_list_renders_records(): void
    {
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        Livewire::test(ListBrands::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$brand]);
    }

    public function test_brand_can_be_created_with_logo_and_auto_slug(): void
    {
        Storage::fake('public');

        Livewire::test(CreateBrand::class)
            ->fillForm([
                'name' => 'Xerjoff',
                'type' => 'niche',
                'logo_path' => UploadedFile::fake()->image('logo.png'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $brand = Brand::where('name', 'Xerjoff')->firstOrFail();
        $this->assertSame('xerjoff', $brand->slug);
        $this->assertNotNull($brand->logo_path);
        // shops/{id}/ prefix since step 32 — uploads land under the current shop
        $this->assertStringStartsWith('shops/'.app(TenantContext::class)->id().'/brands/', $brand->logo_path);
        Storage::disk('public')->assertExists($brand->logo_path);
    }

    public function test_brand_name_must_be_unique_within_the_shop(): void
    {
        // The (shop_id, name) composite used to be reachable: the form had no
        // unique rule, so a same-shop duplicate hit the index as a raw 500.
        // scopedUnique turns it into a validation error.
        Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        Livewire::test(CreateBrand::class)
            ->fillForm(['name' => 'Chanel', 'type' => 'designer'])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertSame(1, Brand::count());
    }

    public function test_editing_a_brand_does_not_conflict_with_its_own_name(): void
    {
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm(['name' => 'Chanel'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Brand::count());
    }

    public function test_deleting_a_brand_with_fragrances_keeps_it_and_says_why(): void
    {
        $chanel = Brand::create(['name' => 'Chanel', 'type' => 'designer']);
        $allure = $chanel->products()->create(['name' => 'Allure', 'attributes' => ['concentration' => 'edt', 'gender' => 'male']]);
        $dior = Brand::create(['name' => 'Dior', 'type' => 'designer']);

        Livewire::test(ListBrands::class)
            ->callTableAction('delete', $chanel)
            ->assertNotified('This brand has fragrances')
            ->callTableAction('delete', $dior)
            ->assertHasNoTableActionErrors();

        $this->assertNotNull($chanel->fresh());
        $this->assertSame($chanel->id, $allure->fresh()->brand_id);
        $this->assertNull($dior->fresh());
    }

    public function test_bulk_deleting_brands_keeps_the_ones_with_fragrances(): void
    {
        $chanel = Brand::create(['name' => 'Chanel', 'type' => 'designer']);
        $chanel->products()->create(['name' => 'Allure', 'attributes' => ['concentration' => 'edt', 'gender' => 'male']]);
        $dior = Brand::create(['name' => 'Dior', 'type' => 'designer']);

        Livewire::test(ListBrands::class)
            ->callTableBulkAction('delete', [$chanel, $dior])
            ->assertNotified('1 brand(s) kept');

        $this->assertNotNull($chanel->fresh());
        $this->assertNull($dior->fresh());
    }

    public function test_fragrance_can_be_created_with_three_decant_prices(): void
    {
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'brand_id' => $brand->id,
                'name' => 'Allure Homme Sport',
                'attributes' => ['concentration' => 'cologne', 'gender' => 'male'],
                'variants' => [
                    ['size_ml' => 5, 'price_mmk' => 30000, 'in_stock' => true],
                    ['size_ml' => 10, 'price_mmk' => 55000, 'in_stock' => true],
                    ['size_ml' => 30, 'price_mmk' => 150000, 'in_stock' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $fragrance = Product::where('name', 'Allure Homme Sport')->firstOrFail();
        $this->assertSame('chanel-allure-homme-sport', $fragrance->slug);
        $this->assertSame([5, 10, 30], $fragrance->variants()->pluck('size_ml')->all());
    }

    public function test_duplicate_sizes_in_the_repeater_are_rejected(): void
    {
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'brand_id' => $brand->id,
                'name' => 'Bleu de Chanel',
                'attributes' => ['concentration' => 'edp', 'gender' => 'male'],
                'variants' => [
                    ['size_ml' => 10, 'price_mmk' => 70000, 'in_stock' => true],
                    ['size_ml' => 10, 'price_mmk' => 80000, 'in_stock' => true],
                ],
            ])
            ->call('create');

        $this->assertTrue(Product::where('name', 'Bleu de Chanel')->doesntExist(),
            'A fragrance with duplicate repeater sizes must not be created.');
    }

    public function test_from_price_column_shows_cheapest_in_stock_price(): void
    {
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);
        $fragrance = Product::create([
            'brand_id' => $brand->id,
            'name' => 'Allure Homme Sport',
            'attributes' => ['concentration' => 'cologne', 'gender' => 'male'],
        ]);
        $fragrance->variants()->createMany([
            ['size_ml' => 5, 'price_mmk' => 30000, 'in_stock' => false], // cheapest, but out of stock
            ['size_ml' => 10, 'price_mmk' => 55000],
            ['size_ml' => 30, 'price_mmk' => 150000],
        ]);

        Livewire::test(ListProducts::class)
            ->assertOk()
            ->assertSee('From 55,000 Ks')
            ->assertSee('5ml · 10ml · 30ml');
    }
}
