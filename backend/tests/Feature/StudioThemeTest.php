<?php

namespace Tests\Feature;

use App\Filament\Studio\Resources\Shops\ShopResource;
use App\Models\Shop;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 34 PR-3b — the Studio pine theme. Theme is mostly visual, so these pin the few
 * things that CAN regress silently: the pine ramp is the registered primary, its
 * accent steps clear WCAG AA on the dark surface, Studio is dark by construction, and
 * — critically — dark is forced WITHOUT Filament's shared-localStorage path, which
 * would drag the /admin panel dark too (the reason PR-3b does not use darkMode(isForced:)).
 * The slug chip carries the §3 vial-label scope so its restyle stays Studio-only.
 */
class StudioThemeTest extends TestCase
{
    use RefreshDatabase;

    /** The pine ramp (mirror of design-tokens.json / globals.css) is the Studio primary. */
    public function test_studio_registers_the_pine_ramp_as_primary(): void
    {
        $primary = Filament::getPanel('studio')->getColors()['primary'];

        $this->assertSame([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950], array_keys($primary));
        $this->assertSame('#5EC3B4', $primary[400]);
        $this->assertSame('#2FAF9F', $primary[500]);
        $this->assertSame('#04302A', $primary[950]);
    }

    /**
     * The 400/500 accent steps must clear AA (4.5:1) for text against Filament's dark
     * body background (gray-950). If a future ramp edit dims below that, this fails —
     * the contrast promise of §5, pinned in the framework's own math.
     */
    public function test_pine_accent_is_a_a_legible_on_the_dark_surface(): void
    {
        $primary = Filament::getPanel('studio')->getColors()['primary'];
        $darkSurface = Color::Gray[950];

        $this->assertGreaterThanOrEqual(
            Color::WCAG_AA_TEXT,
            Color::calculateContrastRatio($primary[400], $darkSurface),
        );
        $this->assertGreaterThanOrEqual(
            Color::WCAG_AA_TEXT,
            Color::calculateContrastRatio($primary[500], $darkSurface),
        );
    }

    public function test_studio_defaults_to_dark(): void
    {
        $this->assertSame(ThemeMode::Dark, Filament::getPanel('studio')->getDefaultThemeMode());
    }

    /**
     * Dark is forced by adding the class in <head> before paint — NOT by Filament's
     * forced-dark path, which writes the origin-global localStorage 'theme' key that
     * /admin shares. Asserting that write is ABSENT is the regression guard against a
     * well-meaning switch to darkMode(isForced:) that would flip the Admin panel dark.
     */
    public function test_studio_forces_dark_without_writing_the_admin_shared_theme_key(): void
    {
        $shop = Shop::factory()->create(['slug' => 'theme-shop', 'name' => 'Theme Shop']);
        $this->actingAs($this->studioUser());

        $response = $this
            ->get(ShopResource::getUrl('view', ['record' => $shop], panel: 'studio'))
            ->assertOk();

        $response->assertSee("document.documentElement.classList.add('dark')", false);
        $response->assertDontSee("localStorage.setItem('theme', 'dark')", false);
        $response->assertSee('.fi-theme-switcher', false); // the toggle is hidden
    }

    /** The slug chip's vial-label restyle is scoped to .dp-vial-label — Studio only. */
    public function test_studio_slug_chip_carries_the_vial_label_scope(): void
    {
        $shop = Shop::factory()->create(['slug' => 'vial-shop', 'name' => 'Vial Shop']);
        $this->actingAs($this->studioUser());

        $response = $this
            ->get(ShopResource::getUrl('view', ['record' => $shop], panel: 'studio'))
            ->assertOk();

        $response->assertSee('dp-vial-label', false);            // scope class on the slug entry
        $response->assertSee('.dp-vial-label .fi-badge', false); // the injected hairline-pill CSS
    }
}
