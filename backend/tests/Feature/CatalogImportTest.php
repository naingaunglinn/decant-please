<?php

namespace Tests\Feature;

use App\Enums\BrandType;
use App\Enums\Concentration;
use App\Filament\Resources\Fragrances\Pages\ListFragrances;
use App\Models\Brand;
use App\Models\Fragrance;
use App\Models\User;
use App\Support\CatalogImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'brand,brand_type,name,concentration,gender,notes,vibes,performance,description,price_5ml,price_10ml,price_30ml';

    /** The plainest valid row — 5ml only, no optional text. */
    private const ALLURE_ROW = 'Chanel,designer,Allure Homme Sport,cologne,male,,,,,30000,,';

    public function test_imports_brands_fragrances_and_prices_from_a_price_list(): void
    {
        $csv = self::HEADER."\n"
            .'Chanel,designer,Allure Homme Sport,cologne,male,"Citrus, Musk","Modern, Clean",6-8 Hours,Fresh staple.,30000,55000,120000'."\n"
            .'Chanel,designer,Bleu de Chanel,edp,male,"Incense, Citrus",,,,,"60,000",'."\n"
            .'Creed,niche,Aventus,edp,male,"Pineapple, Birch",,,,,80000,210000';

        $import = CatalogImport::run($csv);

        $this->assertSame(3, $import->created);
        $this->assertSame([0, 0, 0], [$import->updated, $import->skipped, count($import->failures)]);

        // one Chanel, reused across both rows — not one per row
        $this->assertSame(2, Brand::count());
        $this->assertSame(BrandType::Niche, Brand::where('name', 'Creed')->firstOrFail()->type);

        $allure = Fragrance::where('name', 'Allure Homme Sport')->firstOrFail();
        $this->assertSame('Chanel', $allure->brand->name);
        $this->assertSame('chanel-allure-homme-sport', $allure->slug);
        $this->assertSame([5 => 30000, 10 => 55000, 30 => 120000],
            $allure->decantPrices->pluck('price_mmk', 'size_ml')->all());
        $this->assertTrue($allure->decantPrices->every->in_stock);

        // blank price cell = size not offered; "60,000" digit-grouping tolerated
        $bleu = Fragrance::where('name', 'Bleu de Chanel')->firstOrFail();
        $this->assertSame([10 => 60000], $bleu->decantPrices->pluck('price_mmk', 'size_ml')->all());
    }

    public function test_reimport_skips_existing_instead_of_duplicating(): void
    {
        $csv = self::HEADER."\n"
            .self::ALLURE_ROW;

        CatalogImport::run($csv);
        $again = CatalogImport::run($csv);

        $this->assertSame(0, $again->created);
        $this->assertSame(1, $again->skipped);
        $this->assertSame(1, Fragrance::count());
        $this->assertSame(1, Brand::count());
    }

    public function test_bad_rows_fail_alone_with_reasons_while_good_rows_land(): void
    {
        $csv = self::HEADER."\n"
            .self::ALLURE_ROW."\n"
            .'Dior,designer,Sauvage,edtt,male,,,,,45000,,'."\n"          // bad concentration
            .'Dior,designer,Homme Intense,edp,male,,,,,,,'."\n"          // no prices at all
            .',designer,Orphan,edp,male,,,,,30000,,';                    // no brand

        $import = CatalogImport::run($csv);

        $this->assertSame(1, $import->created);
        $this->assertCount(3, $import->failures);
        $this->assertSame([3, 4, 5], array_column($import->failures, 'row'));
        $this->assertStringContainsString('unknown concentration "edtt"', $import->failures[0]['message']);
        $this->assertStringContainsString('at least one price', $import->failures[1]['message']);
        $this->assertStringContainsString('brand is required', $import->failures[2]['message']);

        // the failures CSV mirrors the original columns plus the reason,
        // so it can be fixed and re-uploaded as-is
        $failuresCsv = $import->failuresCsv();
        $this->assertStringContainsString('error', explode("\n", $failuresCsv)[0]);
        $this->assertStringContainsString('Sauvage', $failuresCsv);
        $this->assertStringNotContainsString('Allure', $failuresCsv);
    }

    public function test_enums_match_case_insensitively_and_brands_match_ignoring_case(): void
    {
        Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        $csv = self::HEADER."\n"
            .'chanel,DESIGNER,No 5,PARFUM,Female,,,,,90000,,';

        $import = CatalogImport::run($csv);

        $this->assertSame(1, $import->created);
        $this->assertSame(1, Brand::count()); // "chanel" reused the existing Chanel

        $no5 = Fragrance::where('name', 'No 5')->firstOrFail();
        $this->assertSame(Concentration::Parfum, $no5->concentration);
    }

    public function test_burmese_text_and_excel_bom_survive_the_round_trip(): void
    {
        $csv = "\xEF\xBB\xBF".self::HEADER."\n"
            .'Creed,niche,Aventus,edp,male,,,,နာမည်ကြီး niche ရနံ့။,,80000,';

        $import = CatalogImport::run($csv);

        $this->assertSame(1, $import->created);
        $this->assertSame('နာမည်ကြီး niche ရနံ့။', Fragrance::firstOrFail()->description);
    }

    public function test_update_mode_updates_prices_but_blank_cells_keep_hand_written_fields(): void
    {
        CatalogImport::run(self::HEADER."\n"
            .'Chanel,designer,Allure Homme Sport,cologne,male,,,,Hand-written notes.,30000,55000,');

        $update = CatalogImport::run(self::HEADER."\n"
            .'Chanel,designer,Allure Homme Sport,cologne,male,,,,,35000,,140000', updateExisting: true);

        $this->assertSame(1, $update->updated);
        $this->assertSame(0, $update->created + $update->skipped);

        $allure = Fragrance::firstOrFail();
        $this->assertSame('Hand-written notes.', $allure->description); // blank cell didn't erase it
        $this->assertSame(
            [5 => 35000, 10 => 55000, 30 => 140000], // 5ml updated, 10ml untouched, 30ml added
            $allure->decantPrices->pluck('price_mmk', 'size_ml')->all(),
        );
    }

    public function test_the_shipped_template_imports_cleanly(): void
    {
        $import = CatalogImport::run(CatalogImport::template());

        $this->assertSame(2, $import->created);
        $this->assertSame([], $import->failures);
        $this->assertSame(['Chanel', 'Creed'], Brand::orderBy('name')->pluck('name')->all());
    }

    public function test_unusable_files_are_rejected_whole_with_a_pointer_to_the_template(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no "brand" column');

        CatalogImport::run("name,price_5ml\nAventus,30000");
    }

    public function test_admin_can_import_and_download_the_template_from_the_fragrances_page(): void
    {
        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin@decantplease.local',
            'password' => 'secret-password',
        ]));

        $file = UploadedFile::fake()->createWithContent('catalog.csv',
            self::HEADER."\n".self::ALLURE_ROW);

        Livewire::test(ListFragrances::class)
            ->callTableAction('importCsv', data: ['file' => $file])
            ->assertNotified()
            ->assertOk();

        $this->assertSame(1, Fragrance::count());

        Livewire::test(ListFragrances::class)
            ->callTableAction('downloadCsvTemplate')
            ->assertFileDownloaded('catalog-template.csv')
            ->assertOk();
    }
}
