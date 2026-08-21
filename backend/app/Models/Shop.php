<?php

namespace App\Models;

use App\Enums\ShopStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The tenant root. Deliberately NOT tenant-owned itself — it carries no shop_id and
 * uses no BelongsToShop trait; it is what everything else scopes to. Its lifecycle
 * is the `status` enum (Step 34 §1 — onboarding/live/suspended/archived); the old
 * `is_active` boolean is now a derived read-only accessor (status === live).
 */
#[Fillable(['slug', 'name', 'status'])]
class Shop extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ShopStatus::class,
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * Only a live shop is served by the public API and the storefront host
     * resolver — the one seam every `->active()` caller (ResolveTenant,
     * ShopDomain::corsOrigins, StorefrontHostController) goes through, so the
     * lifecycle change lands in this one line.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ShopStatus::Live);
    }

    /**
     * Derived, read-only: `is_active` is retired as a column (Step 34 §1) but kept
     * as an accessor so existing readers (e.g. the studio table) keep working
     * without a writable second state. Setting it is a no-op — status is the state.
     */
    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status === ShopStatus::Live,
            // Read-only: writing it is discarded (no column to persist) — status
            // is the single writable state.
            set: fn (): array => [],
        );
    }

    /** Who suspended this shop (Step 34 §1), when a reason + actor were recorded. */
    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    /** Bring a shop live (from onboarding, or un-suspend); clears suspension metadata. */
    public function activate(): void
    {
        $this->forceFill([
            'status' => ShopStatus::Live,
            'suspended_reason' => null,
            'suspended_at' => null,
            'suspended_by' => null,
        ])->save();
    }

    /** Suspend — reason + actor are required (Step 34 §1); storefront/API then 404. */
    public function suspend(User $actor, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException('A suspension reason is required.');
        }

        $this->forceFill([
            'status' => ShopStatus::Suspended,
            'suspended_reason' => $reason,
            'suspended_at' => now(),
            'suspended_by' => $actor->getKey(),
        ])->save();
    }

    /** Archive — the soft, retention-then-export end state (Step 34 §1). */
    public function archive(): void
    {
        $this->forceFill(['status' => ShopStatus::Archived])->save();
    }

    /** Payment / Telegram / social settings for this shop (step 33). */
    public function setting(): HasOne
    {
        return $this->hasOne(ShopSetting::class);
    }

    /**
     * Members of this shop via shop_user (Step 25a). The shop's OWNER is the member
     * holding the shop_owner role — membership carries no role column (roles live in
     * Shield, PR-1). The Studio registry reads this cross-shop; User is a platform
     * table (no BelongsToShop), so no tenant scope applies.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** The hosts this shop's storefront answers on (ADR-0004). */
    public function domains(): HasMany
    {
        return $this->hasMany(ShopDomain::class);
    }

    public function primaryDomain(): HasOne
    {
        return $this->hasOne(ShopDomain::class)->where('is_primary', true);
    }

    /**
     * Where this shop's storefront lives (ADR-0004): its verified primary domain,
     * falling back to the platform FRONTEND_URL while none is mapped yet.
     */
    public function storefrontUrl(): string
    {
        $primary = $this->domains()
            ->where('is_primary', true)
            ->whereNotNull('verified_at')
            ->first();

        return $primary === null
            ? rtrim(config('app.frontend_url'), '/')
            : ShopDomain::originFor($primary->host);
    }

    /**
     * Replace-set this shop's domain list (ADR-0004; the Studio "Domains" action).
     * Normalizes hosts, enforces exactly one primary (promoting the first row when
     * none is flagged — a shop with domains but no canonical one is never a valid
     * state), preserves original verification stamps, and deletes per-model so the
     * CORS cache-bust hooks fire (bulk deletes fire no events — the v12 lesson).
     * Rejections surface as a ValidationException keyed 'domains'.
     */
    public function syncDomains(array $domains): void
    {
        $rows = collect($domains)
            ->map(fn (array $row): array => [
                'host' => ShopDomain::normalizeHost($row['host'] ?? ''),
                'is_primary' => (bool) ($row['is_primary'] ?? false),
                'verified' => (bool) ($row['verified'] ?? false),
            ])
            ->filter(fn (array $row): bool => $row['host'] !== '')
            ->unique('host')
            ->values();

        if ($rows->where('is_primary', true)->count() > 1) {
            throw ValidationException::withMessages(['domains' => 'Only one domain can be primary.']);
        }

        if ($rows->isNotEmpty() && ! $rows->contains('is_primary', true)) {
            $rows = $rows->map(fn (array $row, int $index): array => $index === 0
                ? [...$row, 'is_primary' => true]
                : $row);
        }

        try {
            DB::transaction(function () use ($rows): void {
                $this->domains()
                    ->whereNotIn('host', $rows->pluck('host'))
                    ->get()
                    ->each->delete();

                // drop every primary flag first so the partial unique index can't
                // trip while the flag moves between rows
                $this->domains()->update(['is_primary' => false]);

                $rows->each(function (array $row): void {
                    $existing = $this->domains()->where('host', $row['host'])->first();

                    $this->domains()->updateOrCreate(
                        ['host' => $row['host']],
                        [
                            'is_primary' => $row['is_primary'],
                            // keep the original verification stamp on re-saves
                            'verified_at' => $row['verified'] ? ($existing?->verified_at ?? now()) : null,
                        ],
                    );
                });
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'domains' => 'One of these hosts already belongs to another shop.',
            ]);
        }

        // the bulk un-flag update above fired no model events — bust explicitly
        ShopDomain::forgetCorsOrigins();
    }
}
