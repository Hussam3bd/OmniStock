<?php

namespace App\Services;

use App\Models\Product\ProductVariant;
use App\Settings\BarcodeSettings;
use Illuminate\Database\Eloquent\Model;

class BarcodeService
{
    public function __construct(protected BarcodeSettings $settings) {}

    /**
     * Generate a UPC-A barcode for a product variant
     */
    public function generateBarcode(ProductVariant $variant): string
    {
        $format = $this->settings->barcode_format;

        return match ($format) {
            'upca' => $this->generateUpcA($variant),
            'ean13' => $this->generateEan13($variant),
            'ean8' => $this->generateEan8($variant),
            default => $this->generateUpcA($variant),
        };
    }

    /**
     * Generate UPC-A barcode (12 digits)
     * Format: [Country Code 3] [Company Prefix 4-6] [Product Code 2-5] [Check Digit 1]
     */
    protected function generateUpcA(ProductVariant $variant): string
    {
        $countryCode = str_pad($this->settings->barcode_country_code, 3, '0', STR_PAD_LEFT);

        // Use company prefix or generate one
        $companyPrefix = $this->settings->barcode_company_prefix
            ? str_pad($this->settings->barcode_company_prefix, 4, '0', STR_PAD_LEFT)
            : str_pad((string) ($variant->product_id % 10000), 4, '0', STR_PAD_LEFT);

        // Generate product code from variant ID
        $productCode = str_pad((string) ($variant->id % 100000), 4, '0', STR_PAD_LEFT);

        // First 11 digits (without check digit)
        $barcode = $countryCode.$companyPrefix.$productCode;

        // Calculate check digit
        $checkDigit = $this->calculateUpcCheckDigit($barcode);

        return $barcode.$checkDigit;
    }

    /**
     * Generate EAN-13 barcode (13 digits)
     */
    protected function generateEan13(ProductVariant $variant): string
    {
        $countryCode = str_pad($this->settings->barcode_country_code, 3, '0', STR_PAD_LEFT);

        $companyPrefix = $this->settings->barcode_company_prefix
            ? str_pad($this->settings->barcode_company_prefix, 5, '0', STR_PAD_LEFT)
            : str_pad((string) ($variant->product_id % 100000), 5, '0', STR_PAD_LEFT);

        $productCode = str_pad((string) ($variant->id % 10000), 4, '0', STR_PAD_LEFT);

        $barcode = $countryCode.$companyPrefix.$productCode;

        $checkDigit = $this->calculateEanCheckDigit($barcode);

        return $barcode.$checkDigit;
    }

    /**
     * Generate EAN-8 barcode (8 digits)
     */
    protected function generateEan8(ProductVariant $variant): string
    {
        $countryCode = str_pad(substr($this->settings->barcode_country_code, 0, 2), 2, '0', STR_PAD_LEFT);

        $productCode = str_pad((string) ($variant->id % 100000), 5, '0', STR_PAD_LEFT);

        $barcode = $countryCode.$productCode;

        $checkDigit = $this->calculateEanCheckDigit($barcode);

        return $barcode.$checkDigit;
    }

    /**
     * Calculate UPC-A check digit
     */
    protected function calculateUpcCheckDigit(string $barcode): int
    {
        $sum = 0;
        for ($i = 0; $i < 11; $i++) {
            $digit = (int) $barcode[$i];
            // Odd positions (1,3,5...) multiply by 3, even by 1
            $sum += ($i % 2 === 0) ? ($digit * 3) : $digit;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return $checkDigit;
    }

    /**
     * Calculate EAN check digit
     */
    protected function calculateEanCheckDigit(string $barcode): int
    {
        $sum = 0;
        $length = strlen($barcode);

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $barcode[$i];
            // Odd positions (from right) multiply by 3
            $sum += (($length - $i) % 2 === 0) ? ($digit * 3) : $digit;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return $checkDigit;
    }

    /**
     * Generate SKU for a product variant
     * Format: MODEL-CODE-OPT1-OPT2
     */
    public function generateSku(ProductVariant $variant, ?Model $product = null): string
    {
        $product = $product ?? $variant->product;

        // Get the product's model code (e.g., REV-0001)
        $modelCode = strtoupper($product->model_code ?? 'PROD-'.str_pad($product->id, 4, '0', STR_PAD_LEFT));

        // Get the actual option values from the database
        $optionValues = $variant->optionValues()
            ->orderBy('position')
            ->get()
            ->map(function ($optionValue) {
                $value = $optionValue->value;

                // Extract the actual value after the last dot (e.g., "color.black" -> "black")
                if (str_contains($value, '.')) {
                    $value = substr($value, strrpos($value, '.') + 1);
                }

                // If value is 3 chars or less, use full value
                if (strlen($value) <= 3) {
                    return strtoupper($value);
                }

                // For numeric values, preserve the full number
                if (is_numeric($value)) {
                    return strtoupper($value);
                }

                // Take first 3 characters for text values
                return strtoupper(substr($value, 0, 3));
            })
            ->toArray();

        // Construct base SKU: MODEL-CODE-OPT1-OPT2
        $baseSku = $modelCode;
        if (! empty($optionValues)) {
            $baseSku .= '-'.implode('-', $optionValues);
        }

        // Check for uniqueness and add suffix if needed
        $sku = $baseSku;
        $counter = 1;
        while (ProductVariant::where('sku', $sku)->where('id', '!=', $variant->id)->exists()) {
            $sku = $baseSku.'-'.str_pad($counter, 2, '0', STR_PAD_LEFT);
            $counter++;
        }

        return $sku;
    }
}
