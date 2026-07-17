<?php

namespace App\Console\Commands;

use App\Models\DecantPrice;
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
            'This permanently deletes ALL orders, ALL fragrances (with their prices and images) and ALL promo codes. Brands and the admin login are kept. Continue?'
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
            Order::query()->delete();
            DecantPrice::query()->delete();

            Fragrance::query()->whereNotNull('image_path')->pluck('image_path')
                ->each(fn (string $path) => Storage::disk(config('filesystems.media_disk'))->delete($path));
            Fragrance::query()->delete();
            PromoCode::query()->delete();

            return $counts;
        });

        Cache::forget('api.meta');
        Cache::forget('api.brands');

        $this->info("Deleted {$counts['orders']} order(s) and {$counts['fragrances']} fragrance(s). Brands and admin user kept — ready for real inventory.");

        return self::SUCCESS;
    }
}
