<?php

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

#[Fillable(['customer_name', 'phone', 'address', 'order_from', 'tracking_code', 'decant_date', 'delivery_date', 'status', 'rejection_reason', 'deposit_mmk', 'delivery_fee_mmk', 'discount_mmk', 'total_mmk', 'notes'])]
class Order extends Model
{
    /** No 0/O/1/I — codes get read out loud over the phone. */
    private const TRACKING_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const TRACKING_LENGTH = 10;

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            $order->tracking_code ??= self::generateTrackingCode();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The only path website checkouts take. Prices and availability are re-derived
     * from the current catalog — anything price-like in $data['items'] is ignored.
     *
     * @param  array{customer_name: string, phone: string, address: string, notes?: ?string, items: array<array{fragrance_id: int, size_ml: int, quantity: int}>}  $data
     */
    public static function newFromCheckout(array $data): self
    {
        return DB::transaction(function () use ($data) {
            $order = self::create([
                'customer_name' => $data['customer_name'],
                'phone' => $data['phone'],
                'address' => $data['address'],
                'notes' => $data['notes'] ?? null,
                'order_from' => OrderSource::Website,
                'status' => OrderStatus::AwaitingConfirmation,
            ]);

            foreach ($data['items'] as $i => $item) {
                $price = DecantPrice::query()
                    ->where('fragrance_id', $item['fragrance_id'])
                    ->where('size_ml', $item['size_ml'])
                    ->where('in_stock', true)
                    ->whereHas('fragrance', fn ($query) => $query
                        ->where('is_active', true)
                        ->whereHas('brand', fn ($q) => $q->where('is_active', true)))
                    ->with('fragrance.brand')
                    ->first();

                if (! $price) {
                    throw ValidationException::withMessages([
                        "items.{$i}" => self::unavailableItemMessage($item),
                    ]);
                }

                $order->items()->create([
                    'fragrance_id' => $price->fragrance_id,
                    'fragrance_name_snapshot' => $price->fragrance->brand->name.' '.$price->fragrance->name,
                    'size_ml' => $price->size_ml,
                    'unit_price_mmk' => $price->price_mmk,
                    'quantity' => $item['quantity'],
                ]);
            }

            $order->recalculateTotal();

            return $order;
        });
    }

    public function accept(CarbonInterface $decantDate, ?CarbonInterface $deliveryDate = null): void
    {
        if ($this->status !== OrderStatus::AwaitingConfirmation) {
            throw new LogicException('Only orders awaiting confirmation can be accepted.');
        }

        $this->decant_date = $decantDate;
        $this->delivery_date = $deliveryDate;
        $this->status = OrderStatus::Pending;
        $this->save();
    }

    public function reject(string $reason): void
    {
        if ($this->status !== OrderStatus::AwaitingConfirmation) {
            throw new LogicException('Only orders awaiting confirmation can be rejected.');
        }

        $this->status = OrderStatus::Rejected;
        $this->rejection_reason = $reason;
        $this->save();
    }

    /**
     * Customer self-cancellation — only while the decanter hasn't committed
     * time or stock to it yet. After acceptance it's a phone call, not a click.
     */
    public function cancel(): void
    {
        if ($this->status !== OrderStatus::AwaitingConfirmation) {
            throw new LogicException('Only orders awaiting confirmation can be cancelled by the customer.');
        }

        $this->status = OrderStatus::Cancelled;
        $this->save();
    }

    /** The one lookup both public tracking endpoints share: exact pair or nothing. */
    public static function findByTracking(string $code, string $phone): ?self
    {
        return self::query()
            ->where('tracking_code', Str::upper(trim($code)))
            ->where('phone', trim($phone))
            ->with('items.fragrance.brand')
            ->first();
    }

    public function recalculateTotal(): void
    {
        $items = (int) $this->items()->sum('line_total_mmk');

        $this->total_mmk = max(0, $items + $this->delivery_fee_mmk - $this->discount_mmk);
        $this->save();
    }

    /**
     * A customer-actionable reason: name the fragrance/size so the frontend
     * can say more than "something failed".
     */
    protected static function unavailableItemMessage(array $item): string
    {
        $fragrance = Fragrance::query()->find($item['fragrance_id'] ?? null);

        if (! $fragrance || ! $fragrance->is_active || ! $fragrance->brand?->is_active) {
            return 'That fragrance is no longer available.';
        }

        return "{$item['size_ml']}ml of {$fragrance->name} just sold out — pick another size.";
    }

    public static function generateTrackingCode(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';

            for ($i = 0; $i < self::TRACKING_LENGTH; $i++) {
                $code .= self::TRACKING_ALPHABET[random_int(0, strlen(self::TRACKING_ALPHABET) - 1)];
            }

            if (! self::where('tracking_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique tracking code after 5 attempts.');
    }

    protected function casts(): array
    {
        return [
            'order_from' => OrderSource::class,
            'status' => OrderStatus::class,
            'decant_date' => 'date',
            'delivery_date' => 'date',
            'deposit_mmk' => 'integer',
            'delivery_fee_mmk' => 'integer',
            'discount_mmk' => 'integer',
            'total_mmk' => 'integer',
        ];
    }
}
