<?php

namespace App\Filament\Pages;

use App\Design\Designs;
use App\Design\Presets;
use App\Design\Theme;
use App\Models\ShopDesign;
use App\Templates\Templates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Design (step 46a): pick one of the shop template's three presets, see the
 * design history, and publish an older row to undo. Every write goes through
 * App\Design\Designs. Scoping: this shop's shop_designs rows and settings row
 * only, through the BelongsToShop scope — Designs::publish() finds the row
 * scoped, so another shop's id is a not-found.
 *
 * Out of the menu until 46b: the storefront doesn't render the design yet.
 */
class ManageDesign extends Page
{
    protected string $view = 'filament.pages.manage-design';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Design · ဒီဇိုင်း';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** @return array<string, array{label: string, description: string, colors: array<string, string>, live: bool}> */
    public function getPresets(): array
    {
        $live = Designs::published();
        $cards = [];

        foreach (Presets::for(Templates::shopDefaultKey()) as $key => $config) {
            $base = Theme::bases()[$config['base']];
            $cards[$key] = [
                'label' => $base['label'],
                'description' => $base['description'],
                'colors' => $config['theme']['colors'],
                // Nothing published: the Clean preset is what the storefront shows.
                'live' => $live === null ? $config['base'] === Presets::DEFAULT_BASE : false,
            ];
        }

        return $cards;
    }

    /** @return Collection<int, ShopDesign> the newest 20 rows */
    public function getHistory(): Collection
    {
        return ShopDesign::query()->with('creator')->latest('id')->limit(20)->get();
    }

    public function getLive(): ?ShopDesign
    {
        return Designs::published();
    }

    public function describe(?ShopDesign $design): string
    {
        return Designs::describe($design);
    }

    public function usePresetAction(): Action
    {
        return Action::make('usePreset')
            ->label('Use this design · ဒီဒီဇိုင်းသုံးမယ်')
            ->requiresConfirmation()
            ->modalHeading('Use this design?')
            ->modalDescription('Your shop switches to this design. You can switch back any time from History below.')
            ->action(function (array $arguments): void {
                Designs::usePreset((string) ($arguments['preset'] ?? ''), auth()->user());

                Notification::make()->success()->title('Design published.')->send();
            });
    }

    public function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Use this one · ဒါကိုသုံးမယ်')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Switch back to this design?')
            ->action(function (array $arguments): void {
                Designs::publish((int) ($arguments['design'] ?? 0));

                Notification::make()->success()->title('Design published.')->send();
            });
    }
}
