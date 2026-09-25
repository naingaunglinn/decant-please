<?php

namespace App\Filament\Pages;

use App\Enums\ShopStatus;
use App\Models\DeliveryTownship;
use App\Models\Product;
use App\Models\Shop;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The shop's dashboard, plus the seller's Publish button (step 44b) while the
 * shop is still `onboarding`. Scoping: the current tenant only — the checklist's
 * counts are BelongsToShop queries under the panel's TenantContext.
 *
 * The button shows only to a member with a verified phone; Shop::publish() is
 * where that rule lives. A studio admin (impersonating or not) doesn't see it —
 * the Studio's own activate() is their power.
 */
class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Open my shop · ဆိုင် ဖွင့်မယ်')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->visible(fn (): bool => $this->shop()->status === ShopStatus::Onboarding
                    && ! auth()->user()->isStudioAdmin()
                    && auth()->user()->phone_verified_at !== null)
                ->requiresConfirmation()
                ->modalHeading('Open your shop to customers? · ဆိုင်ကို ဝယ်သူများအတွက် ဖွင့်မလား?')
                ->modalDescription(fn (): string => $this->checklist())
                ->modalSubmitActionLabel('Open my shop · ဆိုင် ဖွင့်မယ်')
                ->action(function (): void {
                    try {
                        $this->shop()->publish(auth()->user());
                    } catch (AuthorizationException|\DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Your shop is open · ဆိုင် ဖွင့်ပြီးပါပြီ')
                        ->body($this->shop()->storefrontUrl())
                        ->success()
                        ->send();
                }),
        ];
    }

    /** What a live shop still lacks — said, never blocking (the seller decides). */
    private function checklist(): string
    {
        $missing = array_filter([
            Product::query()->exists() ? null
                : 'No products yet · ပစ္စည်း မထည့်ရသေးပါ',
            DeliveryTownship::query()->where('is_active', true)->exists() ? null
                : 'No delivery township switched on · ပို့ပေးမည့် မြို့နယ် မဖွင့်ရသေးပါ',
        ]);

        return $missing === []
            ? 'Customers can order from your shop as soon as it opens. · ဖွင့်ပြီးတာနဲ့ ဝယ်သူများ မှာယူနိုင်ပါပြီ။'
            : 'Still missing: · မပြည့်စုံသေးသည်များ — '.implode('; ', $missing).'. You can open now and add them later. · ယခုဖွင့်ပြီး နောက်မှ ထည့်နိုင်ပါသည်။';
    }

    private function shop(): Shop
    {
        /** @var Shop */
        return Filament::getTenant();
    }
}
