<?php

namespace Database\Factories\Order;

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use App\Models\Currency;
use App\Models\Customer\Customer;
use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $currency = Currency::first();

        if (! $currency) {
            throw new \RuntimeException('No currency found. Please create a currency in your test setup using Currency::create()');
        }

        return [
            'customer_id' => Customer::factory(),
            'channel' => fake()->randomElement(['trendyol', 'shopify']),
            'order_number' => fake()->unique()->numerify('ORD-########'),
            'order_status' => OrderStatus::PROCESSING,
            'payment_status' => PaymentStatus::PAID,
            'fulfillment_status' => FulfillmentStatus::UNFULFILLED,
            'currency_id' => $currency->id,
            'subtotal' => 10000, // 100.00 in cents
            'tax_amount' => 1800, // 18.00 in cents
            'shipping_amount' => 500, // 5.00 in cents
            'discount_amount' => 0,
            'total_amount' => 12300, // 123.00 in cents
            'order_date' => now(),
        ];
    }
}
