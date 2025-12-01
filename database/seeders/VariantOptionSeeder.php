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
            'name' => 'Color',
            'type' => 'color',
            'position' => 1,
        ]);

        $colors = [
            ['value' => ['en' => 'Gold', 'tr' => 'Altın'], 'position' => 1],
            ['value' => ['en' => 'Beige', 'tr' => 'Bej'], 'position' => 2],
            ['value' => ['en' => 'White', 'tr' => 'Beyaz'], 'position' => 3],
            ['value' => ['en' => 'Gray', 'tr' => 'Gri'], 'position' => 4],
            ['value' => ['en' => 'Silver', 'tr' => 'Gümüş'], 'position' => 5],
            ['value' => ['en' => 'Brown', 'tr' => 'Kahverengi'], 'position' => 6],
            ['value' => ['en' => 'Red', 'tr' => 'Kırmızı'], 'position' => 7],
            ['value' => ['en' => 'Navy Blue', 'tr' => 'Lacivert'], 'position' => 8],
            ['value' => ['en' => 'Blue', 'tr' => 'Mavi'], 'position' => 9],
            ['value' => ['en' => 'Metallic', 'tr' => 'Metalik'], 'position' => 10],
            ['value' => ['en' => 'Purple', 'tr' => 'Mor'], 'position' => 11],
            ['value' => ['en' => 'Pink', 'tr' => 'Pembe'], 'position' => 12],
            ['value' => ['en' => 'Yellow', 'tr' => 'Sarı'], 'position' => 13],
            ['value' => ['en' => 'Black', 'tr' => 'Siyah'], 'position' => 14],
            ['value' => ['en' => 'Turquoise', 'tr' => 'Turkuaz'], 'position' => 15],
            ['value' => ['en' => 'Orange', 'tr' => 'Turuncu'], 'position' => 16],
            ['value' => ['en' => 'Green', 'tr' => 'Yeşil'], 'position' => 17],
            ['value' => ['en' => 'Burgundy', 'tr' => 'Bordo'], 'position' => 18],
            ['value' => ['en' => 'Ecru', 'tr' => 'Ekru'], 'position' => 19],
            ['value' => ['en' => 'Khaki', 'tr' => 'Haki'], 'position' => 20],
            ['value' => ['en' => 'Cream', 'tr' => 'Krem'], 'position' => 21],
            ['value' => ['en' => 'Multicolor', 'tr' => 'Çok Renkli'], 'position' => 22],
        ];

        foreach ($colors as $color) {
            VariantOptionValue::create(array_merge($color, [
                'variant_option_id' => $colorOption->id,
            ]));
        }

        // Create Size option
        $sizeOption = VariantOption::create([
            'name' => 'Size',
            'type' => 'size',
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
