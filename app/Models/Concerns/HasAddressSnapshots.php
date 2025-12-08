<?php

namespace App\Models\Concerns;

use App\Models\Address\Address;

trait HasAddressSnapshots
{
    /**
     * Create a snapshot copy of an address for this model
     * This creates an immutable copy that belongs to this model (e.g., Order)
     */
    public function snapshotAddress(Address $sourceAddress): Address
    {
        // Create a new address as a snapshot
        $snapshot = $sourceAddress->replicate();

        // Make it belong to this model (e.g., Order instead of Customer)
        $snapshot->addressable_type = static::class;
        $snapshot->addressable_id = $this->id;

        // Reset flags that shouldn't be copied
        $snapshot->is_default = false;

        // Save the snapshot
        $snapshot->save();

        return $snapshot;
    }

    /**
     * Set shipping address from a customer address (creates snapshot)
     */
    public function setShippingAddressFromSnapshot(Address $customerAddress): void
    {
        // Create snapshot
        $snapshot = $this->snapshotAddress($customerAddress);

        // Update foreign key
        $this->update([
            'shipping_address_id' => $snapshot->id,
        ]);
    }

    /**
     * Set billing address from a customer address (creates snapshot)
     */
    public function setBillingAddressFromSnapshot(Address $customerAddress): void
    {
        // Create snapshot
        $snapshot = $this->snapshotAddress($customerAddress);

        // Update foreign key
        $this->update([
            'billing_address_id' => $snapshot->id,
        ]);
    }

    /**
     * Create or update shipping address for this order
     * If address data is provided, create/update a snapshot
     */
    public function setShippingAddress(array $addressData): Address
    {
        // Check if order already has a shipping address snapshot
        if ($this->shipping_address_id) {
            $address = $this->shippingAddress;
            if ($address && $address->addressable_type === static::class) {
                // Update existing snapshot
                $address->update($addressData);

                return $address;
            }
        }

        // Create new address snapshot
        $address = new Address($addressData);
        $address->addressable_type = static::class;
        $address->addressable_id = $this->id;
        $address->is_shipping = true;
        $address->save();

        // Update foreign key
        $this->update([
            'shipping_address_id' => $address->id,
        ]);

        return $address;
    }

    /**
     * Create or update billing address for this order
     * If address data is provided, create/update a snapshot
     */
    public function setBillingAddress(array $addressData): Address
    {
        // Check if order already has a billing address snapshot
        if ($this->billing_address_id) {
            $address = $this->billingAddress;
            if ($address && $address->addressable_type === static::class) {
                // Update existing snapshot
                $address->update($addressData);

                return $address;
            }
        }

        // Create new address snapshot
        $address = new Address($addressData);
        $address->addressable_type = static::class;
        $address->addressable_id = $this->id;
        $address->is_billing = true;
        $address->save();

        // Update foreign key
        $this->update([
            'billing_address_id' => $address->id,
        ]);

        return $address;
    }
}
