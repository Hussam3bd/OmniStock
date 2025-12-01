<?php

namespace Database\Seeders;

use App\Models\Product\AttributeMapping;
use App\Models\Product\VariantOption;
use Illuminate\Database\Seeder;

class TrendyolAttributeMappingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $colorOption = VariantOption::color()->first();
        $sizeOption = VariantOption::size()->first();

        if (! $colorOption || ! $sizeOption) {
            $this->command->error('Variant options not found. Please run variant option seeder first.');

            return;
        }

        $mappings = [
            [
                'platform' => 'trendyol',
                'platform_attribute_name' => 'productColor',
                'variant_option_id' => $colorOption->id,
                'is_active' => true,
            ],
            [
                'platform' => 'trendyol',
                'platform_attribute_name' => 'productSize',
                'variant_option_id' => $sizeOption->id,
                'is_active' => true,
            ],
            // Turkish names (in case API returns these)
            [
                'platform' => 'trendyol',
                'platform_attribute_name' => 'Renk',
                'variant_option_id' => $colorOption->id,
                'is_active' => true,
            ],
            [
                'platform' => 'trendyol',
                'platform_attribute_name' => 'Beden',
                'variant_option_id' => $sizeOption->id,
                'is_active' => true,
            ],
        ];

        foreach ($mappings as $mapping) {
            AttributeMapping::updateOrCreate(
                [
                    'platform' => $mapping['platform'],
                    'platform_attribute_name' => $mapping['platform_attribute_name'],
                ],
                $mapping
            );
        }

        $this->command->info('Trendyol attribute mappings created successfully.');
    }
}
