<?php

namespace App\Services\Address;

use App\Models\Address\District;
use App\Models\Address\Neighborhood;
use App\Models\Address\Province;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TurkishAddressParser
{
    protected ?Collection $provinces = null;

    protected ?Collection $districts = null;

    protected ?Collection $neighborhoods = null;

    /**
     * Parse address text and extract province, district, and neighborhood
     */
    public function parse(string|array $addressText): array
    {
        // Normalize address text
        $text = $this->normalizeAddress($addressText);

        // Extract in order: province -> district -> neighborhood
        // Each level needs the previous level for context
        $province = $this->extractProvince($text);
        $district = $this->extractDistrict($text, $province);
        $neighborhood = $this->extractNeighborhood($text, $district);

        return [
            'province' => $province,
            'district' => $district,
            'neighborhood' => $neighborhood,
            'postal_code' => $this->extractPostalCode($text),
            'building_number' => $this->extractBuildingNumber($text),
            'floor' => $this->extractFloor($text),
            'apartment' => $this->extractApartment($text),
        ];
    }

    /**
     * Normalize address text (handle multi-line, trim, lowercase)
     */
    protected function normalizeAddress(string|array $addressText): string
    {
        if (is_array($addressText)) {
            $addressText = implode(' ', array_filter($addressText));
        }

        // Replace multiple spaces with single space
        $text = preg_replace('/\s+/', ' ', $addressText);

        return trim($text);
    }

    /**
     * Extract postal code from address text
     */
    protected function extractPostalCode(string $text): ?string
    {
        // Turkish postal codes are 5 digits
        if (preg_match('/\b(\d{5})\b/', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract province from address text
     */
    protected function extractProvince(string $text): ?Province
    {
        // Try postal code matching first
        $postalCode = $this->extractPostalCode($text);
        if ($postalCode) {
            $province = $this->findProvinceByPostalCode($postalCode);
            if ($province) {
                return $province;
            }
        }

        // Try slash pattern (e.g., "Yakutiye/Erzurum")
        if (preg_match('/([^\s,\/]+)\/([^\s,]+)/', $text, $matches)) {
            $provinceName = trim($matches[2]);
            $province = $this->findProvinceByName($provinceName);
            if ($province) {
                return $province;
            }
        }

        // Try to find province name in text
        return $this->findProvinceInText($text);
    }

    /**
     * Extract district from address text
     */
    protected function extractDistrict(string $text, ?Province $province = null): ?District
    {
        // If province not provided, try to extract it first
        if (! $province) {
            $province = $this->extractProvince($text);
        }

        if (! $province) {
            return null;
        }

        // Try postal code matching
        $postalCode = $this->extractPostalCode($text);
        if ($postalCode) {
            $district = $this->findDistrictByPostalCode($postalCode, $province);
            if ($district) {
                return $district;
            }
        }

        // Try slash pattern (e.g., "Yakutiye/Erzurum")
        if (preg_match('/([^\s,\/]+)\/([^\s,]+)/', $text, $matches)) {
            $districtName = trim($matches[1]);
            $district = $this->findDistrictByName($districtName, $province);
            if ($district) {
                return $district;
            }
        }

        // Try to find district name in text
        return $this->findDistrictInText($text, $province);
    }

    /**
     * Extract neighborhood from address text
     */
    protected function extractNeighborhood(string $text, ?District $district = null): ?Neighborhood
    {
        // Try multiple neighborhood patterns
        $patterns = [
            // "şükrüpaşa mahallesi", "Davutlar Mahallesi"
            '/([^\s,]+(?:\s+[^\s,]+)?)\s+mahalle(?:si)?/iu',
            // "Koza Mh", "Merdivenköy Mah"
            '/([^\s,]+(?:\s+[^\s,]+)?)\s+Mh\.?(?:\s|,|$)/iu',
            '/([^\s,]+(?:\s+[^\s,]+)?)\s+Mah\.?(?:\s|,|$)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $neighborhoodName = trim($matches[1]);

                // Only search within district if district is provided
                // Do NOT search globally to avoid wrong matches
                if ($district) {
                    $neighborhood = $this->findNeighborhoodByName($neighborhoodName, $district);
                    if ($neighborhood) {
                        return $neighborhood;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find province by postal code
     */
    protected function findProvinceByPostalCode(string $postalCode): ?Province
    {
        // First two digits of Turkish postal codes represent the province
        $provinceCode = substr($postalCode, 0, 2);

        return $this->getProvinces()
            ->first(function ($province) use ($provinceCode) {
                return str_pad((string) $province->id, 2, '0', STR_PAD_LEFT) === $provinceCode;
            });
    }

    /**
     * Find district by postal code within a province
     */
    protected function findDistrictByPostalCode(string $postalCode, Province $province): ?District
    {
        return $this->getDistricts()
            ->where('province_id', $province->id)
            ->first(function ($district) use ($postalCode) {
                // This is a simplified approach - you might need to store postal codes
                // in the districts table for accurate matching
                return $district->postal_code === $postalCode;
            });
    }

    /**
     * Find province by name with normalization
     */
    protected function findProvinceByName(string $name): ?Province
    {
        $normalized = $this->normalizeText($name);

        return $this->getProvinces()
            ->first(function ($province) use ($normalized) {
                $provinceName = $this->normalizeText($province->getTranslation('name', 'tr'));

                return $provinceName === $normalized
                    || $this->normalizeText($province->getTranslation('name', 'en')) === $normalized;
            });
    }

    /**
     * Find province in free-form text
     */
    protected function findProvinceInText(string $text): ?Province
    {
        $normalized = $this->normalizeText($text);

        // Try to find any province name mentioned in the text
        foreach ($this->getProvinces() as $province) {
            $provinceName = $this->normalizeText($province->getTranslation('name', 'tr'));

            if (str_contains($normalized, $provinceName)) {
                return $province;
            }
        }

        return null;
    }

    /**
     * Find district by name within a province
     */
    protected function findDistrictByName(string $name, Province $province): ?District
    {
        $normalized = $this->normalizeText($name);

        return $this->getDistricts()
            ->where('province_id', $province->id)
            ->first(function ($district) use ($normalized) {
                $districtName = $this->normalizeText($district->getTranslation('name', 'tr'));

                return $districtName === $normalized
                    || $this->normalizeText($district->getTranslation('name', 'en')) === $normalized;
            });
    }

    /**
     * Find district in free-form text within a province
     */
    protected function findDistrictInText(string $text, Province $province): ?District
    {
        $normalized = $this->normalizeText($text);

        // Try to find any district name mentioned in the text
        foreach ($this->getDistricts()->where('province_id', $province->id) as $district) {
            $districtName = $this->normalizeText($district->getTranslation('name', 'tr'));

            if (str_contains($normalized, $districtName)) {
                return $district;
            }
        }

        return null;
    }

    /**
     * Find neighborhood by name within a district
     */
    protected function findNeighborhoodByName(string $name, District $district): ?Neighborhood
    {
        $normalized = $this->normalizeText($name);

        // Get all neighborhoods in this district
        $neighborhoods = Neighborhood::where('district_id', $district->id)->get();

        // Search by comparing normalized versions
        foreach ($neighborhoods as $neighborhood) {
            $neighborhoodName = $this->normalizeText($neighborhood->getTranslation('name', 'tr'));

            if ($neighborhoodName === $normalized) {
                return $neighborhood;
            }
        }

        return null;
    }

    /**
     * Find neighborhood by name globally (less accurate)
     */
    protected function findNeighborhoodByNameGlobal(string $name): ?Neighborhood
    {
        $normalized = $this->normalizeText($name);

        // Get all neighborhoods
        $neighborhoods = Neighborhood::all();

        // Search by comparing normalized versions
        foreach ($neighborhoods as $neighborhood) {
            $neighborhoodName = $this->normalizeText($neighborhood->getTranslation('name', 'tr'));

            if ($neighborhoodName === $normalized) {
                return $neighborhood;
            }
        }

        return null;
    }

    /**
     * Get cached provinces
     */
    protected function getProvinces(): Collection
    {
        if ($this->provinces === null) {
            $this->provinces = Province::all();
        }

        return $this->provinces;
    }

    /**
     * Get cached districts
     */
    protected function getDistricts(): Collection
    {
        if ($this->districts === null) {
            $this->districts = District::all();
        }

        return $this->districts;
    }

    /**
     * Normalize text for matching (trim, lowercase, ASCII conversion for Turkish characters)
     */
    protected function normalizeText(string $text): string
    {
        return Str::of($text)->trim()->lower()->ascii()->toString();
    }

    /**
     * Extract building number from address text
     * Patterns: "No: 5", "No 5", "No:5", "C2 block", "A1 blok"
     */
    protected function extractBuildingNumber(string $text): ?string
    {
        // Pattern: No: X or No X
        if (preg_match('/\bNo:?\s*(\d+[A-Za-z]?)\b/iu', $text, $matches)) {
            return $matches[1];
        }

        // Pattern: C2 block, A1 blok
        if (preg_match('/\b([A-Z]\d+)\s+(?:block|blok)\b/i', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract floor from address text
     * Patterns: "K1", "K 1", "Kat: 3", "kat 3", "Kat:3"
     */
    protected function extractFloor(string $text): ?string
    {
        // Pattern: Kat: X or kat X or kat:X
        if (preg_match('/\bKat:?\s*(\d+)\b/iu', $text, $matches)) {
            return $matches[1];
        }

        // Pattern: K1, K 1
        if (preg_match('/\bK\s*(\d+)\b/iu', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract apartment number from address text
     * Patterns: "D5", "D: 5", "D 5", "Daire: 5", "daire 5", "29 daire"
     */
    protected function extractApartment(string $text): ?string
    {
        // Pattern: Daire: X or daire X (daire before number)
        if (preg_match('/\bDaire:?\s*(\d+[A-Za-z]?)\b/iu', $text, $matches)) {
            return $matches[1];
        }

        // Pattern: 29 daire (number before daire)
        if (preg_match('/\b(\d+[A-Za-z]?)\s+daire\b/iu', $text, $matches)) {
            return $matches[1];
        }

        // Pattern: D: X or D X or D:X
        if (preg_match('/\bD:?\s*(\d+[A-Za-z]?)\b/iu', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
