<?php

namespace Tests\Feature;

use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\Fragrances\Pages\CreateFragrance;
use App\Filament\Resources\Fragrances\Pages\ListFragrances;
use App\Models\Brand;
use App\Models\Fragrance;
use App\Models\User;
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

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin@decantplease.local',
            'password' => 'secret-password',
        ]));
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
        $this->assertStringStartsWith('brands/', $brand->logo_path);
        Storage::disk('public')->assertExists($brand->logo_path);
    }

    public function test_fragrance_can_be_created_with_three_decant_prices(): void
    {
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        Livewire::test(CreateFragrance::class)
            ->fillForm([
                'brand_id' => $brand->id,
                'name' => 'Allure Homme Sport',
                'concentration' => 'cologne',
                'gender' => 'male',
                'decantPrices' => [
                    ['size_ml' => 5, 'price_mmk' => 30000, 'in_stock' => true],
                    ['size_ml' => 10, 'price_mmk' => 55000, 'in_stock' => true],
                    ['size_ml' => 30, 'price_mmk' => 150000, 'in_stock' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $fragrance = Fragrance::where('name', 'Allure Homme Sport')->firstOrFail();
        $this->assertSame('chanel-allure-homme-sport', $fragrance->slug);
        $this->assertSame([5, 10, 30], $fragrance->decantPrices()->pluck('size_ml')->all());
    }

    public function test_duplicate_sizes_in_the_repeater_are_rejected(): void
    {
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        Livewire::test(CreateFragrance::class)
            ->fillForm([
                'brand_id' => $brand->id,
                'name' => 'Bleu de Chanel',
                'concentration' => 'edp',
                'gender' => 'male',
                'decantPrices' => [
                    ['size_ml' => 10, 'price_mmk' => 70000, 'in_stock' => true],
                    ['size_ml' => 10, 'price_mmk' => 80000, 'in_stock' => true],
                ],
            ])
            ->call('create');

        $this->assertTrue(Fragrance::where('name', 'Bleu de Chanel')->doesntExist(),
            'A fragrance with duplicate repeater sizes must not be created.');
    }

    public function test_from_price_column_shows_cheapest_in_stock_price(): void
    {
        $brand = Brand::create(['name' => 'Chanel', 'type' => 'designer']);
        $fragrance = Fragrance::create([
            'brand_id' => $brand->id,
            'name' => 'Allure Homme Sport',
            'concentration' => 'cologne',
            'gender' => 'male',
        ]);
        $fragrance->decantPrices()->createMany([
            ['size_ml' => 5, 'price_mmk' => 30000, 'in_stock' => false], // cheapest, but out of stock
            ['size_ml' => 10, 'price_mmk' => 55000],
            ['size_ml' => 30, 'price_mmk' => 150000],
        ]);

        Livewire::test(ListFragrances::class)
            ->assertOk()
            ->assertSee('From 55,000 Ks')
            ->assertSee('5ml · 10ml · 30ml');
    }
}
