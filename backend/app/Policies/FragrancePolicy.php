<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Fragrance;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FragrancePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Fragrance');
    }

    public function view(AuthUser $authUser, Fragrance $fragrance): bool
    {
        return $authUser->can('View:Fragrance');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Fragrance');
    }

    public function update(AuthUser $authUser, Fragrance $fragrance): bool
    {
        return $authUser->can('Update:Fragrance');
    }

    public function delete(AuthUser $authUser, Fragrance $fragrance): bool
    {
        return $authUser->can('Delete:Fragrance');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Fragrance');
    }

    public function restore(AuthUser $authUser, Fragrance $fragrance): bool
    {
        return $authUser->can('Restore:Fragrance');
    }

    public function forceDelete(AuthUser $authUser, Fragrance $fragrance): bool
    {
        return $authUser->can('ForceDelete:Fragrance');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Fragrance');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Fragrance');
    }

    public function replicate(AuthUser $authUser, Fragrance $fragrance): bool
    {
        return $authUser->can('Replicate:Fragrance');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Fragrance');
    }
}
