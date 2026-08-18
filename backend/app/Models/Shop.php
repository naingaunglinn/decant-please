<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The tenant root. Deliberately NOT tenant-owned itself — it carries no shop_id and
 * uses no BelongsToShop trait; it is what everything else scopes to. Step A keeps it
 * minimal (slug/name/is_active); contact, Telegram, and theming columns wait for
 * Step 25 (design-doc §7).
 */
#[Fillable(['slug', 'name', 'is_active'])]
class Shop extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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
