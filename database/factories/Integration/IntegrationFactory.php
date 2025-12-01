<?php

namespace Database\Factories\Integration;

use App\Models\Integration\Integration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Integration\Integration>
 */
class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Integration',
            'type' => 'sales_channel',
            'provider' => 'trendyol',
            'is_active' => true,
            'settings' => [
                'api_key' => fake()->uuid(),
                'api_secret' => fake()->uuid(),
                'supplier_id' => fake()->numerify('####'),
            ],
            'config' => [],
        ];
    }
}
