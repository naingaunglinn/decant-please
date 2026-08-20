<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeliveryTownship;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DeliveryTownshipPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DeliveryTownship');
    }

    public function view(AuthUser $authUser, DeliveryTownship $deliveryTownship): bool
    {
        return $authUser->can('View:DeliveryTownship');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DeliveryTownship');
    }

    public function update(AuthUser $authUser, DeliveryTownship $deliveryTownship): bool
    {
        return $authUser->can('Update:DeliveryTownship');
    }

    public function delete(AuthUser $authUser, DeliveryTownship $deliveryTownship): bool
    {
        return $authUser->can('Delete:DeliveryTownship');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DeliveryTownship');
    }

    public function restore(AuthUser $authUser, DeliveryTownship $deliveryTownship): bool
    {
        return $authUser->can('Restore:DeliveryTownship');
    }

    public function forceDelete(AuthUser $authUser, DeliveryTownship $deliveryTownship): bool
    {
        return $authUser->can('ForceDelete:DeliveryTownship');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DeliveryTownship');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DeliveryTownship');
    }

    public function replicate(AuthUser $authUser, DeliveryTownship $deliveryTownship): bool
    {
        return $authUser->can('Replicate:DeliveryTownship');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DeliveryTownship');
    }
}
