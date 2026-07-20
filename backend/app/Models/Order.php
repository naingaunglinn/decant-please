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

#[Fillable(['customer_name', 'phone', 'address', 'order_from', 'tracking_code', 'decant_date', 'delivery_date', 'status', 'rejection_reason', 'deposit_mmk', 'delivery_fee_mmk', 'discount_mmk', 'promo_code', 'total_mmk', 'notes'])]
class Order extends Model
{
    /** No 0/O/1/I — codes get read out loud over the phone. */
    private const TRACKING_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const TRACKING_LENGTH = 10;

    /** Not persisted — set when a promo lapsed between preview and submission,
     *  so the checkout response can explain the dropped discount. */
    public ?string $promoNote = null;

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
                $price = self::currentPriceFor((int) $item['fragrance_id'], (int) $item['size_ml']);

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

            if (! empty($data['promo_code'])) {
                $order->applyPromo($data['promo_code']);
            }

            $order->recalculateTotal();

            return $order;
        });
    }

    /**
     * Re-evaluates the code against the real subtotal at submission time — never
     * the preview result — with the promo row locked so a limited-use code can't
     * be double-spent. A code that lapsed since preview drops silently (promoNote
     * explains why) instead of failing a real order over a dead coupon.
     * Must run inside newFromCheckout's transaction (lockForUpdate needs one).
     */
    protected function applyPromo(string $code): void
    {
        $subtotal = (int) $this->items()->sum('line_total_mmk');
        $result = PromoCode::evaluate($code, $subtotal, lock: true);

        if (! $result['valid']) {
            $this->promoNote = "That code was no longer valid, so it wasn't applied — you can still place this order without it.";

            return;
        }

        $result['promo']->increment('times_used');
        $this->discount_mmk = $result['discount_mmk'];
        $this->promo_code = $result['promo']->code;
    }

    /** The current-catalog price lookup checkout and promo preview both use. */
    public static function currentPriceFor(int $fragranceId, int $sizeMl): ?DecantPrice
    {
        return DecantPrice::query()
            ->where('fragrance_id', $fragranceId)
            ->where('size_ml', $sizeMl)
            ->where('in_stock', true)
            ->whereHas('fragrance', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('brand', fn ($q) => $q->where('is_active', true)))
            ->with('fragrance.brand')
            ->first();
    }

    public function accept(CarbonInterface $decantDate, ?CarbonInterface $deliveryDate = null): void
    {
        if ($this->status !== OrderStatus::AwaitingConfirmation) {
            throw new LogicException('Only orders awaiting confirmation can be accepted.');
        }

        DB::transaction(function () use ($decantDate, $deliveryDate) {
            $this->pourFromBottles();

            $this->decant_date = $decantDate;
            $this->delivery_date = $deliveryDate;
            $this->status = OrderStatus::Pending;
            $this->save();
        });
    }

    /**
     * Deduct this order's volume from each fragrance's active bottle. All-or-nothing:
     * every bottle is locked and checked before any is decremented, so failing on one
     * item never leaves another half-applied. Fragrances with no active bottle are
     * skipped — bottle tracking is opt-in per fragrance, and accepting an untracked
     * one behaves exactly as it did before bottles existed. Must run inside accept()'s
     * transaction (lockForUpdate needs one — same guard PromoCode::evaluate uses
     * against double-spending a limited-use code).
     */
    protected function pourFromBottles(): void
    {
        // One order can hold several sizes of the same fragrance; they all pour
        // from the same bottle, so sum the need per fragrance first.
        $needs = $this->items()->get()
            ->groupBy('fragrance_id')
            ->map(fn ($items) => [
                'ml' => $items->sum(fn (OrderItem $item) => $item->size_ml * $item->quantity),
                'name' => $items->first()->fragrance_name_snapshot,
            ]);

        $pours = [];

        foreach ($needs as $fragranceId => $need) {
            $bottle = Bottle::query()
                ->where('fragrance_id', $fragranceId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $bottle) {
                continue;
            }

            if ($bottle->remaining_ml < $need['ml']) {
                throw ValidationException::withMessages([
                    'items' => "Not enough {$need['name']} left: the open bottle has "
                        ."{$bottle->remaining_ml}ml, this order needs {$need['ml']}ml.",
                ]);
            }

            $pours[] = [$bottle, $need['ml']];
        }

        foreach ($pours as [$bottle, $ml]) {
            $bottle->update(['remaining_ml' => $bottle->remaining_ml - $ml]);
            $bottle->loadMissing('fragrance')->fragrance->syncStockFromBottle();
        }
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
    public static function unavailableItemMessage(array $item): string
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
