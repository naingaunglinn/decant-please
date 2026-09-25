<?php

namespace App\Filament\Pages;

use App\Models\ShopSetting;
use App\Support\Modules;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Features (step 41): the shop turns optional modules on and off
 * (App\Support\Modules). Scoping: this shop's ShopSetting row only, like
 * ManagePayment. Until the first save the shop runs on its template's defaults
 * (shop_settings.modules is null); saving stores the full enabled set.
 *
 * @property-read Schema $form
 */
class ManageFeatures extends Page
{
    protected string $view = 'filament.pages.manage-features';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Features';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['modules' => Modules::enabled()]);
    }

    public function form(Schema $schema): Schema
    {
        $modules = Modules::all();

        return $schema
            ->components([
                CheckboxList::make('modules')
                    ->label('Turn on what your shop uses')
                    ->helperText('A feature you turn off disappears from your menu and dashboard. Nothing is deleted — turn it back on to see it again.')
                    ->options(array_map(fn (array $module): string => $module['label'], $modules))
                    ->descriptions(array_map(fn (array $module): string => $module['help'], $modules)),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        ShopSetting::current()->update([
            'modules' => Modules::known($this->form->getState()['modules'] ?? []),
        ]);

        Notification::make()->success()->title('Features saved.')->send();

        // Reload so the menu and dashboard show the change.
        $this->redirect(static::getUrl());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())->key('form-actions'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save')->submit('save')->keyBindings(['mod+s']),
        ];
    }
}
