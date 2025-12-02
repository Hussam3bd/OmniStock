<?php

namespace App\Observers;

use App\Models\Customer\Customer;
use App\Services\PhoneNumberService;

class CustomerObserver
{
    public function __construct(
        protected PhoneNumberService $phoneService
    ) {}

    /**
     * Handle the Customer "saving" event.
     * This runs before both creating and updating.
     */
    public function saving(Customer $customer): void
    {
        // Normalize phone number if it's set and has changed
        if ($customer->phone && $customer->isDirty('phone')) {
            $customer->phone = $this->phoneService->normalize($customer->phone);
        }
    }
}
