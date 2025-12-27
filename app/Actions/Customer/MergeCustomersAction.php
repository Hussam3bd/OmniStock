<?php

namespace App\Actions\Customer;

use App\Models\Customer\Customer;
use Illuminate\Support\Facades\DB;

class MergeCustomersAction
{
    /**
     * Merge multiple customers into a single customer.
     *
     * @param  Customer  $primaryCustomer  The customer to keep
     * @param  array<Customer>  $duplicateCustomers  Customers to merge into primary
     * @return Customer The merged customer
     */
    public function execute(Customer $primaryCustomer, array $duplicateCustomers): Customer
    {
        return DB::transaction(function () use ($primaryCustomer, $duplicateCustomers) {
            foreach ($duplicateCustomers as $duplicate) {
                if ($duplicate->id === $primaryCustomer->id) {
                    continue; // Skip the primary customer itself
                }

                // Count before migration for logging
                $ordersCount = $duplicate->orders()->count();
                $addressesCount = $duplicate->addresses()->count();

                // 1. Migrate orders
                $duplicate->orders()->update(['customer_id' => $primaryCustomer->id]);

                // 2. Migrate addresses (polymorphic)
                $duplicate->addresses()->update([
                    'addressable_id' => $primaryCustomer->id,
                ]);

                // 3. Migrate platform mappings (polymorphic)
                // Handle potential duplicates by deleting conflicting mappings from duplicate customer
                $primaryMappings = $primaryCustomer->platformMappings()
                    ->pluck('platform_id', 'platform')
                    ->toArray();

                foreach ($duplicate->platformMappings as $mapping) {
                    $key = $mapping->platform;

                    // If primary customer doesn't have this platform mapping, move it
                    if (! isset($primaryMappings[$key])) {
                        $mapping->update(['entity_id' => $primaryCustomer->id]);
                    } else {
                        // If primary already has this platform, delete duplicate's mapping
                        $mapping->delete();
                    }
                }

                // 4. Migrate SMS logs
                $duplicate->smsLogs()->update(['customer_id' => $primaryCustomer->id]);

                // 5. Merge customer data (fill in missing fields from duplicate)
                $updateData = [];

                if (empty($primaryCustomer->email) && ! empty($duplicate->email)) {
                    $updateData['email'] = $duplicate->email;
                }

                if (empty($primaryCustomer->phone) && ! empty($duplicate->phone)) {
                    $updateData['phone'] = $duplicate->phone;
                }

                // Append notes if duplicate has useful information
                if (! empty($duplicate->notes) &&
                    $duplicate->notes !== 'Customer data pending from Trendyol' &&
                    $duplicate->notes !== $primaryCustomer->notes
                ) {
                    $currentNotes = $primaryCustomer->notes ?? '';
                    $updateData['notes'] = trim($currentNotes."\n\n".'[Merged from customer #'.$duplicate->id.']: '.$duplicate->notes);
                }

                if (! empty($updateData)) {
                    $primaryCustomer->update($updateData);
                }

                // 6. Log the merge
                activity()
                    ->performedOn($primaryCustomer)
                    ->withProperties([
                        'merged_customer_id' => $duplicate->id,
                        'merged_customer_name' => $duplicate->first_name.' '.$duplicate->last_name,
                        'merged_customer_email' => $duplicate->email,
                        'orders_migrated' => $ordersCount,
                        'addresses_migrated' => $addressesCount,
                    ])
                    ->log('customer_merged');

                // 7. Delete the duplicate customer
                $duplicate->delete();
            }

            return $primaryCustomer->fresh();
        });
    }

    /**
     * Find potential duplicate customers based on email, name, or address.
     *
     * @return array<int, array{customer_id: int, duplicates: array, reason: string}>
     */
    public function findPotentialDuplicates(): array
    {
        $potentialDuplicates = [];

        // Find duplicates by email
        $emailDuplicates = Customer::select('email', DB::raw('GROUP_CONCAT(id) as customer_ids'), DB::raw('COUNT(*) as count'))
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('email', '!=', '***')
            ->groupBy('email')
            ->having('count', '>', 1)
            ->get();

        foreach ($emailDuplicates as $group) {
            $customerIds = explode(',', $group->customer_ids);
            $potentialDuplicates[] = [
                'primary_id' => (int) $customerIds[0],
                'duplicate_ids' => array_map('intval', array_slice($customerIds, 1)),
                'reason' => 'Same email: '.$group->email,
                'email' => $group->email,
            ];
        }

        // Find duplicates by full name (excluding masked data)
        $nameDuplicates = Customer::select(
            DB::raw('CONCAT(first_name, " ", last_name) as full_name'),
            DB::raw('GROUP_CONCAT(id) as customer_ids'),
            DB::raw('COUNT(*) as count')
        )
            ->where('first_name', '!=', '***')
            ->where('last_name', '!=', '***')
            ->where('first_name', '!=', 'Trendyol')
            ->where('last_name', '!=', 'Customer')
            ->groupBy('full_name')
            ->having('count', '>', 1)
            ->get();

        foreach ($nameDuplicates as $group) {
            $customerIds = explode(',', $group->customer_ids);

            // Only add if not already in email duplicates
            $alreadyInList = false;
            foreach ($potentialDuplicates as $existing) {
                if (in_array((int) $customerIds[0], array_merge([$existing['primary_id']], $existing['duplicate_ids']))) {
                    $alreadyInList = true;
                    break;
                }
            }

            if (! $alreadyInList) {
                $potentialDuplicates[] = [
                    'primary_id' => (int) $customerIds[0],
                    'duplicate_ids' => array_map('intval', array_slice($customerIds, 1)),
                    'reason' => 'Same name: '.$group->full_name,
                    'full_name' => $group->full_name,
                ];
            }
        }

        return $potentialDuplicates;
    }
}
