<?php

namespace App\Observers;

use App\Models\Address\Address;
use App\Services\PhoneNumberService;

class AddressObserver
{
    public function __construct(
        protected PhoneNumberService $phoneService
    ) {}

    /**
     * Handle the Address "saving" event.
     * This runs before both creating and updating.
     */
    public function saving(Address $address): void
    {
        // Normalize phone number if it's set and has changed
        if ($address->phone && $address->isDirty('phone')) {
            // Get country code from the address's country relationship
            $countryCode = $address->country?->iso2;

            $address->phone = $this->phoneService->normalize($address->phone, $countryCode);
        }
    }
}
