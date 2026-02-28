<?php

declare(strict_types=1);

namespace App\Policies\Accounting;

use App\Models\Accounting\TransactionCategoryMapping;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TransactionCategoryMappingPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TransactionCategoryMapping');
    }

    public function view(AuthUser $authUser, TransactionCategoryMapping $transactionCategoryMapping): bool
    {
        return $authUser->can('View:TransactionCategoryMapping');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TransactionCategoryMapping');
    }

    public function update(AuthUser $authUser, TransactionCategoryMapping $transactionCategoryMapping): bool
    {
        return $authUser->can('Update:TransactionCategoryMapping');
    }

    public function delete(AuthUser $authUser, TransactionCategoryMapping $transactionCategoryMapping): bool
    {
        return $authUser->can('Delete:TransactionCategoryMapping');
    }

    public function restore(AuthUser $authUser, TransactionCategoryMapping $transactionCategoryMapping): bool
    {
        return $authUser->can('Restore:TransactionCategoryMapping');
    }

    public function forceDelete(AuthUser $authUser, TransactionCategoryMapping $transactionCategoryMapping): bool
    {
        return $authUser->can('ForceDelete:TransactionCategoryMapping');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TransactionCategoryMapping');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TransactionCategoryMapping');
    }

    public function replicate(AuthUser $authUser, TransactionCategoryMapping $transactionCategoryMapping): bool
    {
        return $authUser->can('Replicate:TransactionCategoryMapping');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:TransactionCategoryMapping');
    }
}
