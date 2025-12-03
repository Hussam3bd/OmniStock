<?php

namespace Database\Seeders;

use App\Enums\Shipping\ShippingCarrier;
use App\Models\Shipping\ShippingRate;
use App\Models\Shipping\ShippingRateTable;
use Illuminate\Database\Seeder;

class TrendyolShippingRatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvPath = database_path('seeders/data/trendyol-shipping-cost.csv');

        if (! file_exists($csvPath)) {
            $this->command->error("CSV file not found at: {$csvPath}");

            return;
        }

        $this->command->info('Importing Trendyol shipping rates...');

        // Create rate table
        $rateTable = ShippingRateTable::create([
            'name' => 'Trendyol Shipping Rates - December 2025',
            'effective_from' => now()->startOfMonth(),
            'effective_until' => null,
            'is_active' => true,
            'notes' => 'Imported from trendyol-shipping-cost.csv. Prices exclude 20% VAT.',
        ]);

        // Parse CSV
        $csv = array_map('str_getcsv', file($csvPath));
        $headers = array_shift($csv); // Remove header row

        // Map carrier names from CSV to our enum
        $carrierMap = [
            'Aras' => ShippingCarrier::ARAS,
            'DHL eCommerce' => ShippingCarrier::DHL,
            'Kolay Gelsin' => ShippingCarrier::KOLAY_GELSIN,
            'PTT' => ShippingCarrier::PTT,
            'Sürat' => ShippingCarrier::SURAT,
            'TEX' => ShippingCarrier::TEX,
            'Yurtiçi' => ShippingCarrier::YURTICI,
            'Borusan' => ShippingCarrier::BORUSAN,
            'CEVA' => ShippingCarrier::CEVA,
            'Horoz' => ShippingCarrier::HOROZ,
        ];

        $progressBar = $this->command->getOutput()->createProgressBar(count($csv) * count($carrierMap));
        $progressBar->start();

        foreach ($csv as $row) {
            $desi = (float) $row[0]; // Desi/KG column

            foreach ($carrierMap as $csvCarrierName => $carrier) {
                $columnIndex = array_search($csvCarrierName, $headers);

                if ($columnIndex === false) {
                    continue;
                }

                $price = $row[$columnIndex] ?? null;

                // Skip if price is empty
                if (empty($price) || ! is_numeric($price)) {
                    $progressBar->advance();

                    continue;
                }

                // Convert price to minor units (cents) - multiply by 100
                $priceInCents = (int) (floatval($price) * 100);

                // Determine desi_to (next row's desi_from - 0.01, or null for last row)
                $desiTo = null;
                $nextRowIndex = array_search($row, $csv) + 1;
                if (isset($csv[$nextRowIndex])) {
                    $desiTo = (float) $csv[$nextRowIndex][0] - 0.01;
                }

                // Heavy cargo is 100+ desi (starting from row 31 in CSV which is desi 31+)
                $isHeavyCargo = $desi >= 31;

                ShippingRate::create([
                    'shipping_rate_table_id' => $rateTable->id,
                    'carrier' => $carrier->value,
                    'desi_from' => $desi,
                    'desi_to' => $desiTo,
                    'price_excluding_vat' => $priceInCents,
                    'vat_rate' => 20.00,
                    'is_heavy_cargo' => $isHeavyCargo,
                ]);

                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->command->newLine(2);
        $this->command->info('✓ Successfully imported shipping rates!');
        $this->command->info("Rate Table ID: {$rateTable->id}");
        $this->command->info('Total rates created: '.ShippingRate::where('shipping_rate_table_id', $rateTable->id)->count());
    }
}
