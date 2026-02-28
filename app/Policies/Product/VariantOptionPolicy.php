<?php

declare(strict_types=1);

namespace App\Policies\Product;

use App\Models\Product\VariantOption;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VariantOptionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:VariantOption');
    }

    public function view(AuthUser $authUser, VariantOption $variantOption): bool
    {
        return $authUser->can('View:VariantOption');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:VariantOption');
    }

    public function update(AuthUser $authUser, VariantOption $variantOption): bool
    {
        return $authUser->can('Update:VariantOption');
    }

    public function delete(AuthUser $authUser, VariantOption $variantOption): bool
    {
        return $authUser->can('Delete:VariantOption');
    }

    public function restore(AuthUser $authUser, VariantOption $variantOption): bool
    {
        return $authUser->can('Restore:VariantOption');
    }

    public function forceDelete(AuthUser $authUser, VariantOption $variantOption): bool
    {
        return $authUser->can('ForceDelete:VariantOption');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:VariantOption');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:VariantOption');
    }

    public function replicate(AuthUser $authUser, VariantOption $variantOption): bool
    {
        return $authUser->can('Replicate:VariantOption');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:VariantOption');
    }
}
