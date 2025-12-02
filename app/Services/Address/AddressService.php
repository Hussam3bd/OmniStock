<?php

namespace App\Services\Address;

use App\Enums\Address\AddressType;
use App\Models\Address\Address;
use App\Models\Address\Country;
use App\Models\Address\District;
use App\Models\Address\Neighborhood;
use App\Models\Address\Province;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AddressService
{
    /**
     * Create or update an address for an entity
     */
    public function createOrUpdateAddress(
        Model $addressable,
        array $addressData,
        string $addressType = 'shipping',
        bool $isDefault = false
    ): Address {
        // Find matching Turkish locations
        $country = $this->findCountry($addressData['country'] ?? null, $addressData['country_code'] ?? null);
        $province = null;
        $district = null;
        $neighborhood = null;

        // Only match Turkish addresses
        if ($country && $country->iso2 === 'TR') {
            $province = $this->matchProvince($addressData['province'] ?? $addressData['city'] ?? null);

            if ($province) {
                $district = $this->matchDistrict($province, $addressData['district'] ?? $addressData['city'] ?? null);

                if ($district) {
                    $neighborhood = $this->matchNeighborhood($district, $addressData['neighborhood'] ?? null);
                }
            }
        }

        // Determine address type
        $type = $this->determineAddressType($addressData);

        return Address::updateOrCreate(
            [
                'addressable_type' => get_class($addressable),
                'addressable_id' => $addressable->id,
                'type' => $type->value,
                'title' => $addressData['title'] ?? ucfirst($addressType),
            ],
            [
                'country_id' => $country?->id,
                'province_id' => $province?->id,
                'district_id' => $district?->id,
                'neighborhood_id' => $neighborhood?->id,
                'first_name' => $addressData['first_name'] ?? null,
                'last_name' => $addressData['last_name'] ?? null,
                'company_name' => $addressData['company'] ?? $addressData['company_name'] ?? null,
                'phone' => $addressData['phone'] ?? null,
                'email' => $addressData['email'] ?? null,
                'address_line1' => $addressData['address1'] ?? $addressData['address_line1'] ?? null,
                'address_line2' => $addressData['address2'] ?? $addressData['address_line2'] ?? null,
                'postal_code' => $addressData['zip'] ?? $addressData['postal_code'] ?? null,
                'tax_office' => $addressData['tax_office'] ?? null,
                'tax_number' => $addressData['tax_number'] ?? null,
                'identity_number' => $addressData['identity_number'] ?? null,
                'is_default' => $isDefault,
                'is_billing' => $addressType === 'billing',
                'is_shipping' => $addressType === 'shipping',
            ]
        );
    }

    /**
     * Find country by name or code
     */
    protected function findCountry(?string $countryName, ?string $countryCode = null): ?Country
    {
        if ($countryCode) {
            return Country::where('iso2', strtoupper($countryCode))->first();
        }

        if (! $countryName) {
            return null;
        }

        // Try exact match first
        $country = Country::where('name->en', $countryName)
            ->orWhere('name->tr', $countryName)
            ->first();

        if ($country) {
            return $country;
        }

        // Try partial match
        return Country::where('name->en', 'like', '%'.$countryName.'%')
            ->orWhere('name->tr', 'like', '%'.$countryName.'%')
            ->first();
    }

    /**
     * Match Turkish province by name
     */
    protected function matchProvince(?string $provinceName): ?Province
    {
        if (! $provinceName) {
            return null;
        }

        $normalized = $this->normalizeText($provinceName);

        // Try exact match
        $province = Province::whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.tr"))) = ?', [$normalized])
            ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.en"))) = ?', [$normalized])
            ->first();

        if ($province) {
            return $province;
        }

        // Try without Turkish characters
        $asciiNormalized = Str::ascii($normalized);

        return Province::whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.tr"))) = ?', [$asciiNormalized])
            ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.en"))) = ?', [$asciiNormalized])
            ->first();
    }

    /**
     * Match Turkish district by name within a province
     */
    protected function matchDistrict(Province $province, ?string $districtName): ?District
    {
        if (! $districtName) {
            return null;
        }

        $normalized = $this->normalizeText($districtName);

        // Try exact match within province
        $district = District::where('province_id', $province->id)
            ->where(function ($query) use ($normalized) {
                $query->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.tr"))) = ?', [$normalized])
                    ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.en"))) = ?', [$normalized]);
            })
            ->first();

        if ($district) {
            return $district;
        }

        // Try without Turkish characters
        $asciiNormalized = Str::ascii($normalized);

        return District::where('province_id', $province->id)
            ->where(function ($query) use ($asciiNormalized) {
                $query->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.tr"))) = ?', [$asciiNormalized])
                    ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.en"))) = ?', [$asciiNormalized]);
            })
            ->first();
    }

    /**
     * Match Turkish neighborhood by name within a district
     */
    protected function matchNeighborhood(District $district, ?string $neighborhoodName): ?Neighborhood
    {
        if (! $neighborhoodName) {
            return null;
        }

        $normalized = $this->normalizeText($neighborhoodName);

        // Try exact match within district
        $neighborhood = Neighborhood::where('district_id', $district->id)
            ->where(function ($query) use ($normalized) {
                $query->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.tr"))) = ?', [$normalized])
                    ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.en"))) = ?', [$normalized]);
            })
            ->first();

        if ($neighborhood) {
            return $neighborhood;
        }

        // Try partial match
        return Neighborhood::where('district_id', $district->id)
            ->where(function ($query) use ($normalized) {
                $query->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.tr"))) LIKE ?', ['%'.$normalized.'%'])
                    ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.en"))) LIKE ?', ['%'.$normalized.'%']);
            })
            ->first();
    }

    /**
     * Normalize text for matching (lowercase, trim)
     */
    protected function normalizeText(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    /**
     * Determine address type (residential vs institutional)
     */
    protected function determineAddressType(array $addressData): AddressType
    {
        // If company name exists, it's institutional
        if (! empty($addressData['company']) || ! empty($addressData['company_name'])) {
            return AddressType::INSTITUTIONAL;
        }

        // If tax number or tax office exists, it's institutional
        if (! empty($addressData['tax_number']) || ! empty($addressData['tax_office'])) {
            return AddressType::INSTITUTIONAL;
        }

        return AddressType::RESIDENTIAL;
    }
}
