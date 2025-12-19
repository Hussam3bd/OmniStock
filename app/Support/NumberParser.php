<?php

namespace App\Support;

class NumberParser
{
    /**
     * Parse amount (handles both Turkish and English formats)
     * English: "3,077.08" = 3077.08 (comma = thousands, dot = decimal)
     * Turkish: "3.077,08" = 3077.08 (dot = thousands, comma = decimal)
     */
    public static function parseAmount(string $amount): ?float
    {
        try {
            // Remove spaces
            $amount = str_replace(' ', '', $amount);

            // Determine format by checking which comes last (decimal separator)
            $lastDot = strrpos($amount, '.');
            $lastComma = strrpos($amount, ',');

            if ($lastDot !== false && $lastComma !== false) {
                // Both present - whichever comes last is the decimal separator
                if ($lastDot > $lastComma) {
                    // English format: "3,077.08" - comma is thousands, dot is decimal
                    $amount = str_replace(',', '', $amount);
                } else {
                    // Turkish format: "3.077,08" - dot is thousands, comma is decimal
                    $amount = str_replace('.', '', $amount);
                    $amount = str_replace(',', '.', $amount);
                }
            } elseif ($lastComma !== false) {
                // Only comma - assume Turkish format: "249,00"
                $amount = str_replace(',', '.', $amount);
            }
            // If only dot or neither, keep as-is

            return (float) $amount;
        } catch (\Exception $e) {
            return null;
        }
    }
}
