<?php

namespace App\Services\Integrations\InvoiceProviders\TrendyolEFatura;

use App\Enums\Invoice\DocumentType;
use App\Enums\Invoice\FileExtension;
use App\Enums\Invoice\InvoiceStatus;
use App\Enums\Order\PaymentMethod;
use App\Models\Customer\Customer;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Integrations\Contracts\InvoiceProviderAdapter;
use App\Services\Integrations\InvoiceProviders\TrendyolEFatura\Enums\InvoiceType;
use App\Services\Integrations\InvoiceProviders\TrendyolEFatura\Enums\InvoiceTypeCode;
use App\Services\Integrations\InvoiceProviders\TrendyolEFatura\Enums\PaymentMeans;
use App\Services\Integrations\InvoiceProviders\TrendyolEFatura\Enums\Source;
use App\Services\Integrations\InvoiceProviders\TrendyolEFatura\Enums\TaxType;
use App\Services\Integrations\InvoiceProviders\TrendyolEFatura\Enums\UnitCode;
use Cknow\Money\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrendyolEFaturaAdapter implements InvoiceProviderAdapter
{
    protected string $baseUrl;

    protected string $email;

    protected string $password;

    protected ?string $accessToken = null;

    protected ?string $refreshToken = null;

    public function __construct(
        protected Integration $integration
    ) {
        $this->email = $this->integration->settings['email'] ?? '';
        $this->password = $this->integration->settings['password'] ?? '';

        // Set base URL based on test mode
        $testMode = $this->integration->settings['test_mode'] ?? true;
        $this->baseUrl = $testMode
            ? 'https://stage-apigateway.trendyolefaturam.com/api'
            : 'https://apigateway.trendyolecozum.com/api';

        // Load tokens from integration settings if available
        $this->accessToken = $this->integration->settings['access_token'] ?? null;
        $this->refreshToken = $this->integration->settings['refresh_token'] ?? null;
    }

    protected function httpClient(): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl)
            ->asJson()
            ->acceptJson();

        if ($this->accessToken) {
            $client = $client->withToken($this->accessToken);
        }

        return $client;
    }

    /**
     * Authenticate with Trendyol E-Fatura API and store tokens
     */
    public function authenticate(): bool
    {
        try {
            $response = $this->httpClient()->post('/auth/signin', [
                'email' => $this->email,
                'password' => $this->password,
            ]);

            if (!$response->successful()) {
                throw new \Exception(
                    'Authentication failed: '.$response->body()
                );
            }

            // Extract tokens from response headers
            $this->accessToken = $response->header('x-access-token');
            $this->refreshToken = $response->header('x-refresh-token');

            if (!$this->accessToken || !$this->refreshToken) {
                throw new \Exception('Authentication response missing tokens');
            }

            // Get user info from response body
            $responseData = $response->json();

            // The response can be either:
            // 1. Just a number (userId): 63211
            // 2. An object with id and other fields: {"id": 63211, "companyId": 123456, ...}
            $userId = is_array($responseData) ? ($responseData['id'] ?? null) : $responseData;
            $companyId = is_array($responseData) ? ($responseData['companyId'] ?? null) : null;

            // Store tokens and IDs in integration settings
            $settingsUpdate = [
                'access_token' => $this->accessToken,
                'refresh_token' => $this->refreshToken,
                'token_expires_at' => now()->addHours(24)->toDateTimeString(),
            ];

            // Update user_id if we got it from the response
            if ($userId) {
                $settingsUpdate['user_id'] = $userId;
            }

            // Update company_id if we got it from the response
            if ($companyId) {
                $settingsUpdate['company_id'] = $companyId;
            }

            $this->integration->update([
                'settings' => array_merge($this->integration->settings, $settingsUpdate),
            ]);

            Log::info('Trendyol E-Fatura authentication successful', [
                'integration_id' => $this->integration->id,
                'user_id' => $userId,
                'company_id' => $companyId,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Trendyol E-Fatura authentication failed', [
                'error' => $e->getMessage(),
                'integration_id' => $this->integration->id,
            ]);

            return false;
        }
    }

    /**
     * Ensure we have a valid access token
     *
     * Note: Trendyol E-Fatura API doesn't have a documented token refresh endpoint.
     * If the token expires, we need to re-authenticate with email/password.
     */
    protected function ensureAuthenticated(): void
    {
        // If no access token, authenticate
        if (!$this->accessToken) {
            $this->authenticate();

            return;
        }

        // Check if token might be expired (we store when it was created, assume 24h validity)
        $expiresAt = $this->integration->settings['token_expires_at'] ?? null;
        if ($expiresAt && now()->gt($expiresAt)) {
            Log::info('Trendyol E-Fatura token may be expired, re-authenticating', [
                'integration_id' => $this->integration->id,
                'expires_at' => $expiresAt,
            ]);
            $this->authenticate();
        }
    }

    /**
     * Verify tax ID with Trendyol E-Fatura
     * https://developers.trendyolefaturam.com/OpenApi/M%C3%BCkellef%20Sorgulama/get-tax-payers-by-tax-id
     */
    public function verifyTaxId(string $taxId): array
    {
        try {
            $this->ensureAuthenticated();

            $response = $this->makeRequest('GET', "/taxpayer/{$taxId}");

            if (!$response->successful()) {
                throw new \Exception(
                    'Tax ID verification failed: '.$response->body()
                );
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Trendyol E-Fatura tax ID verification failed', [
                'error' => $e->getMessage(),
                'tax_id' => $taxId,
                'integration_id' => $this->integration->id,
            ]);

            throw $e;
        }
    }

    /**
     * Generate E-Archive invoice for an order
     * https://developers.trendyolefaturam.com/OpenApi/eAr%C5%9Fiv/create-e-archive
     */
    public function generateInvoice(Order $order): array|string
    {
        try {
            $this->ensureAuthenticated();

            $invoiceData = $this->prepareInvoiceData($order);

            // Log the request for debugging (debug level to avoid logging sensitive customer data in production)
            Log::debug('Trendyol E-Fatura invoice generation request', [
                'order_id' => $order->id,
                'payload' => $invoiceData,
            ]);

            $response = $this->httpClient()
                ->post('/invoice/documents/earchive', $invoiceData);

            // Log the response for debugging (debug level in production)
            Log::debug('Trendyol E-Fatura invoice generation response', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            if (!$response->successful()) {
                throw new \Exception(
                    'Failed to generate invoice: '.$response->body()
                );
            }

            $result = $response->json();

            // Return invoice data from Trendyol E-Fatura
            return [
                'invoice_id' => $result['invoiceId'] ?? '',
                'invoice_uuid' => $result['invoiceUuid'] ?? '',
                'invoice_number' => $result['invoiceId'] ?? '',
                'response' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('Trendyol E-Fatura invoice generation failed', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            throw $e;
        }
    }

    /**
     * Send invoice to customer via email/SMS
     */
    public function sendInvoice(string $invoiceId, Customer $customer): bool
    {
        try {
            $this->ensureAuthenticated();

            $response = $this->makeRequest('POST', "/e-archive/{$invoiceId}/send", [
                'email' => $customer->email,
                'phone' => $customer->phone,
                'send_via_email' => !empty($customer->email),
                'send_via_sms' => !empty($customer->phone),
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Trendyol E-Fatura invoice send failed', [
                'error' => $e->getMessage(),
                'invoice_id' => $invoiceId,
                'customer_id' => $customer->id,
            ]);

            return false;
        }
    }

    /**
     * Cancel E-Archive invoice
     * https://developers.trendyolefaturam.com/OpenApi/eAr%C5%9Fiv/cancel-e-archive
     */
    public function cancelInvoice(string $invoiceId): bool
    {
        try {
            $this->ensureAuthenticated();

            $companyId = $this->integration->settings['company_id'] ?? null;

            $response = $this->httpClient()->post('/invoice/documents/earchive/cancel', [
                'invoiceUuid' => $invoiceId,
                'companyId' => $companyId,
            ]);

            if (!$response->successful()) {
                throw new \Exception(
                    'Failed to cancel invoice: '.$response->body()
                );
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Trendyol E-Fatura invoice cancellation failed', [
                'error' => $e->getMessage(),
                'invoice_id' => $invoiceId,
            ]);

            return false;
        }
    }

    /**
     * Get invoice details
     */
    public function getInvoice(string $invoiceId): array
    {
        try {
            $this->ensureAuthenticated();

            $response = $this->makeRequest('GET', "/e-archive/{$invoiceId}");

            if (!$response->successful()) {
                throw new \Exception(
                    'Failed to get invoice: '.$response->body()
                );
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Trendyol E-Fatura get invoice failed', [
                'error' => $e->getMessage(),
                'invoice_id' => $invoiceId,
            ]);

            throw $e;
        }
    }

    /**
     * Prepare invoice data from order
     */
    protected function prepareInvoiceData(Order $order): array
    {
        $customer = $order->customer;
        $shippingAddress = $order->shippingAddress;

        // Get currency code - handle both string and relationship
        $currency = is_string($order->currency)
            ? $order->currency
            : ($order->currency?->code ?? 'TRY');

        // Determine if this is an export invoice
        $isExport = $shippingAddress && $shippingAddress->country?->iso2 !== 'TR';

        // Get user ID and company ID from integration settings
        $userId = $this->integration->settings['user_id'] ?? null;
        $companyId = $this->integration->settings['company_id'] ?? null;

        // Base invoice data
        $invoiceData = [
            'userId' => $userId,
            'companyId' => $companyId,
            'autoInvoiceId' => true,
            'targetAlias' => '', // Always include empty targetAlias
            'source' => Source::PARTNER->value,
            'issuedAt' => $order->order_date?->format('Y-m-d\TH:i'),
            'recipientInfo' => $this->buildRecipientInfo($order, $customer, $shippingAddress),
            'invoiceInfo' => $this->buildInvoiceInfo($isExport, $order),
            'bankInfo' => $this->buildBankInfo() ?? [], // Always include bankInfo (empty array if not configured)
            'invoiceLines' => $this->buildInvoiceLines($order, $isExport, $shippingAddress),
            'totalTax' => $this->buildTotalTax($order, $isExport),
            'invoiceTotal' => $this->buildInvoiceTotal($order),
        ];

        // Add delivery info if available
        if ($carrierInfo = $this->buildCarrierInfo($order)) {
            $invoiceData['deliveryInfo'] = $carrierInfo;
        }

        // Add payment info for online orders
        if ($order->payment_method) {
            $invoiceData['paymentInfo'] = $this->buildPaymentInfo($order);
        }

        // Always add currency info (Trendyol expects it)
        $invoiceData['currencyInfo'] = $this->buildCurrencyInfo($order, $currency);

        // Add prefix if configured (must be registered in Trendyol account)
        $prefix = $this->integration->settings['prefix'] ?? null;
        if ($prefix && strlen(trim($prefix)) === 3) {
            // Ensure uppercase and exactly 3 characters
            $invoiceData['prefix'] = strtoupper(trim($prefix));
        }

        return $invoiceData;
    }

    /**
     * Build recipient information
     */
    protected function buildRecipientInfo(Order $order, Customer $customer, $shippingAddress): array
    {
        $recipientInfo = [
            'countryCode' => $shippingAddress?->country?->iso2 ?? 'TR',
            'city' => $shippingAddress?->province?->name ?? 'Unknown',
            'district' => $shippingAddress?->district?->name ?? '',
            'address' => $shippingAddress?->address_line1 ?? '',
            'phone' => $customer->phone ?? '',
            'taxId' => $customer->tax_number ?? $customer->identity_number ?? '1111111111',
            'name' => $customer->full_name,
            'surname' => $customer->last_name,
        ];

        // Only add email if it's not empty (minimum 2 characters required by API)
        if ($customer->email && strlen($customer->email) >= 2) {
            $recipientInfo['email'] = $customer->email;
        }

        // Add surname if available
        if ($customer->last_name) {
            $recipientInfo['surname'] = $customer->last_name;
        }

        // Add postal code if available
        if ($shippingAddress?->postal_code) {
            $recipientInfo['postalCode'] = $shippingAddress->postal_code;
        }

        // Add tax office if customer is a company
        if ($customer->tax_number && $customer->tax_office) {
            $recipientInfo['taxOffice'] = $customer->tax_office;
        }

        return $recipientInfo;
    }

    /**
     * Build invoice info
     */
    protected function buildInvoiceInfo(bool $isExport, Order $order): array
    {
        if ($isExport) {
            return [
                'invoiceType' => InvoiceType::EARSIVFATURA->value,
                'invoiceTypeCode' => InvoiceTypeCode::ISTISNA->value,
            ];
        }

        // Check if this is a return/refund
        if ($order->hasReturns()) {
            return [
                'invoiceType' => InvoiceType::EARSIVFATURA->value,
                'invoiceTypeCode' => InvoiceTypeCode::IADE->value,
            ];
        }

        // Default: regular sale
        return [
            'invoiceType' => InvoiceType::EARSIVFATURA->value,
            'invoiceTypeCode' => InvoiceTypeCode::SATIS->value,
        ];
    }

    /**
     * Build invoice lines
     */
    protected function buildInvoiceLines(Order $order, bool $isExport, $shippingAddress): array
    {
        $lines = [];

        foreach ($order->items as $item) {
            $quantity = $item->quantity;

            // Extract amounts from Money objects (MoneyIntegerCast returns Money objects)
            $unitPrice = $this->extractMoneyAmount($item->unit_price);
            $totalPrice = $this->extractMoneyAmount($item->total_price);
            $taxAmount = $this->extractMoneyAmount($item->tax_amount);
            $discountAmount = $this->extractMoneyAmount($item->discount_amount);

            // In Turkish invoices, the amounts already include VAT
            // total_price = amount INCLUDING VAT
            // tax_amount = VAT amount
            // taxableAmount = amount BEFORE VAT (total_price - tax_amount)
            $taxableAmount = $totalPrice - $taxAmount; // Amount before VAT
            $totalAmount = $totalPrice; // Amount including VAT
            $totalDiscount = $discountAmount; // Discount amount

            // Tax calculation
            $taxPercent = $isExport ? 0 : ($item->tax_rate ?? 10);

            $line = [
                'unitCode' => UnitCode::ADET->value,
                'quantity' => $quantity,
                'totalAmount' => (int) $totalAmount, // In kuruş
                'taxAmount' => round($taxAmount / 100, 2), // In TRY (Trendyol expects this in decimal format)
                'kdvTaxAmount' => (int) $taxAmount, // In kuruş (this is the actual KDV tax amount)
                'taxableAmount' => (int) $taxableAmount, // In kuruş
                'taxPercent' => (float) $taxPercent,
                'itemName' => $item->name ?? $item->productVariant?->product?->name ?? 'Product',
                'unitPriceAmount' => (int) $unitPrice, // In kuruş
                'totalDiscountAmount' => (int) $totalDiscount, // In kuruş
            ];

            // Add origin code if available (important for exports)
            if ($item->productVariant?->product?->country_of_origin) {
                $line['originCode'] = $item->productVariant->product->country_of_origin;
            } elseif ($isExport) {
                $line['originCode'] = 'TR'; // Default to Turkey
            }

            // Add export info for export invoices
            if ($isExport && $shippingAddress) {
                $line['exportInfo'] = [
                    'delivery' => [
                        'city' => $shippingAddress->province?->name ?? '',
                        'country' => $shippingAddress->country?->iso2 ?? '',
                        'deliveryTypeCode' => 'DDP', // Can be configured
                    ],
                ];

                // Add GTIP/customs code if available
                if ($item->productVariant?->customs_code) {
                    $line['microExportRequiredCustomsId'] = $item->productVariant->customs_code;
                }
            }

            // Build total tax structure
            $line['totalTax'] = [
                'totalTaxAmount' => (int) $taxAmount,
                'subTotalTaxes' => [
                    [
                        'taxAmount' => (int) $taxAmount,
                        'taxableAmount' => (int) $taxableAmount,
                        'percent' => (float) $taxPercent,
                        'taxType' => TaxType::KDV->value,
                        'name' => 'KDV GERÇEK',
                    ],
                ],
            ];

            if ($isExport) {
                $line['totalTax']['subTotalTaxes'][0]['taxExemptionReason'] = '11/1-a Mal İhracatı';
                $line['totalTax']['subTotalTaxes'][0]['taxExemptionReasonCode'] = 301;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Build total tax structure
     */
    protected function buildTotalTax(Order $order, bool $isExport): array
    {
        // Extract amounts from Money objects (MoneyIntegerCast returns Money objects)
        $totalAmount = $this->extractMoneyAmount($order->total_amount);
        $taxAmount = $this->extractMoneyAmount($order->tax_amount);

        // In Turkish invoices, amounts already include VAT
        // taxableAmount = amount BEFORE VAT (total_amount - tax_amount)
        $taxableAmount = $totalAmount - $taxAmount;
        $totalTaxAmount = $isExport ? 0 : $taxAmount;

        // Get average tax rate from order items
        $taxPercent = $isExport ? 0 : ($order->items->first()?->tax_rate ?? 10);

        $subTax = [
            'taxableAmount' => (int) $taxableAmount,
            'taxAmount' => (int) $totalTaxAmount,
            'percent' => (float) $taxPercent,
            'taxType' => TaxType::KDV->value,
            'name' => 'KDV GERÇEK',
        ];

        if ($isExport) {
            $subTax['taxExemptionReason'] = '11/1-a Mal İhracatı';
            $subTax['taxExemptionReasonCode'] = 301;
        }

        return [
            'totalTaxAmount' => (int) $totalTaxAmount,
            'subTotalTaxes' => [$subTax],
        ];
    }

    /**
     * Build invoice total
     */
    protected function buildInvoiceTotal(Order $order): array
    {
        // Extract amounts from Money objects (MoneyIntegerCast returns Money objects)
        $taxAmount = $this->extractMoneyAmount($order->tax_amount);
        $discountAmount = $this->extractMoneyAmount($order->discount_amount);
        $totalAmount = $this->extractMoneyAmount($order->total_amount);

        // In Turkish e-archive invoices, customer has already paid the full amount including VAT
        // taxExclusiveAmount = amount BEFORE VAT (Vergiler Hariç Tutar) - for tax authority breakdown
        // taxInclusiveAmount = amount INCLUDING VAT (Vergiler Dahil Tutar) - what was charged
        // payableAmount = what customer already paid (Ödenecek Tutar) - same as taxInclusiveAmount
        $taxExclusiveAmount = $totalAmount - $taxAmount;

        return [
            'lineExtensionAmount' => (int) $taxExclusiveAmount,
            'taxExclusiveAmount' => (int) $taxExclusiveAmount,
            'taxInclusiveAmount' => (int) $totalAmount,
            'allowanceTotalAmount' => (int) $discountAmount,
            'payableAmount' => (int) $totalAmount,
        ];
    }

    /**
     * Build currency information
     */
    protected function buildCurrencyInfo(Order $order, string $currency): array
    {
        $isTRY = $currency === 'TRY';
        $exchangeRate = $order->exchange_rate ?? 1;

        return [
            'currency' => $currency,
            'hasExchange' => !$isTRY,
            'calculationRate' => $isTRY ? 0 : (int) round($exchangeRate * 100),
            'sourceCurrency' => $currency,
            'targetCurrency' => $isTRY ? 'TRY' : 'TRY',
            'exchangeDate' => $order->order_date?->format('Y-m-d\TH:i'),
        ];
    }

    /**
     * Build payment information for online orders
     */
    protected function buildPaymentInfo(Order $order): array
    {
        // Map payment gateway to payment means
        $paymentMeans = $this->mapPaymentMethodToMeans($order->payment_method);

        $paymentInfo = [
            'paymentMeans' => $paymentMeans->value,
            'paymentDate' => $order->order_date?->format('Y-m-d\TH:i'),
        ];

        // Add payment agent name if available
        if ($order->payment_gateway) {
            $paymentInfo['paymentAgentName'] = $order->payment_gateway;
        }

        // Add purchase URL if order is from an integration
        if ($order->integration) {
            $paymentInfo['purchaseUrl'] = $this->getPurchaseUrl($order);
        }

        // Auto-generate payment instruction note (required, max 255 chars)
        $paymentInfo['instructionNote'] = $this->generatePaymentInstructionNote($order);

        return $paymentInfo;
    }

    /**
     * Generate payment instruction note from order payment data
     */
    protected function generatePaymentInstructionNote(Order $order): string
    {
        $parts = [];

        // Add payment method
        if ($order->payment_method) {
            $parts[] = ucfirst(str_replace('_', ' ', $order->payment_method));
        }

        // Add payment gateway
        if ($order->payment_gateway) {
            $parts[] = 'via '.$order->payment_gateway;
        }

        // Add transaction ID if available
        if ($order->payment_transaction_id) {
            $parts[] = 'Transaction ID: '.$order->payment_transaction_id;
        }

        // Add order number as fallback
        if (empty($parts)) {
            $parts[] = 'Order: '.$order->order_number;
        }

        // Join parts and ensure max 255 characters
        $note = implode(' - ', $parts);

        return substr($note, 0, 255);
    }

    /**
     * Map payment method to Trendyol payment means
     */
    protected function mapPaymentMethodToMeans(string $paymentMethod): PaymentMeans
    {
        return match (strtolower($paymentMethod)) {
            'credit_card', 'card', 'credit card', PaymentMethod::ONLINE->value => PaymentMeans::CREDIT_CARD,
            'eft', 'bank_transfer', 'transfer', PaymentMethod::BANK_TRANSFER->value => PaymentMeans::EFT,
            'cash_on_delivery', 'cod', PaymentMethod::COD->value => PaymentMeans::ON_DELIVERY,
            'mediator', 'escrow' => PaymentMeans::MEDIATOR,
            default => PaymentMeans::OTHER,
        };
    }

    /**
     * Get purchase URL from order integration
     */
    protected function getPurchaseUrl(Order $order): string
    {
        // Try to get from integration settings
        $integration = $order->integration;
        if ($integration) {
            // For Shopify, use the shop domain
            if (isset($integration->settings['shop_domain'])) {
                return 'https://'.$integration->settings['shop_domain'];
            }
        }

        // Default fallback
        return config('app.url');
    }

    /**
     * Build carrier/shipping delivery info
     */
    protected function buildCarrierInfo(Order $order): ?array
    {
        // Get carrier info from the order's shipping aggregator integration
        if (!$order->shipping_aggregator_integration_id) {
            return null;
        }

        $shippingIntegration = Integration::find($order->shipping_aggregator_integration_id);
        if (!$shippingIntegration) {
            return null;
        }

        $carrierName = $shippingIntegration->settings['carrier_name'] ?? null;
        $carrierTaxId = $shippingIntegration->settings['carrier_tax_id'] ?? null;

        // Both carrierName and carrierTaxId are required by API
        if (!$carrierName || !$carrierTaxId) {
            return null;
        }

        $carrierInfo = [
            'carrierTaxId' => $carrierTaxId,
            'carrierName' => $carrierName,
        ];

        // Add shipping date if available (use shipped_at from order)
        if ($order->shipped_at) {
            $carrierInfo['sentAt'] = $order->shipped_at->format('Y-m-d');
        }

        return $carrierInfo;
    }

    /**
     * Extract integer amount from Money object or return default value
     */
    protected function extractMoneyAmount($value, int $default = 0): int
    {
        return $value instanceof Money ? $value->getAmount() : ($value ?? $default);
    }

    /**
     * Build billing reference for return/refund invoices
     */
    protected function buildBillingReference(Order $order): ?array
    {
        // Find the original invoice for this order
        $originalInvoice = $order->invoices()
            ->where('invoice_type', 'e-archive')
            ->where('status', InvoiceStatus::ISSUED)
            ->whereNull('cancelled_at')
            ->first();

        if (!$originalInvoice) {
            return null;
        }

        return [
            'invoiceId' => $originalInvoice->invoice_number,
            'issueDate' => $originalInvoice->issued_at?->format('Y-m-d'),
            'typeCode' => 'IADE',
        ];
    }

    /**
     * Build bank information from integration settings
     */
    protected function buildBankInfo(): ?array
    {
        $settings = $this->integration->settings;

        // Check if bank info is configured
        if (empty($settings['bank_accounts'])) {
            return null;
        }

        $bankAccounts = [];
        foreach ($settings['bank_accounts'] as $account) {
            $bankAccounts[] = [
                'bankName' => $account['bank_name'] ?? '',
                'bankBranchOfficeName' => $account['branch_name'] ?? '',
                'accountType' => $account['currency'] ?? 'TRY',
                'accountNumber' => $account['account_number'] ?? '',
            ];
        }

        return $bankAccounts;
    }

    /**
     * Get permanent download URL for invoice PDF or HTML
     */
    public function getPermanentUrl(
        string $invoiceUuid,
        DocumentType $documentType = DocumentType::EARCHIVE,
        FileExtension $fileExtension = FileExtension::PDF
    ): ?string {
        $companyId = $this->integration->settings['company_id'] ?? null;

        if (!$companyId) {
            throw new \Exception('Company ID is required to generate download URL. Please authenticate first.');
        }

        $payload = [
            'documentType' => $documentType->value,
            'fileExtension' => $fileExtension->value,
            'documentUuid' => $invoiceUuid,
            'invoiceUuid' => $invoiceUuid,
            'companyId' => (int) $companyId,
        ];

        Log::debug('Fetching permanent URL for invoice', $payload);

        try {
            $response = $this->httpClient()
                ->withHeaders(['Accept' => 'text/plain'])
                ->post('/invoice/documents/download/permanent-url', $payload);

            if (!$response->successful()) {
                Log::error('Failed to get permanent URL', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return null;
            }

            // Response is plain text URL
            $url = trim($response->body());

            Log::debug('Permanent URL fetched successfully', ['url' => $url]);

            return $url;
        } catch (\Exception $e) {
            Log::error('Exception while fetching permanent URL', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Make authenticated HTTP request to Trendyol E-Fatura API
     */
    protected function makeRequest(string $method, string $endpoint, array $data = [])
    {
        return $this->httpClient()->{strtolower($method)}($endpoint, $data);
    }
}
