<?php

namespace App\Filament\Pages;

use App\Models\ShopSetting;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Payment settings: the decanter's own MMQR + KBZPay/Wave details, set here
 * instead of in .env. Saved to the ShopSetting singleton and surfaced to the
 * storefront checkout via /api/v1/meta. Mirrors Filament's own EditProfile
 * form-page pattern (content() embeds the 'form' schema + a Save action).
 *
 * @property-read Schema $form
 */
class ManagePayment extends Page
{
    protected string $view = 'filament.pages.manage-payment';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $title = 'Payment settings';

    protected static ?string $navigationLabel = 'Payment';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(ShopSetting::current()->only([
            'kbzpay_name', 'kbzpay_number', 'wave_name', 'wave_number', 'payment_qr_path', 'payment_instructions',
        ]));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What customers pay to')
                    ->description('Shown at checkout when a customer chooses Online payment. Leave everything blank to hide online payment entirely.')
                    ->columns(2)
                    ->components([
                        FileUpload::make('payment_qr_path')
                            ->label('Payment QR (MMQR)')
                            ->image()
                            ->disk(config('filesystems.media_disk'))
                            // shops/{id}/ prefix (step 32) — see BrandForm
                            ->directory(fn (): string => 'shops/'.app(TenantContext::class)->id().'/payment-qr')
                            ->maxSize(2048)
                            ->columnSpanFull()
                            ->helperText('Your MMQR — customers scan it to pay from any wallet (KBZPay, Wave, AYA, CB…).'),
                        TextInput::make('kbzpay_name')
                            ->label('KBZPay account name')
                            ->maxLength(255),
                        TextInput::make('kbzpay_number')
                            ->label('KBZPay number')
                            ->maxLength(255),
                        TextInput::make('wave_name')
                            ->label('Wave account name')
                            ->maxLength(255),
                        TextInput::make('wave_number')
                            ->label('Wave number')
                            ->maxLength(255),
                        Textarea::make('payment_instructions')
                            ->label('Instructions')
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('Optional note shown with the QR, e.g. "Add your order number in the transfer note."'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        ShopSetting::current()->update($this->form->getState());

        Notification::make()->success()->title('Payment settings saved.')->send();
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
