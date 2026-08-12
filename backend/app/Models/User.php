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

#[Fillable(['name', 'email', 'password', 'is_studio'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        // Every user is an operator — customers never get accounts (PRODUCT.md non-goals).
        return true;
    }

    /** Shops this user owns/operates (Step 25a). Studio founders bypass this list. */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class);
    }

    /**
     * Filament tenancy: the shops selectable in the switcher. A studio founder
     * (is_studio) sees every shop; a shop owner sees only the ones they belong to.
     *
     * @return Collection<int, Shop>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->is_studio
            ? Shop::query()->orderBy('name')->get()
            : $this->shops()->orderBy('name')->get();
    }

    /** Filament tenancy: may this user operate $tenant? Studio sees all. */
    public function canAccessTenant(Model $tenant): bool
    {
        return $this->is_studio || $this->shops()->whereKey($tenant->getKey())->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_studio' => 'boolean',
        ];
    }
}
