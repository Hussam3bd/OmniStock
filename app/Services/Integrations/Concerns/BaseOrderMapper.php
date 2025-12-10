<?php

namespace App\Services\Integrations\Concerns;

use App\Enums\Order\OrderChannel;
use App\Helpers\CurrencyHelper;
use App\Models\Currency;
use App\Models\Customer\Customer;
use App\Models\Order\Order;
use App\Models\Platform\PlatformMapping;
use Illuminate\Support\Facades\DB;

abstract class BaseOrderMapper
{
    abstract public function mapOrder(array $data): ?Order;

    abstract protected function getChannel(): OrderChannel;

    protected function findOrCreateCustomer(array $customerData, ?string $externalCustomerId = null): Customer
    {
        if ($externalCustomerId) {
            $existingMapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('platform_id', (string) $externalCustomerId)
                ->where('entity_type', Customer::class)
                ->first();

            if ($existingMapping) {
                return $existingMapping->entity;
            }
        }

        $customer = Customer::create(array_merge($customerData, [
            'channel' => $this->getChannel(),
        ]));

        if ($externalCustomerId) {
            PlatformMapping::create([
                'platform' => $this->getChannel()->value,
                'entity_type' => Customer::class,
                'entity_id' => $customer->id,
                'platform_id' => (string) $externalCustomerId,
                'platform_data' => $customerData,
            ]);
        }

        return $customer;
    }

    protected function convertToMinorUnits(float $amount, string $currency = 'TRY'): int
    {
        // Convert to minor units (cents/kuruş)
        return (int) round($amount * 100);
    }

    protected function createOrUpdateOrder(array $orderData, ?string $externalOrderId = null): Order
    {
        if ($externalOrderId) {
            $existingMapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('platform_id', (string) $externalOrderId)
                ->where('entity_type', Order::class)
                ->first();

            if ($existingMapping) {
                // Update existing order
                $existingMapping->entity->update($orderData);

                return $existingMapping->entity;
            }
        }

        return DB::transaction(function () use ($orderData, $externalOrderId) {
            $order = Order::create($orderData);

            if ($externalOrderId) {
                PlatformMapping::create([
                    'platform' => $this->getChannel()->value,
                    'entity_type' => Order::class,
                    'entity_id' => $order->id,
                    'platform_id' => (string) $externalOrderId,
                ]);
            }

            return $order;
        });
    }

    /**
     * Resolve currency_id and exchange_rate from currency code
     * Returns array with 'currency_id' and 'exchange_rate' keys
     */
    protected function resolveCurrencyFields(string $currencyCode): array
    {
        // Find the currency by code
        $currency = Currency::where('code', strtoupper($currencyCode))->first();

        if (! $currency) {
            // Fallback to default currency if code not found
            $currency = Currency::getDefault();
        }

        // Get exchange rate from order currency to default currency
        $defaultCurrency = Currency::getDefault();
        $exchangeRate = 1.0; // Default to 1.0 if same currency or rate not found

        if ($currency->id !== $defaultCurrency?->id) {
            // Get current exchange rate
            $rate = CurrencyHelper::getRate($currency->code, $defaultCurrency->code);
            if ($rate) {
                $exchangeRate = $rate;
            }
        }

        return [
            'currency_id' => $currency->id,
            'exchange_rate' => $exchangeRate,
        ];
    }
}
