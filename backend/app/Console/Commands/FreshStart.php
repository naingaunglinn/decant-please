<?php

namespace App\Console\Commands;

use App\Models\DecantPrice;
use App\Models\DeliveryTownship;
use App\Models\Fragrance;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PromoCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FreshStart extends Command
{
    protected $signature = 'decant:fresh-start {--force : Skip the confirmation prompt}';

    protected $description = 'Wipe demo fragrances and orders (keeps the admin user and brand list) so real inventory can be entered';

    public function handle(): int
    {
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
            // that removes proof objects never runs here — and every order is
            // going anyway, so wipe the whole directory on the proofs disk.
            Storage::disk(config('filesystems.proofs_disk'))->deleteDirectory('payment-proofs');
            Order::query()->delete();
            DecantPrice::query()->delete();

            Fragrance::query()->whereNotNull('image_path')->pluck('image_path')
                ->each(fn (string $path) => Storage::disk(config('filesystems.media_disk'))->delete($path));
            Fragrance::query()->delete();
            PromoCode::query()->delete();

            // Zones are configuration (geography + courier coverage), so the
            // rows stay — but the demo's Yangon activation and placeholder fee
            // are demo data, and a fee the code invented must not survive into
            // a real shop. Inactive at 0 until the decanter prices each zone.
            DeliveryTownship::query()->update(['is_active' => false, 'fee_mmk' => 0]);

            return $counts;
        });

        Cache::forget('api.meta');
        Cache::forget('api.brands');
        Cache::forget('api.delivery-zones'); // the bulk update above fires no model events

        $this->info("Deleted {$counts['orders']} order(s) and {$counts['fragrances']} fragrance(s). Brands and admin user kept — ready for real inventory.");

        return self::SUCCESS;
    }
}
