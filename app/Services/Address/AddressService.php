<?php

namespace App\Services\Address;

use App\Enums\Address\AddressType;
use App\Models\Address\Address;
use App\Models\Address\Country;
use App\Models\Address\District;
use App\Models\Address\Neighborhood;
use App\Models\Address\Province;
use App\Services\PhoneNumberService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AddressService
{
    public function __construct(
        protected TurkishAddressParser $parser,
        protected PhoneNumberService $phoneService
    ) {}

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
            // Try structured data first
            $province = $this->matchProvince($addressData['province'] ?? $addressData['city'] ?? null);

            if ($province) {
                $district = $this->matchDistrict($province, $addressData['district'] ?? $addressData['city'] ?? null);

                if ($district) {
                    $neighborhood = $this->matchNeighborhood($district, $addressData['neighborhood'] ?? null);
                }
            }

            // If we still don't have province/district, try parsing the full address
            if (! $province || ! $district) {
                $parsed = $this->parseFullAddress($addressData);

                if (! $province && $parsed['province']) {
                    $province = $parsed['province'];
                }

                if (! $district && $parsed['district']) {
                    $district = $parsed['district'];
                }

                if (! $neighborhood && $parsed['neighborhood']) {
                    $neighborhood = $parsed['neighborhood'];
                }

                // Extract building details from parsed data
                $addressData['building_number'] = $addressData['building_number'] ?? $parsed['building_number'];
                $addressData['floor'] = $addressData['floor'] ?? $parsed['floor'];
                $addressData['apartment'] = $addressData['apartment'] ?? $parsed['apartment'];
            }
        }

        // Determine address type
        $type = $this->determineAddressType($addressData);

        // Normalize phone number based on country
        $phone = $this->phoneService->normalize(
            $addressData['phone'] ?? null,
            $country?->iso2
        );

        // Use customer email as fallback if address email is not provided
        $email = $addressData['email'] ?? null;
        if (! $email && method_exists($addressable, 'email')) {
            $email = $addressable->email;
        }

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
                'phone' => $phone,
                'email' => $email,
                'address_line1' => $addressData['address1'] ?? $addressData['address_line1'] ?? null,
                'address_line2' => $addressData['address2'] ?? $addressData['address_line2'] ?? null,
                'building_number' => $addressData['building_number'] ?? null,
                'floor' => $addressData['floor'] ?? null,
                'apartment' => $addressData['apartment'] ?? null,
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

    /**
     * Parse full address text to extract province/district/neighborhood
     */
    protected function parseFullAddress(array $addressData): array
    {
        // Combine all address fields into one string for parsing
        $addressText = array_filter([
            $addressData['address1'] ?? $addressData['address_line1'] ?? null,
            $addressData['address2'] ?? $addressData['address_line2'] ?? null,
            $addressData['city'] ?? null,
            $addressData['province'] ?? null,
            $addressData['district'] ?? null,
            $addressData['zip'] ?? $addressData['postal_code'] ?? null,
            $addressData['country'] ?? null,
        ]);

        return $this->parser->parse($addressText);
    }

    /**
     * Check if two address arrays are the same
     */
    public function isSameAddress(array $address1, array $address2): bool
    {
        // Normalize addresses for comparison
        $normalizeAddress = function ($address) {
            return [
                'address1' => trim(strtolower($address['address1'] ?? $address['address_line1'] ?? '')),
                'address2' => trim(strtolower($address['address2'] ?? $address['address_line2'] ?? '')),
                'city' => trim(strtolower($address['city'] ?? $address['province'] ?? '')),
                'province' => trim(strtolower($address['province'] ?? $address['city'] ?? '')),
                'district' => trim(strtolower($address['district'] ?? '')),
                'zip' => trim($address['zip'] ?? $address['postal_code'] ?? ''),
                'country' => trim(strtolower($address['country'] ?? $address['country_code'] ?? '')),
            ];
        };

        $norm1 = $normalizeAddress($address1);
        $norm2 = $normalizeAddress($address2);

        // Check if all key fields match
        return $norm1['address1'] === $norm2['address1']
            && $norm1['zip'] === $norm2['zip']
            && $norm1['city'] === $norm2['city'];
    }
}
