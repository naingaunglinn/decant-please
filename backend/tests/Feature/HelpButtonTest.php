<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\StudioHelp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 45: the admin's Help button — deep links to the studio's Telegram /
 * Viber. Platform env only; blank or malformed hides a channel, never a 500.
 * The pre-filled message names the current shop and no other.
 */
class HelpButtonTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Shop $shop): User
    {
        $owner = User::factory()->create();
        $owner->shops()->attach($shop);
        $owner->assignRole('shop_owner');

        return $owner;
    }

    private function channels(?string $telegram, ?string $viber): void
    {
        config([
            'services.support.telegram_username' => $telegram,
            'services.support.viber_number' => $viber,
        ]);
    }

    public function test_nothing_renders_while_no_channel_is_set(): void
    {
        $shop = Shop::factory()->create(['slug' => 'thida-closet']);
        $this->actingAs($this->ownerOf($shop));

        $this->assertSame([], StudioHelp::links($shop));
        $this->get('/admin/thida-closet')->assertOk()
            ->assertDontSee('fi-help-menu', false)
            ->assertDontSee('t.me/', false)
            ->assertDontSee('viber://', false);
    }

    public function test_both_channels_render_in_the_top_bar_naming_the_shop(): void
    {
        $this->channels('@cornerarea_help', '09 791 234 567');
        $shop = Shop::factory()->create(['slug' => 'thida-closet', 'name' => 'Thida Closet']);
        $this->actingAs($this->ownerOf($shop));

        $message = rawurlencode(StudioHelp::message($shop));

        $this->get('/admin/thida-closet')->assertOk()
            ->assertSee('fi-help-menu', false)
            ->assertSee('https://t.me/cornerarea_help?text='.$message, false)
            ->assertSee('viber://chat?number=%2B959791234567&amp;draft='.$message, false)
            ->assertSee('Thida Closet (thida-closet)');
    }

    public function test_each_channel_renders_on_its_own(): void
    {
        $this->channels('cornerarea_help', null);
        $this->assertSame(['telegram'], array_column(StudioHelp::links(null), 'channel'));

        $this->channels(null, '+959791234567');
        $this->assertSame(['viber'], array_column(StudioHelp::links(null), 'channel'));
    }

    public function test_a_malformed_value_hides_that_channel_and_never_breaks_the_page(): void
    {
        $this->channels('bad name!', '12345');
        $shop = Shop::factory()->create(['slug' => 'thida-closet']);
        $this->actingAs($this->ownerOf($shop));

        $this->assertSame([], StudioHelp::links($shop));
        $this->get('/admin/thida-closet')->assertOk()->assertDontSee('fi-help-menu', false);

        $this->channels('abc', '+959791234567'); // too short for Telegram
        $this->assertSame(['viber'], array_column(StudioHelp::links($shop), 'channel'));
    }

    public function test_a_burmese_shop_name_is_url_encoded(): void
    {
        $this->channels('cornerarea_help', null);
        $shop = Shop::factory()->create(['slug' => 'shwe-bakery', 'name' => 'ရွှေ မုန့်တိုက်']);

        $url = StudioHelp::links($shop)[0]['url'];

        $this->assertStringNotContainsString(' ', $url);
        $this->assertStringContainsString(rawurlencode('ရွှေ မုန့်တိုက်'), $url);
        $this->assertSame(StudioHelp::message($shop), rawurldecode(explode('?text=', $url)[1]));
    }

    public function test_a_shops_panel_never_names_another_shop(): void
    {
        $this->channels('cornerarea_help', '09791234567');
        $a = Shop::factory()->create(['slug' => 'shop-alpha', 'name' => 'Alpha Scents']);
        $b = Shop::factory()->create(['slug' => 'shop-bravo', 'name' => 'Bravo Wear']);
        $this->actingAs($this->ownerOf($b));

        $this->get('/admin/shop-bravo')->assertOk()
            ->assertSee(rawurlencode(StudioHelp::message($b)), false)
            ->assertDontSee('Alpha Scents')
            ->assertDontSee('shop-alpha');

        $this->get("/admin/{$a->slug}")->assertNotFound();
    }

    public function test_a_studio_admin_sees_no_help_button(): void
    {
        $this->channels('cornerarea_help', '09791234567');
        $shop = Shop::factory()->create(['slug' => 'thida-closet']);
        $this->actingAs($this->studioUser());

        $this->get('/admin/thida-closet')->assertOk()->assertDontSee('fi-help-menu', false);
    }

    public function test_the_login_and_sign_up_pages_show_help_without_a_shop(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee('fi-help-links', false);

        $this->channels('cornerarea_help', '09791234567');
        config(['services.phone_verification.driver' => 'log']);
        $message = rawurlencode(StudioHelp::message(null));

        $this->get('/admin/login')->assertOk()
            ->assertSee('fi-help-links', false)
            ->assertSee('https://t.me/cornerarea_help?text='.$message, false);
        $this->get('/admin/register')->assertOk()
            ->assertSee('fi-help-links', false)
            ->assertSee('viber://chat?number=%2B959791234567', false);
    }
}
