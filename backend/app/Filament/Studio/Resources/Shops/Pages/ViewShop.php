<?php

namespace App\Filament\Studio\Resources\Shops\Pages;

use App\Filament\Studio\Resources\Shops\ShopResource;
use App\Models\Shop;
use App\Models\StudioAuditEvent;
use App\Support\ShopConfig;
use App\Support\StudioShopStats;
use App\Support\TelegramNotifier;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Step 34 PR-3 — the Studio shop detail page. Read-only: owner/contact, configuration
 * completeness (via the blessed ShopConfig set-context + TelegramNotifier — the same
 * truth the API reads), activity counts (reusing StudioShopStats' single budgeted
 * cross-shop read), and this shop's recent impersonation-audit entries.
 */
class ViewShop extends ViewRecord
{
    protected static string $resource = ShopResource::class;

    public function infolist(Schema $schema): Schema
    {
        /** @var Shop $shop */
        $shop = $this->getRecord();

        // Compute cross-shop-safe values once (each through its blessed mechanism).
        $orders = StudioShopStats::forShop($shop->id, StudioShopStats::orderStats());
        $paymentConfigured = StudioShopStats::paymentConfigured($shop);
        $telegramConfigured = app(TelegramNotifier::class)->isConfiguredForShop($shop);
        $socialConfigured = ShopConfig::forShop($shop, 'social.tiktok') !== null
            || ShopConfig::forShop($shop, 'social.facebook') !== null;
        $domainVerified = $shop->domains()
            ->where('is_primary', true)->whereNotNull('verified_at')->exists();
        $owner = $shop->users()->role('shop_owner')->orderBy('users.id')->first();

        $recentAudit = StudioAuditEvent::query()
            ->where('shop_id', $shop->id)
            ->with('actor')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (StudioAuditEvent $event): array => [
                'action' => $event->action->label(),
                'actor' => $event->actor?->name ?? 'Unknown',
                'when' => $event->created_at?->diffForHumans(),
            ])
            ->all();

        return $schema->components([
            Section::make('Shop')->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('status')->badge(),
                // Vial-label chip (§3 motif) — hairline pill, scoped by the class (see ShopsTable).
                TextEntry::make('slug')->badge()->extraAttributes(['class' => 'dp-vial-label'])->copyable(),
                TextEntry::make('created_at')->label('Registered')->dateTime(),
            ]),

            Section::make('Owner')->columns(2)->schema([
                TextEntry::make('owner_name')->label('Owner')->state($owner?->name)->placeholder('—'),
                TextEntry::make('owner_email')->label('Email')->state($owner?->email)->placeholder('—')->copyable(),
            ]),

            // Payment drives "needs attention" (D1); Telegram/social/domain are shown
            // for completeness but are optional (blank is a valid "off", §33).
            Section::make('Configuration')->columns(2)->schema([
                TextEntry::make('payment')->badge()
                    ->state($paymentConfigured ? 'Configured' : 'Not configured')
                    ->color($paymentConfigured ? 'success' : 'danger'),
                TextEntry::make('telegram')->badge()
                    ->state($telegramConfigured ? 'Configured' : 'Off')
                    ->color($telegramConfigured ? 'success' : 'gray'),
                TextEntry::make('social')->badge()
                    ->state($socialConfigured ? 'Set' : 'Off')
                    ->color($socialConfigured ? 'success' : 'gray'),
                TextEntry::make('domain')->label('Verified domain')->badge()
                    ->state($domainVerified ? 'Verified' : 'None')
                    ->color($domainVerified ? 'success' : 'gray'),
            ]),

            Section::make('Activity')->columns(3)->schema([
                TextEntry::make('orders_total')->label('Orders (all time)')->state((int) $orders->total),
                TextEntry::make('orders_this_month')->label('Orders this month')->state((int) $orders->this_month),
                TextEntry::make('last_activity')->label('Last activity')->state($orders->last_at)->dateTime()->placeholder('—'),
            ]),

            Section::make('Recent Studio activity')->schema([
                RepeatableEntry::make('recent_audit')
                    ->hiddenLabel()
                    ->state($recentAudit)
                    ->schema([
                        TextEntry::make('action')->badge(),
                        TextEntry::make('actor'),
                        TextEntry::make('when'),
                    ])
                    ->columns(3),
            ])->visible($recentAudit !== []),
        ]);
    }
}
