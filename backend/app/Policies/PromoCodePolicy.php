<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PromoCode;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PromoCodePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PromoCode');
    }

    public function view(AuthUser $authUser, PromoCode $promoCode): bool
    {
        return $authUser->can('View:PromoCode');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PromoCode');
    }

    public function update(AuthUser $authUser, PromoCode $promoCode): bool
    {
        return $authUser->can('Update:PromoCode');
    }

    public function delete(AuthUser $authUser, PromoCode $promoCode): bool
    {
        return $authUser->can('Delete:PromoCode');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PromoCode');
    }

    public function restore(AuthUser $authUser, PromoCode $promoCode): bool
    {
        return $authUser->can('Restore:PromoCode');
    }

    public function forceDelete(AuthUser $authUser, PromoCode $promoCode): bool
    {
        return $authUser->can('ForceDelete:PromoCode');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PromoCode');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PromoCode');
    }

    public function replicate(AuthUser $authUser, PromoCode $promoCode): bool
    {
        return $authUser->can('Replicate:PromoCode');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PromoCode');
    }
}
