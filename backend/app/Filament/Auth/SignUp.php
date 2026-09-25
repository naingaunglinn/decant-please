<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Support\PhoneVerification;
use App\Support\ShopRegistration;
use App\Templates\Templates;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Self-serve sign-up (step 44b): the seller's account, their verified phone and
 * their shop on one page, registered by ShopRegistration::register() — the same
 * method the Studio uses — so a signed-up shop is exactly a Studio-registered one:
 * `onboarding`, owned by this user alone, on {slug}.{platform domain}.
 *
 * Scoping: platform-level, before any shop exists. It writes only through
 * register(), which runs the tenant-owned writes under the new shop's own context.
 *
 * Off (404) whenever no code can reach a phone (PhoneVerification::enabled()) —
 * checked on every request, not at panel boot. English + Burmese side by side:
 * the admin has no locale switch, and a seller must not need one to sign up.
 */
class SignUp extends Register
{
    private const NOT_A_PHONE = 'Enter a Myanmar mobile number, like 09 7xx xxx xxx. · မြန်မာ ဖုန်းနံပါတ် ထည့်ပါ (ဥပမာ 09 7xx xxx xxx)။';

    // register() owns the only transaction: its geography seed and CORS bust
    // must run after the shop commits, not inside an outer page transaction
    protected ?bool $hasDatabaseTransactions = false;

    public function mount(): void
    {
        abort_unless(PhoneVerification::enabled(), 404);

        parent::mount();
    }

    public function register(): ?RegistrationResponse
    {
        abort_unless(PhoneVerification::enabled(), 404);

        return parent::register();
    }

    /** The "Send code" button. Errors land on the phone field. */
    public function sendCode(): void
    {
        abort_unless(PhoneVerification::enabled(), 404);

        $phone = PhoneVerification::normalize($this->data['phone'] ?? null);

        if ($phone === null) {
            throw ValidationException::withMessages(['data.phone' => self::NOT_A_PHONE]);
        }

        try {
            PhoneVerification::send($phone, (string) request()->ip());
        } catch (ValidationException $e) {
            throw self::onForm($e);
        }

        Notification::make()
            ->title('Code sent · ကုဒ် ပို့ပြီးပါပြီ')
            ->body('Check the messages on '.$phone.' · '.$phone.' သို့ ပို့ထားသော စာကို ကြည့်ပါ')
            ->success()
            ->send();
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $phone = (string) PhoneVerification::normalize($data['phone']);

        try {
            PhoneVerification::check($phone, (string) $data['code']);

            ShopRegistration::register(
                name: $data['shop_name'],
                slug: $data['slug'],
                template: $data['template'],
                owner: [
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'], // already hashed by the field; the cast keeps it
                    'phone' => $phone,
                ],
            );
        } catch (ValidationException $e) {
            throw self::onForm($e);
        }

        PhoneVerification::consume($phone);

        return User::query()->where('email', $data['email'])->firstOrFail();
    }

    /** register()'s errors are keyed `slug`, `email`…; the form's fields live under `data.`. */
    private static function onForm(ValidationException $e): ValidationException
    {
        $messages = [];

        foreach ($e->errors() as $key => $errors) {
            $messages['data.'.$key] = $errors;
        }

        return ValidationException::withMessages($messages);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('You · သင့်အကြောင်း')->schema([
                $this->getNameFormComponent()
                    ->label('Your name · သင့်အမည်'),
                TextInput::make('phone')
                    ->label('Mobile number · ဖုန်းနံပါတ်')
                    ->tel()
                    ->placeholder('09 7xx xxx xxx')
                    ->required()
                    ->maxLength(20)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $phone = PhoneVerification::normalize(is_string($value) ? $value : null);

                        if ($phone === null) {
                            $fail(self::NOT_A_PHONE);
                        } elseif (User::query()->where('phone', $phone)->exists()) {
                            $fail('This phone number already has an account. · ဤဖုန်းနံပါတ်ဖြင့် အကောင့်ဖွင့်ပြီးသား ဖြစ်ပါသည်။');
                        }
                    })
                    ->suffixAction(
                        Action::make('sendCode')
                            ->label('Send code · ကုဒ်ပို့ရန်')
                            ->button()
                            ->action(fn () => $this->sendCode()),
                        isInline: true,
                    ),
                TextInput::make('code')
                    ->label('6-digit code · ဂဏန်း ၆ လုံး ကုဒ်')
                    ->helperText('We send it to your phone. · သင့်ဖုန်းသို့ ပို့ပေးပါမည်။')
                    ->required()
                    ->length(6)
                    ->inputMode('numeric')
                    ->autocomplete('one-time-code'),
                $this->getEmailFormComponent()
                    ->label('Email · အီးမေးလ်')
                    ->helperText('You log in with it. · Login ဝင်ရာတွင် သုံးပါသည်။'),
                $this->getPasswordFormComponent()
                    ->label('Password · စကားဝှက်'),
                $this->getPasswordConfirmationFormComponent()
                    ->label('Password again · စကားဝှက် ထပ်ရိုက်ပါ'),
            ]),
            Section::make('Your shop · သင့်ဆိုင်')->schema([
                TextInput::make('shop_name')
                    ->label('Shop name · ဆိုင်အမည်')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label('Shop address · ဆိုင်လိပ်စာ')
                    ->required()
                    ->maxLength(40)
                    ->rules(fn (): array => ShopRegistration::slugRules())
                    ->helperText(fn (): string => 'Small letters, numbers and dashes. Your shop will be at '
                        .(ShopRegistration::platformHost('your-shop') ?? 'your-shop')
                        .' · အင်္ဂလိပ် အက္ခရာအသေး၊ ဂဏန်းနှင့် - သာ သုံးပါ။'),
                Select::make('template')
                    ->label('What do you sell? · ဘာ ရောင်းမှာလဲ')
                    ->options(Templates::options())
                    ->default(Templates::DEFAULT)
                    ->required()
                    ->in(array_keys(Templates::options())),
            ]),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Open your shop · ဆိုင်ဖွင့်မယ်';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Open your shop · ဆိုင်ဖွင့်မယ်';
    }

    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()->label('Create my shop · ဆိုင် ဖန်တီးမယ်');
    }
}
