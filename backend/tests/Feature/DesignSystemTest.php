<?php

namespace Tests\Feature;

use App\Design\DesignConfig;
use App\Design\Designs;
use App\Design\Presets;
use App\Design\Sections;
use App\Design\Theme;
use App\Enums\DesignSource;
use App\Exceptions\ReadOnlyImpersonationException;
use App\Filament\Pages\ManageDesign;
use App\Models\Shop;
use App\Models\ShopDesign;
use App\Models\ShopSetting;
use App\Models\User;
use App\Support\Impersonation;
use App\Support\TenantContext;
use App\Templates\Templates;
use Filament\Events\TenantSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step 46a: the design config validator, the presets, and the append-only
 * design history behind the Design page. Cross-shop cases live in
 * TenantIsolationTest.
 */
class DesignSystemTest extends TestCase
{
    use RefreshDatabase;

    private function shopId(): int
    {
        return app(TenantContext::class)->id();
    }

    /** A valid config to break one rule at a time. */
    private function config(): array
    {
        return Presets::get('decant.bold');
    }

    /** $config with the value at $path replaced (a copy). */
    private static function with(array $config, string $path, mixed $value): array
    {
        data_set($config, $path, $value);

        return $config;
    }

    /** The paths the validator refuses $config with. */
    private function refusedPaths(array $config): array
    {
        try {
            DesignConfig::validate($config, $this->shopId());
        } catch (ValidationException $exception) {
            return array_keys($exception->errors());
        }

        $this->fail('The config was accepted.');
    }

    private function ownerOf(Shop $shop): User
    {
        $owner = User::factory()->create();
        $owner->shops()->attach($shop);
        $owner->assignRole('shop_owner');

        return $owner;
    }

    // ---- presets ----

    public function test_every_template_has_three_presets_and_each_one_validates(): void
    {
        foreach (array_keys(Templates::options()) as $template) {
            $presets = Presets::for($template);

            $this->assertSame(
                array_map(fn (string $base): string => "{$template}.{$base}", array_keys(Theme::bases())),
                array_keys($presets),
            );

            foreach ($presets as $key => $config) {
                $this->assertSame($key, $config['preset']);
                // Presets are stored already normalized: validating changes nothing.
                $this->assertSame($config, DesignConfig::validate($config, $this->shopId()), $key);
            }
        }
    }

    public function test_decant_clean_is_todays_storefront_and_the_other_presets_carry_burmese_copy(): void
    {
        $clean = Presets::default('decant');

        $this->assertSame(
            ['background' => '#f2f8fc', 'text' => '#212121', 'primary' => '#013e37', 'primary_text' => '#f2f8fc'],
            $clean['theme']['colors'],
        );
        $this->assertSame('Great perfume, five millilitres at a time.', $clean['sections'][0]['props']['title']);
        $this->assertSame(['hero', 'featured', 'recently_viewed', 'steps', 'tiles'], array_column($clean['sections'], 'type'));

        foreach (['decant.bold', 'decant.warm', 'clothing.clean', 'clothing.bold', 'clothing.warm'] as $key) {
            $hero = collect(Presets::get($key)['sections'])->firstWhere('type', 'hero');
            $this->assertMatchesRegularExpression('/\p{Myanmar}/u', $hero['props']['title'], $key);
        }
    }

    public function test_an_unknown_preset_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Presets::get('../../.env');
    }

    // ---- the validator ----

    public function test_a_valid_config_is_normalized(): void
    {
        $config = $this->config();
        $config['theme']['colors']['primary'] = '#7A1F5C';
        $config['sections'][] = ['type' => 'about', 'on' => false];

        $valid = DesignConfig::validate($config, $this->shopId());

        $this->assertSame('#7a1f5c', $valid['theme']['colors']['primary']);
        // A missing props block is filled with the section's empty defaults.
        $this->assertSame(['title' => '', 'text' => '', 'image' => null], Arr::last($valid['sections'])['props']);
    }

    /** @return array<string, array{0: callable(array): array, 1: string}> */
    public static function refusals(): array
    {
        return [
            'wrong version' => [fn (array $c) => ['version' => 2] + $c, 'version'],
            'unknown base' => [fn (array $c) => ['base' => 'neon'] + $c, 'base'],
            'unknown top-level key' => [fn (array $c) => $c + ['css' => 'body{}'], 'css'],
            'unknown font' => [fn (array $c) => self::with($c, 'theme.font', 'https://fonts.example/x.woff'), 'theme.font'],
            'named colour' => [fn (array $c) => self::with($c, 'theme.colors.background', 'white'), 'theme.colors.background'],
            'short hex' => [fn (array $c) => self::with($c, 'theme.colors.text', '#000'), 'theme.colors.text'],
            'extra colour' => [fn (array $c) => self::with($c, 'theme.colors.accent', '#ffffff'), 'theme.colors.accent'],
            'unreadable text' => [fn (array $c) => self::with($c, 'theme.colors.text', '#fff5f0'), 'theme.colors.text'],
            'unreadable button' => [fn (array $c) => self::with($c, 'theme.colors.primary_text', '#8a3a70'), 'theme.colors.primary_text'],
            'unknown section' => [fn (array $c) => self::with($c, 'sections.1.type', 'custom_html'), 'sections.1.type'],
            'duplicate section' => [fn (array $c) => self::with($c, 'sections.2.type', 'hero'), 'sections.2.type'],
            'on not a boolean' => [fn (array $c) => self::with($c, 'sections.1.on', 'yes'), 'sections.1.on'],
            'unknown prop' => [fn (array $c) => self::with($c, 'sections.1.props.html', '<b>x</b>'), 'sections.1.props.html'],
            'text too long' => [fn (array $c) => self::with($c, 'sections.1.props.title', str_repeat('က', 81)), 'sections.1.props.title'],
            'newline in a one-line field' => [fn (array $c) => self::with($c, 'sections.1.props.title', "a\nb"), 'sections.1.props.title'],
            'control character' => [fn (array $c) => self::with($c, 'sections.1.props.subtitle', "a\x07b"), 'sections.1.props.subtitle'],
            'reversed text' => [fn (array $c) => self::with($c, 'sections.0.props.text', "Call 09\u{202E}987"), 'sections.0.props.text'],
            'line separator' => [fn (array $c) => self::with($c, 'sections.0.props.text', "a\u{2028}b"), 'sections.0.props.text'],
            'next line' => [fn (array $c) => self::with($c, 'sections.0.props.text', "a\u{85}b"), 'sections.0.props.text'],
            'text not a string' => [fn (array $c) => self::with($c, 'sections.1.props.title', ['x']), 'sections.1.props.title'],
            'image URL' => [fn (array $c) => self::with($c, 'sections.1.props.image', 'https://evil.example/x.jpg'), 'sections.1.props.image'],
            'too many items' => [fn (array $c) => self::with($c, 'sections.4.props.items', array_fill(0, 5, ['title' => 'x', 'text' => 'y'])), 'sections.4.props.items'],
            'items not a list' => [fn (array $c) => self::with($c, 'sections.4.props.items', ['a' => ['title' => 'x']]), 'sections.4.props.items'],
            'sections not a list' => [fn (array $c) => ['sections' => ['hero' => []]] + $c, 'sections'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_the_validator_refuses_and_names_the_path(callable $break, string $path): void
    {
        $this->assertContains($path, $this->refusedPaths($break($this->config())));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function tileLinks(): array
    {
        return [
            'shop page' => ['/shop', true],
            'filtered shop' => ['/shop?brand_type=niche&option%5BSize%5D=M', true],
            'home' => ['/', true],
            'protocol-relative' => ['//evil.example', false],
            'absolute' => ['https://evil.example', false],
            'javascript' => ['javascript:alert(1)', false],
            'backslash trick' => ['/\\evil.example', false],
            'relative' => ['shop', false],
            'dot segment to protocol-relative' => ['/.//evil.example', false],
            'empty segment' => ['/shop//evil.example', false],
            'encoded slash' => ['/%2F/evil.example', false],
            'parent segment' => ['/../x', false],
            'encoded dot' => ['/%2e%2e/x', false],
        ];
    }

    #[DataProvider('tileLinks')]
    public function test_a_tile_link_stays_on_the_shop(string $link, bool $accepted): void
    {
        $config = Presets::get('decant.clean');
        $config['sections'][4]['props']['items'][0]['link'] = $link;

        if ($accepted) {
            $this->assertSame($link, DesignConfig::validate($config, $this->shopId())['sections'][4]['props']['items'][0]['link']);
        } else {
            $this->assertContains('sections.4.props.items.0.link', $this->refusedPaths($config));
        }
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function mapLinks(): array
    {
        return [
            'google maps' => ['https://www.google.com/maps/place/Yangon', true],
            'short link' => ['https://maps.app.goo.gl/AbCdEf123', true],
            'maps.google.com query' => ['https://maps.google.com/?q=16.8,96.1', true],
            'place with coordinates' => ['https://www.google.com/maps/place/Yangon/@16.8409,96.1735,12z', true],
            'maps prefix, not a segment' => ['https://www.google.com/mapsevil', false],
            'dot segment to the redirector' => ['https://www.google.com/maps/../url?q=https://evil.example', false],
            'encoded dot segment' => ['https://www.google.com/maps/..%2F..%2Furl?q=https://evil.example', false],
            'maps.google.com redirector' => ['https://maps.google.com/url?q=https://evil.example', false],
            'generic shortener' => ['https://goo.gl/abc123', false],
            'http' => ['http://maps.app.goo.gl/AbCdEf123', false],
            'google search, not maps' => ['https://www.google.com/search?q=x', false],
            'lookalike host' => ['https://maps.app.goo.gl.evil.example/x', false],
            'credentials trick' => ['https://maps.app.goo.gl@evil.example/x', false],
            'other site' => ['https://evil.example/maps', false],
        ];
    }

    #[DataProvider('mapLinks')]
    public function test_a_map_link_goes_to_a_maps_host_over_https(string $link, bool $accepted): void
    {
        $config = Presets::get('decant.warm');
        $config['sections'][5]['props']['map_link'] = $link;

        if ($accepted) {
            $this->assertSame($link, DesignConfig::validate($config, $this->shopId())['sections'][5]['props']['map_link']);
        } else {
            $this->assertContains('sections.5.props.map_link', $this->refusedPaths($config));
        }
    }

    public function test_an_image_must_be_under_this_shops_design_prefix(): void
    {
        $config = $this->config();
        $prefix = DesignConfig::imagePrefix($this->shopId());

        $config['sections'][1]['props']['image'] = $prefix.'hero-1.jpg';
        $this->assertSame($prefix.'hero-1.jpg', DesignConfig::validate($config, $this->shopId())['sections'][1]['props']['image']);

        foreach ([$prefix.'../../other/x.jpg', 'shops/'.$this->shopId().'/proofs/slip.jpg', $prefix.'a b.jpg', $prefix.'x.svg', $prefix.'x.html', $prefix.'noext'] as $path) {
            $config['sections'][1]['props']['image'] = $path;
            $this->assertContains('sections.1.props.image', $this->refusedPaths($config), $path);
        }
    }

    public function test_burmese_text_with_zero_width_characters_is_accepted(): void
    {
        // ZWSP and ZWNJ are ordinary in Burmese text; only direction and line controls are refused.
        $config = self::with($this->config(), 'sections.0.props.text', "ပို့ခ\u{200B}သက်သာ\u{200C}");

        $this->assertSame("ပို့ခ\u{200B}သက်သာ\u{200C}", DesignConfig::validate($config, $this->shopId())['sections'][0]['props']['text']);
    }

    public function test_the_contrast_ratio_matches_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, Theme::contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, Theme::contrast('#777777', '#777777'), 0.01);
        $this->assertEqualsWithDelta(4.48, Theme::contrast('#777777', '#ffffff'), 0.01);
    }

    public function test_the_library_lists_every_section_type_once(): void
    {
        $this->assertCount(13, Sections::all());
        $this->assertArrayNotHasKey('header', Sections::all()); // the fixed frame
    }

    // ---- history: create, publish, undo ----

    public function test_with_nothing_published_the_shop_renders_its_templates_clean_preset(): void
    {
        $this->assertNull(Designs::published());
        $this->assertSame(Presets::default('decant'), Designs::live());

        ShopSetting::current()->update(['template' => 'clothing']);
        $this->assertSame(Presets::default('clothing'), Designs::live());
        $this->assertSame(0, ShopDesign::count()); // a read never writes a row
    }

    public function test_using_a_preset_adds_a_row_and_publishes_it_and_undo_publishes_the_older_row(): void
    {
        $user = User::factory()->create();

        $bold = Designs::usePreset('decant.bold', $user);
        $this->assertSame(DesignSource::Preset, $bold->source);
        $this->assertSame($user->id, $bold->created_by);
        $this->assertSame($bold->id, ShopSetting::current()->published_design_id);
        // assertEquals: Postgres jsonb doesn't keep object key order (list order it keeps).
        $this->assertEquals(Presets::get('decant.bold'), Designs::live());

        $warm = Designs::usePreset('decant.warm', $user);
        $this->assertSame($warm->id, Designs::published()->id);

        // Undo: publish the older row. Nothing is rewritten; nothing is added.
        Designs::publish($bold->id);
        $this->assertSame($bold->id, Designs::published()->id);
        $this->assertSame(2, ShopDesign::count());
        $this->assertEquals(Presets::get('decant.bold'), $bold->fresh()->config);
    }

    public function test_a_design_row_can_never_be_updated(): void
    {
        $design = Designs::usePreset('decant.bold');

        try {
            $design->update(['source' => DesignSource::Manual]);
            $this->fail('A design row was updated.');
        } catch (LogicException) {
            $this->assertSame(DesignSource::Preset, $design->fresh()->source);
        }
    }

    public function test_a_read_only_studio_operator_cannot_publish_or_add_a_design(): void
    {
        $shop = Shop::factory()->create(['slug' => 'alpha']);
        app(TenantContext::class)->set($shop);
        $bold = Designs::usePreset('decant.bold'); // seeded before anyone is impersonating
        Designs::usePreset('decant.warm');

        $this->actingAs(User::factory()->studio()->create());
        event(new TenantSet($shop, auth()->user()));
        $this->assertTrue(app(Impersonation::class)->isReadOnly());

        foreach ([fn () => Designs::usePreset('decant.clean'), fn () => Designs::publish($bold->id)] as $write) {
            try {
                $write();
                $this->fail('A read-only operator changed the design.');
            } catch (ReadOnlyImpersonationException) {
                // expected
            }
        }

        $this->assertSame(2, ShopDesign::count());
        $this->assertSame('decant.warm', Designs::published()->config['preset']);
    }

    public function test_a_preset_from_another_template_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Designs::usePreset('clothing.bold');
    }

    public function test_create_validates_before_insert_and_does_not_publish(): void
    {
        $design = Designs::create($this->config(), DesignSource::Manual);
        $this->assertNull(Designs::published());
        $this->assertSame(DesignSource::Manual, $design->fresh()->source);

        $bad = self::with($this->config(), 'theme.colors.text', '#fffaf5'); // same as the background

        try {
            Designs::create($bad, DesignSource::Ai, prompt: 'make it white', inputTokens: 10, outputTokens: 20);
            $this->fail('An unreadable config was saved.');
        } catch (ValidationException) {
            $this->assertSame(1, ShopDesign::count());
        }
    }

    public function test_publishing_busts_the_shops_cached_meta(): void
    {
        $this->getJson('/api/v1/'.config('app.shop_slug').'/meta')->assertOk();
        $this->assertTrue(cache()->has('api.meta.'.config('app.shop_slug')));

        Designs::usePreset('decant.warm');

        $this->assertFalse(cache()->has('api.meta.'.config('app.shop_slug')));
    }

    public function test_deleting_a_shop_removes_its_designs(): void
    {
        $shop = Shop::factory()->create();
        app(TenantContext::class)->set($shop);
        Designs::usePreset('decant.bold');
        Designs::usePreset('decant.warm');

        $shop->delete();

        $this->assertSame(0, app(TenantContext::class)->withoutTenancy(fn () => ShopDesign::where('shop_designs.shop_id', $shop->id)->count()));
    }

    // ---- the Design page ----

    public function test_the_design_page_publishes_a_preset_and_switches_back(): void
    {
        $shop = Shop::query()->where('slug', config('app.shop_slug'))->firstOrFail();
        $owner = $this->ownerOf($shop);
        $this->actingAs($owner);

        $this->get(ManageDesign::getUrl())->assertOk()
            ->assertSee('Clean · ရိုးရှင်း — default · မူလ')
            ->assertSee('No changes yet.');

        Livewire::test(ManageDesign::class)
            ->callAction('usePreset', arguments: ['preset' => 'decant.bold'])
            ->assertNotified('Design published · ဒီဇိုင်း ပြောင်းပြီးပါပြီ');

        $bold = Designs::published();
        $this->assertSame('decant.bold', $bold->config['preset']);
        $this->assertSame($owner->id, $bold->created_by);

        Designs::usePreset('decant.warm');

        Livewire::test(ManageDesign::class)
            ->assertSee('Preset: Warm · နွေးထွေး')
            ->callAction('publish', arguments: ['design' => $bold->id]);

        $this->assertSame($bold->id, Designs::published()->id);

        // A tampered request naming another template's preset changes nothing and doesn't 500.
        Livewire::test(ManageDesign::class)
            ->callAction('usePreset', arguments: ['preset' => 'clothing.bold'])
            ->assertNotified('That design isn\'t available for this shop.');
        $this->assertSame($bold->id, Designs::published()->id);
    }

    public function test_the_design_page_is_in_the_menu_now_the_storefront_renders_it(): void
    {
        $this->assertTrue(ManageDesign::shouldRegisterNavigation());
    }

    // ---- the storefront's design (46b) ----

    private function meta(): TestResponse
    {
        return $this->getJson('/api/v1/'.config('app.shop_slug').'/meta')->assertOk();
    }

    public function test_meta_serves_the_clean_preset_until_a_design_is_published(): void
    {
        $this->assertSame(Presets::get('decant.clean'), $this->meta()->json('design'));
    }

    public function test_meta_serves_the_published_design_at_once(): void
    {
        $this->meta(); // cached

        Designs::usePreset('decant.bold');
        // assertEquals: Postgres jsonb reorders object keys; the section list keeps its order.
        $this->assertEquals(Presets::get('decant.bold'), $this->meta()->json('design'));
        $this->assertSame(array_column(Presets::get('decant.bold')['sections'], 'type'), array_column($this->meta()->json('design.sections'), 'type'));

        // Undo: publishing the older row is live on the next request too.
        $first = ShopDesign::query()->oldest('id')->firstOrFail();
        Designs::usePreset('decant.warm');
        Designs::publish($first->id);
        $this->assertSame('decant.bold', $this->meta()->json('design.preset'));
    }

    public function test_meta_resolves_design_image_paths_to_urls(): void
    {
        Storage::fake('public');
        $path = DesignConfig::imagePrefix($this->shopId()).'hero.jpg';
        $config = self::with($this->config(), 'sections.1.props.image', $path);
        Designs::publish(Designs::create($config, DesignSource::Manual)->id);

        $image = $this->meta()->json('design.sections.1.props.image');

        $this->assertSame(Storage::disk('public')->url($path), $image);
        // The stored row keeps the path: a config never holds a URL.
        $this->assertSame($path, Designs::live()['sections'][1]['props']['image']);
    }
}
