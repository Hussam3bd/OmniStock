<?php

namespace Database\Seeders;

use App\Models\Product\VariantOption;
use App\Models\Product\VariantOptionValue;
use Illuminate\Database\Seeder;

class VariantOptionSeeder extends Seeder
{
    public function run(): void
    {
        // Create Color option
        $colorOption = VariantOption::create([
            'name' => 'variant.color',
            'position' => 1,
        ]);

        $colors = [
            ['value' => 'color.black', 'position' => 1],
            ['value' => 'color.white', 'position' => 2],
            ['value' => 'color.red', 'position' => 3],
            ['value' => 'color.blue', 'position' => 4],
            ['value' => 'color.green', 'position' => 5],
            ['value' => 'color.yellow', 'position' => 6],
            ['value' => 'color.orange', 'position' => 7],
            ['value' => 'color.purple', 'position' => 8],
            ['value' => 'color.pink', 'position' => 9],
            ['value' => 'color.brown', 'position' => 10],
            ['value' => 'color.gray', 'position' => 11],
            ['value' => 'color.navy', 'position' => 12],
            ['value' => 'color.beige', 'position' => 13],
        ];

        foreach ($colors as $color) {
            VariantOptionValue::create(array_merge($color, [
                'variant_option_id' => $colorOption->id,
            ]));
        }

        // Create Size option
        $sizeOption = VariantOption::create([
            'name' => 'variant.size',
            'position' => 2,
        ]);

        $sizes = [
            ['value' => 'size.xs', 'position' => 1],
            ['value' => 'size.s', 'position' => 2],
            ['value' => 'size.m', 'position' => 3],
            ['value' => 'size.l', 'position' => 4],
            ['value' => 'size.xl', 'position' => 5],
            ['value' => 'size.xxl', 'position' => 6],
            ['value' => 'size.xxxl', 'position' => 7],
            ['value' => 'size.35', 'position' => 8],
            ['value' => 'size.36', 'position' => 9],
            ['value' => 'size.37', 'position' => 10],
            ['value' => 'size.38', 'position' => 11],
            ['value' => 'size.39', 'position' => 12],
            ['value' => 'size.40', 'position' => 13],
            ['value' => 'size.41', 'position' => 14],
            ['value' => 'size.42', 'position' => 15],
            ['value' => 'size.43', 'position' => 16],
            ['value' => 'size.44', 'position' => 17],
            ['value' => 'size.45', 'position' => 18],
            ['value' => 'size.46', 'position' => 19],
        ];

        foreach ($sizes as $size) {
            VariantOptionValue::create(array_merge($size, [
                'variant_option_id' => $sizeOption->id,
            ]));
        }
    }
}
