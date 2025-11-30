<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Customer\Customer;
use App\Models\Order\Order;

interface InvoiceProviderAdapter
{
    public function authenticate(): bool;

    public function generateInvoice(Order $order): string;

    public function sendInvoice(string $invoiceId, Customer $customer): bool;

    public function cancelInvoice(string $invoiceId): bool;

    public function getInvoice(string $invoiceId): array;
}
