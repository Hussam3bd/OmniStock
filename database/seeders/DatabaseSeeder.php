<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed currencies
        $this->call(CurrencySeeder::class);

        // Seed variant options (size, color, etc.)
        $this->call(VariantOptionSeeder::class);

        // Seed accounts
        $this->call(AccountSeeder::class);

        // Seed Trendyol attribute mappings
        $this->call(TrendyolAttributeMappingSeeder::class);

        // Create test user
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
