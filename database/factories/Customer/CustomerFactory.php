<?php

namespace Database\Factories\Customer;

use App\Models\Customer\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer\Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'channel' => fake()->randomElement(['trendyol', 'shopify']),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Indicate that the customer has masked data (***).
     */
    public function masked(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_name' => '***',
            'last_name' => '***',
            'email' => '***',
            'phone' => null,
        ]);
    }

    /**
     * Indicate that the customer is a placeholder from Trendyol.
     */
    public function placeholder(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_name' => 'Trendyol',
            'last_name' => 'Customer',
            'email' => null,
            'phone' => null,
            'notes' => 'Customer data pending from Trendyol',
        ]);
    }
}
