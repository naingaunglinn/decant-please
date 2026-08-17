<?php

namespace App\Console\Commands;

use App\Models\DecantPrice;
use App\Models\DeliveryTownship;
use App\Models\Expense;
use App\Models\Fragrance;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PromoCode;
use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FreshStart extends Command
{
    protected $signature = 'decant:fresh-start {--shop= : Slug of the shop to reset} {--force : Skip the confirmation prompt}';

    protected $description = 'Wipe demo fragrances and orders (keeps the admin user and brand list) so real inventory can be entered';

    public function handle(): int
    {
        // Resolve the tenant this reset scopes to. From the CLI there is no request
        // middleware to set it, so --shop is required there (ADR-002); an ambient
        // context (tests, an already-resolved request) is honoured when present.
        $context = app(TenantContext::class);

        if ($slug = $this->option('shop')) {
            $shop = Shop::query()->where('slug', $slug)->first();

            if (! $shop) {
                $this->error("No shop with slug [{$slug}].");

                return self::FAILURE;
            }

            $context->set($shop);
        }

        if (! $context->has()) {
            $this->error('No shop resolved — pass --shop=<slug> (required outside a tenant-scoped context).');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'This permanently deletes ALL orders (with their payment proofs), ALL fragrances (with their prices and images) and ALL promo codes. Brands, the admin login and the delivery-zone list are kept — but every zone is reset to inactive with no fee, so the demo placeholder can\'t go live. Continue?'
        )) {
            $this->info('Nothing deleted.');

            return self::SUCCESS;
        }

        $counts = DB::transaction(function (): array {
            $counts = [
                'orders' => Order::count(),
                'fragrances' => Fragrance::count(),
            ];

            // order_items FK-protects fragrances (restrictOnDelete), so items go first
            OrderItem::query()->delete();
            // Bulk delete fires no model events, so the per-order deleting hook
            // that removes proof objects never runs here — delete THIS shop's
            // proofs by their stored paths (the fragrance-image pattern below).
            // Never a directory wipe: the proofs tree is shared storage, so a
            // directory delete would destroy every shop's files (step 32), and
            // stored paths cover both eras — objects written before the
            // shops/{id}/ prefix landed as well as after.
            $proofsDisk = Storage::disk(config('filesystems.proofs_disk'));
            Order::query()->whereNotNull('payment_proof_path')->pluck('payment_proof_path')
                ->each(fn (string $path) => $proofsDisk->delete($path));
            Order::query()->delete();
            DecantPrice::query()->delete();

            Fragrance::query()->whereNotNull('image_path')->pluck('image_path')
                ->each(fn (string $path) => Storage::disk(config('filesystems.media_disk'))->delete($path));
            Fragrance::query()->delete();
            PromoCode::query()->delete();
            // Expenses are this shop's own ledger — a reset that keeps them while
            // wiping the orders they relate to is inconsistent (findings A6).
            Expense::query()->delete();

            // Zones are configuration (geography + courier coverage), so the
            // rows stay — but the demo's Yangon activation and placeholder fee
            // are demo data, and a fee the code invented must not survive into
            // a real shop. Inactive at 0 until the decanter prices each zone.
            DeliveryTownship::query()->update(['is_active' => false, 'fee_mmk' => 0]);

            return $counts;
        });

        // Per-shop keys (Step 23 §6). The bulk zone update above fires no model
        // events, so its cache is dropped here rather than by a saved hook.
        $slug = $this->tenantSlug();
        Cache::forget("api.meta.{$slug}");
        Cache::forget("api.brands.{$slug}");
        Cache::forget("api.delivery-zones.{$slug}");

        $this->info("Deleted {$counts['orders']} order(s) and {$counts['fragrances']} fragrance(s). Brands and admin user kept — ready for real inventory.");

        return self::SUCCESS;
    }

    private function tenantSlug(): string
    {
        return app(TenantContext::class)->slug() ?? '';
    }
}
