<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Is this a platform (studio) admin? The single source of truth for "sees and
     * acts on every shop" — the `studio_admin` role (Shield super_admin). Every
     * reader goes through here (issue #110 retired the parallel `is_studio` column,
     * ADR-0003 step 8); never re-inline `hasRole('studio_admin')`.
     */
    public function isStudioAdmin(): bool
    {
        return $this->hasRole('studio_admin');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // The studio panel (/studio) is the super-admin home — studio_admin only.
        // The tenant panel (/admin/{shop}) admits any operator account; WHICH shops
        // they can enter is canAccessTenant's job. Customers never get accounts
        // (PRODUCT.md).
        if ($panel->getId() === 'studio') {
            return $this->isStudioAdmin();
        }

        return true;
    }

    /** Shops this user owns/operates (Step 25a). Studio founders bypass this list. */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class);
    }

    /**
     * Filament tenancy: the shops selectable in the switcher. A studio admin sees
     * every shop; a shop owner sees only the ones they belong to.
     *
     * @return Collection<int, Shop>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->isStudioAdmin()
            ? Shop::query()->orderBy('name')->get()
            : $this->shops()->orderBy('name')->get();
    }

    /** Filament tenancy: may this user operate $tenant? Studio sees all. */
    public function canAccessTenant(Model $tenant): bool
    {
        return $this->isStudioAdmin() || $this->shops()->whereKey($tenant->getKey())->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
