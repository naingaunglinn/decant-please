<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Shop;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ShopPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Shop');
    }

    public function view(AuthUser $authUser, Shop $shop): bool
    {
        return $authUser->can('View:Shop');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Shop');
    }

    public function update(AuthUser $authUser, Shop $shop): bool
    {
        return $authUser->can('Update:Shop');
    }

    public function delete(AuthUser $authUser, Shop $shop): bool
    {
        return $authUser->can('Delete:Shop');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Shop');
    }

    public function restore(AuthUser $authUser, Shop $shop): bool
    {
        return $authUser->can('Restore:Shop');
    }

    public function forceDelete(AuthUser $authUser, Shop $shop): bool
    {
        return $authUser->can('ForceDelete:Shop');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Shop');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Shop');
    }

    public function replicate(AuthUser $authUser, Shop $shop): bool
    {
        return $authUser->can('Replicate:Shop');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Shop');
    }
}
