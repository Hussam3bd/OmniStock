<?php

namespace App\Services;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

class PhoneNumberService
{
    /**
     * Default country code for fallback
     */
    protected string $defaultCountry = 'TR';

    /**
     * Normalize phone number to E.164 format (+905530230411)
     *
     * @param  string|null  $phone  Raw phone number
     * @param  string|null  $countryCode  ISO 3166-1 alpha-2 country code (e.g., TR, US, GB)
     * @return string|null Normalized phone number or null if invalid
     */
    public function normalize(?string $phone, ?string $countryCode = null): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Use default country if not provided
        $country = $countryCode ?: $this->defaultCountry;

        try {
            // Parse and format phone number using laravel-phone
            $phoneNumber = phone($phone, $country);

            // Return in E.164 format (+905530230411)
            return $phoneNumber->formatE164();
        } catch (\Exception $e) {
            // If parsing fails, try with default country
            if ($country !== $this->defaultCountry) {
                try {
                    $phoneNumber = phone($phone, $this->defaultCountry);

                    return $phoneNumber->formatE164();
                } catch (\Exception $e) {
                    // Return null if still fails
                    return null;
                }
            }

            return null;
        }
    }

    /**
     * Format phone number for display in international format
     *
     * @param  string|null  $phone  Phone number
     * @param  string|null  $countryCode  ISO 3166-1 alpha-2 country code
     * @return string|null Formatted phone number (+90 553 023 04 11)
     */
    public function formatInternational(?string $phone, ?string $countryCode = null): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $country = $countryCode ?: $this->defaultCountry;

        try {
            $phoneNumber = phone($phone, $country);

            return $phoneNumber->formatInternational();
        } catch (\Exception $e) {
            return $phone; // Return original if parsing fails
        }
    }

    /**
     * Format phone number for display in national format
     *
     * @param  string|null  $phone  Phone number
     * @param  string|null  $countryCode  ISO 3166-1 alpha-2 country code
     * @return string|null Formatted phone number (0553 023 04 11)
     */
    public function formatNational(?string $phone, ?string $countryCode = null): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $country = $countryCode ?: $this->defaultCountry;

        try {
            $phoneNumber = phone($phone, $country);

            return $phoneNumber->formatNational();
        } catch (\Exception $e) {
            return $phone; // Return original if parsing fails
        }
    }

    /**
     * Validate if phone number is valid
     *
     * @param  string|null  $phone  Phone number
     * @param  string|null  $countryCode  ISO 3166-1 alpha-2 country code
     */
    public function isValid(?string $phone, ?string $countryCode = null): bool
    {
        if (empty($phone)) {
            return false;
        }

        $country = $countryCode ?: $this->defaultCountry;

        try {
            $phoneNumber = phone($phone, $country);

            return $phoneNumber->isValid();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get country code from phone number
     *
     * @param  string  $phone  Phone number
     * @return string|null Country code (TR, US, etc.)
     */
    public function getCountryCode(string $phone): ?string
    {
        try {
            $phoneUtil = PhoneNumberUtil::getInstance();
            $phoneNumber = $phoneUtil->parse($phone, null);

            return $phoneUtil->getRegionCodeForNumber($phoneNumber);
        } catch (NumberParseException $e) {
            return null;
        }
    }

    /**
     * Get country calling code from country code
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country code
     * @return int|null Country calling code (90, 1, 44, etc.)
     */
    public function getCallingCode(string $countryCode): ?int
    {
        try {
            $phoneUtil = PhoneNumberUtil::getInstance();

            return $phoneUtil->getCountryCodeForRegion($countryCode);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if phone number is mobile
     *
     * @param  string  $phone  Phone number
     * @param  string|null  $countryCode  ISO 3166-1 alpha-2 country code
     */
    public function isMobile(string $phone, ?string $countryCode = null): bool
    {
        $country = $countryCode ?: $this->defaultCountry;

        try {
            $phoneNumber = phone($phone, $country);

            return $phoneNumber->getType() === 'mobile';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Extract and normalize phone from various possible field names
     *
     * @param  array  $data  Data array containing phone fields
     * @param  string|null  $countryCode  ISO 3166-1 alpha-2 country code
     * @return string|null Normalized phone number
     */
    public function extractFromData(array $data, ?string $countryCode = null): ?string
    {
        $phoneFields = ['phone', 'phonenumber', 'phone_number', 'mobile', 'telephone', 'tel'];

        foreach ($phoneFields as $field) {
            if (! empty($data[$field])) {
                return $this->normalize($data[$field], $countryCode);
            }
        }

        return null;
    }

    /**
     * Set default country code
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country code
     */
    public function setDefaultCountry(string $countryCode): self
    {
        $this->defaultCountry = strtoupper($countryCode);

        return $this;
    }

    /**
     * Get default country code
     */
    public function getDefaultCountry(): string
    {
        return $this->defaultCountry;
    }
}
