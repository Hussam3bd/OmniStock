<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Customer\Customer;
use App\Models\Order\Order;
use App\Models\Product\ProductVariant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

interface SalesChannelAdapter
{
    public function authenticate(): bool;

    public function fetchOrders(?Carbon $since = null): Collection;

    public function fetchOrder(string $externalId): ?array;

    public function updateInventory(ProductVariant $variant): bool;

    public function fulfillOrder(Order $order, array $trackingInfo): bool;

    public function syncCustomer(Customer $customer): bool;

    public function registerWebhooks(): bool;

    public function verifyWebhook(Request $request): bool;
}
