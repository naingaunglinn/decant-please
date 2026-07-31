<?php

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

#[Fillable(['customer_name', 'phone', 'address', 'order_from', 'tracking_code', 'decant_date', 'delivery_date', 'status', 'rejection_reason', 'deposit_mmk', 'delivery_fee_mmk', 'discount_mmk', 'promo_code', 'total_mmk', 'notes', 'payment_status', 'payment_method', 'paid_at', 'payment_proof_path'])]
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

        // Stock is drawn down when the vials are physically filled — i.e. the
        // moment the order becomes Decanted, not when it's accepted. Warn-only:
        // this never blocks the transition (a shortfall just clamps to 0 and
        // shows on the low-stock panel), and it leaves the manual in_stock
        // toggle alone. wasChanged() means it fires once, on the actual
        // transition into Decanted — a plain re-save of an already-decanted
        // order won't pour twice.
        static::updated(function (self $order) {
            if ($order->wasChanged('status') && $order->status === OrderStatus::Decanted) {
                $order->drawDownDecantStock();
            }
        });

        // Keep paid_at consistent with payment_status however it's changed — the
        // admin form's status select, the Mark paid/unpaid actions, or code. Paid
        // stamps the time (preserving an existing one); Unpaid clears it.
        static::saving(function (self $order) {
            if ($order->isDirty('payment_status')) {
                $order->paid_at = $order->payment_status === PaymentStatus::Paid
                    ? ($order->paid_at ?? now())
                    : null;
            }
        });

        // A replaced or cleared screenshot must not linger as an orphan on the
        // private proofs disk, whichever write path changed it — the customer's
        // re-upload or the admin form. In `updated`, getOriginal() still holds
        // the pre-save path; the truthiness guard matters: on a first upload it
        // is null, and passing null would delete the file just stored.
        static::updated(function (self $order) {
            $previous = $order->getOriginal('payment_proof_path');

            if ($order->wasChanged('payment_proof_path') && $previous) {
                $order->deletePaymentProofFile($previous);
            }
        });

        // Don't orphan the payment-proof screenshot when an order is deleted.
        static::deleting(function (self $order) {
            $order->deletePaymentProofFile();
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
                'payment_method' => $data['payment_method'] ?? PaymentMethod::Cod->value,
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

    /**
     * Pour every item's volume off its fragrance's running stock total, once
     * per fragrance (a 5ml + a 10ml of the same juice draws 15ml in one write).
     * Untracked fragrances are skipped inside Fragrance::drawDownStock. Called
     * from the → Decanted transition in booted().
     */
    protected function drawDownDecantStock(): void
    {
        $this->loadMissing('items');

        $mlByFragrance = [];
        foreach ($this->items as $item) {
            if ($item->fragrance_id === null) {
                continue;
            }

            $mlByFragrance[$item->fragrance_id] = ($mlByFragrance[$item->fragrance_id] ?? 0)
                + $item->size_ml * $item->quantity;
        }

        if ($mlByFragrance === []) {
            return;
        }

        Fragrance::whereIn('id', array_keys($mlByFragrance))
            ->get()
            ->each(fn (Fragrance $fragrance) => $fragrance->drawDownStock($mlByFragrance[$fragrance->id]));
    }

    /**
     * The decanter confirms an offline transfer landed. Payment is manual and
     * out-of-band (KBZPay/Wave/bank); this only records the confirmation.
     */
    public function markPaid(): void
    {
        $this->payment_status = PaymentStatus::Paid;
        $this->paid_at = now();
        $this->save();
    }

    /** Undo a confirmation — a mistaken "paid", or a bounced transfer. */
    public function markUnpaid(): void
    {
        $this->payment_status = PaymentStatus::Unpaid;
        $this->paid_at = null;
        $this->save();
    }

    /** Store the customer's transfer screenshot; does NOT mark paid — the
     *  decanter still eyeballs it and confirms. */
    public function attachPaymentProof(string $path): void
    {
        $this->payment_proof_path = $path;
        $this->save();
    }

    /** Remove a stored screenshot object — the current one by default, or the
     *  pre-save one when a replacement landed — from the private proofs disk. */
    public function deletePaymentProofFile(?string $path = null): void
    {
        $path ??= $this->payment_proof_path;

        if ($path) {
            Storage::disk(config('filesystems.proofs_disk'))->delete($path);
        }
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->where('payment_status', PaymentStatus::Unpaid);
    }

    /** Money still owed after any partial deposit — the figure the receipt emphasises. */
    public function balanceDue(): int
    {
        return max(0, $this->total_mmk - $this->deposit_mmk);
    }

    /**
     * Tracked fragrances this order can't be fully poured from, given current
     * stock_ml — surfaced at Accept so a shortfall is caught before committing,
     * not at the decant bench. Untracked (null stock_ml) fragrances are ignored.
     *
     * @return array<array{name: string, needed: int, available: int}>
     */
    public function stockShortfalls(): array
    {
        $this->loadMissing('items.fragrance');

        $needed = [];
        foreach ($this->items as $item) {
            if ($item->fragrance_id === null) {
                continue;
            }

            $needed[$item->fragrance_id]['name'] ??= $item->fragrance?->name ?? $item->fragrance_name_snapshot;
            $needed[$item->fragrance_id]['ml'] = ($needed[$item->fragrance_id]['ml'] ?? 0) + $item->size_ml * $item->quantity;
            $needed[$item->fragrance_id]['stock'] = $item->fragrance?->stock_ml;
        }

        $short = [];
        foreach ($needed as $row) {
            if ($row['stock'] !== null && $row['ml'] > $row['stock']) {
                $short[] = ['name' => $row['name'], 'needed' => $row['ml'], 'available' => (int) $row['stock']];
            }
        }

        return $short;
    }

    /**
     * The production schedule's one source of truth: the window's order items,
     * grouped per day into fragrance+size production lines. Both schedule views
     * (day-card list and month calendar) read this — the grouping must never
     * fork, and when multi-tenancy lands, shop scoping happens here, once
     * (a custom Filament page is outside Filament's tenancy scoping). Days
     * without work are kept (confirmed-empty); cancelled/rejected orders never
     * count. Dates compare via whereDate: the date cast stores a datetime
     * string, which SQLite matches textually against a bare Y-m-d bound
     * (silently missing the window's last day) while Postgres's DATE column
     * truncates — whereDate reads identically on both engines.
     *
     * @return array<int, array{date: CarbonImmutable, groups: Collection}>
     */
    public static function productionScheduleFor(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        if ($to->lessThan($from)) {
            $to = $from;
        }

        $items = OrderItem::query()
            ->whereHas('order', fn ($query) => $query
                ->whereDate('decant_date', '>=', $from->toDateString())
                ->whereDate('decant_date', '<=', $to->toDateString())
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected]))
            ->with(['order', 'fragrance.brand'])
            ->get();

        $byDay = $items->groupBy(fn (OrderItem $item) => $item->order->decant_date->toDateString());

        $days = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $groups = ($byDay->get($day->toDateString()) ?? collect())
                ->groupBy(fn (OrderItem $item) => "{$item->fragrance_id}:{$item->size_ml}")
                ->map(function (Collection $group): array {
                    $first = $group->first();

                    return [
                        'label' => "{$first->fragrance->brand->name} — {$first->fragrance->name}",
                        'size_ml' => $first->size_ml,
                        'quantity' => $group->sum('quantity'),
                        'orders' => $group->map(fn (OrderItem $item) => $item->order)->unique('id')->values(),
                    ];
                })
                ->sortBy([['label', 'asc'], ['size_ml', 'asc']])
                ->values();

            $days[] = ['date' => $day, 'groups' => $groups];
        }

        return $days;
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
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'decant_date' => 'date',
            'delivery_date' => 'date',
            'paid_at' => 'datetime',
            'deposit_mmk' => 'integer',
            'delivery_fee_mmk' => 'integer',
            'discount_mmk' => 'integer',
            'total_mmk' => 'integer',
        ];
    }
}
