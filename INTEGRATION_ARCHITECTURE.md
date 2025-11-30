# Multi-Channel Integration Architecture Plan

## Overview
A comprehensive integration system for managing multiple sales channels, shipping providers, payment gateways, and invoice generation services with proper tracking of revenue, costs, and balances per integration.

## Core Concepts

### 1. Integration Types (Polymorphic Architecture)
- **Sales Channels**: Shopify, Trendyol
- **Shipping Providers**: Basit Kargo (Turkey only)
- **Payment Gateways**: Stripe, Iyzico
- **Invoice Providers**: Trendyol E-Fatura

### Specific Provider Information
- **Basit Kargo**: Turkish shipping aggregator - https://basitkargo.com/
  - API Documentation: https://basitkargo.com/api
  - Provides unified API for multiple Turkish carriers
  - Handles rate comparison, shipment creation, tracking

- **Trendyol E-Fatura**: Turkish invoice generation - https://trendyolefaturam.com/
  - Legal invoice generation for Turkish market
  - E-Fatura compliance
  - Integration with Trendyol marketplace

- **Iyzico**: Turkish payment gateway - https://dev.iyzipay.com/
  - Local payment methods (credit cards, installments)
  - Turkish Lira support
  - 3D Secure integration

### 2. Key Design Patterns

#### Adapter Pattern
- Create a common interface for each integration type
- Each provider (Shopify, Trendyol, etc.) implements an adapter
- Adapters translate between provider APIs and our internal format
- Benefits: Easy to add new providers, switchable providers, testable

#### Strategy Pattern
- Different sync strategies per channel (webhook vs polling)
- Different authentication strategies per provider
- Different data mapping strategies

#### Polymorphic Relationships
- One integration model that morphs into specific types
- Flexible configuration storage per type
- Unified tracking and reporting

## Database Architecture

### Core Tables

#### `integrations` (Simplified single table)
```
id
name (user-defined: "Main Shopify Store", "Backup Trendyol")
type (enum: sales_channel, shipping_provider, payment_gateway, invoice_provider)
provider (string: shopify, trendyol, basit_kargo, stripe, etc.)
is_active (boolean)
settings (json: API keys, credentials, endpoints)
config (json: type-specific configuration like auto_sync, test_mode, etc.)
location_id (nullable: for sales channels)
account_id (nullable: for revenue/payment tracking)
created_at
updated_at
```

**Note**: All configuration now stored in single table using JSON columns:
- `settings`: Provider-specific API credentials
- `config`: Business logic configuration (auto-sync, webhooks, etc.)
- Direct foreign keys for common relationships (location, account)

**Additional tables** (will be added in later phases):
- `integration_metrics`: Track revenue/costs per integration
- `webhook_logs`: Log incoming webhooks
- `sync_jobs`: Track synchronization jobs

## Class Structure

### Contracts (Interfaces)

```php
interface SalesChannelAdapter
{
    public function authenticate(): bool;
    public function fetchOrders(Carbon $since = null): Collection;
    public function fetchOrder(string $externalId): ?array;
    public function updateInventory(ProductVariant $variant): bool;
    public function fulfillOrder(Order $order, array $trackingInfo): bool;
    public function syncCustomer(Customer $customer): bool;
    public function registerWebhooks(): bool;
    public function verifyWebhook(Request $request): bool;
}

interface ShippingProviderAdapter
{
    public function authenticate(): bool;
    public function getRates(Order $order): Collection;
    public function createShipment(Order $order, array $options): array;
    public function trackShipment(string $trackingNumber): array;
    public function cancelShipment(string $shipmentId): bool;
    public function printLabel(string $shipmentId): string;
}

interface PaymentGatewayAdapter
{
    public function authenticate(): bool;
    public function createPaymentIntent(Money $amount, array $options): array;
    public function capturePayment(string $paymentId): bool;
    public function refundPayment(string $paymentId, Money $amount): bool;
    public function getTransaction(string $transactionId): array;
}

interface InvoiceProviderAdapter
{
    public function authenticate(): bool;
    public function generateInvoice(Order $order): string;
    public function sendInvoice(string $invoiceId, Customer $customer): bool;
    public function cancelInvoice(string $invoiceId): bool;
    public function getInvoice(string $invoiceId): array;
}
```

### Adapters (Implementations)

```
app/Services/Integrations/
├── SalesChannels/
│   ├── ShopifyAdapter.php
│   ├── TrendyolAdapter.php
│   └── ManualAdapter.php
├── ShippingProviders/
│   ├── BasitKargoAdapter.php (Primary - Turkish carriers)
│   ├── DHLAdapter.php
│   └── AramexAdapter.php
├── PaymentGateways/
│   ├── StripeAdapter.php
│   └── PayPalAdapter.php
└── InvoiceProviders/
    ├── TrendyolEFaturaAdapter.php (Primary - Turkish invoices)
    └── CustomAdapter.php
```

### Models

```
app/Models/Integration/
└── Integration.php (main model with settings/config JSON)
```

**Note**: Metric, webhook, and sync models will be added when needed in later phases.

## Workflow Examples

### Sales Channel Integration Flow

1. **Setup**
   - User creates integration: "Main Shopify Store"
   - Selects provider: Shopify
   - Enters API credentials
   - Selects location and bank account
   - Chooses linked shipping/payment/invoice providers
   - Configures auto-sync options

2. **Order Sync (Webhook)**
   - Shopify sends webhook: order.created
   - Webhook verified using HMAC
   - Logged in webhook_logs with event_id
   - Queued job processes webhook
   - ShopifyAdapter maps data to our Order model
   - Order created with:
     - channel = 'shopify'
     - Integration linked
     - Customer synced/created
     - Payment status updated
     - Fulfillment status set

3. **Revenue Tracking**
   - Daily cron job calculates metrics per integration
   - Stores in integration_metrics
   - Dashboard shows: revenue, orders, costs per channel

4. **Inventory Sync**
   - When stock changes locally
   - Job queued to update Shopify
   - ShopifyAdapter pushes inventory update
   - Logs sync result

### Shipping Integration Flow

1. **Order Fulfillment**
   - User fulfills order (or auto-fulfill)
   - System checks sales channel's shipping provider
   - BasitKargoAdapter.createShipment() called
   - Tracking number saved to order
   - Webhook sent back to sales channel

2. **Rate Shopping (Basit Kargo)**
   - BasitKargoAdapter.getRates() fetches rates from multiple Turkish carriers
   - Compare costs across Aras, MNG, Yurtiçi, etc.
   - User or system selects best rate
   - Single API call handles multiple providers

### Payment Integration Flow

1. **Order Payment**
   - Customer pays on Shopify
   - Webhook updates payment status
   - Transaction linked to configured account
   - Account balance updated
   - Integration metrics updated

### Invoice Integration Flow

1. **Auto-Invoice Generation (Trendyol E-Fatura)**
   - Order marked as paid
   - If channel has auto_generate_invoices
   - TrendyolEFaturaAdapter.generateInvoice()
   - Legal Turkish e-invoice generated
   - Invoice sent to customer
   - Invoice reference saved to order
   - Compliance with Turkish tax regulations

## Implementation Phases

### Phase 1: Foundation (Week 1) ✅
- [x] Create simplified database schema (single integrations table)
- [x] Create base models and relationships
- [x] Create adapter interfaces
- [x] Build Integration marketplace in Filament
- [x] Implement dynamic provider configuration

### Phase 2: Shopify Integration (Week 2)
- [ ] Shopify adapter implementation
- [ ] OAuth flow for Shopify
- [ ] Webhook handler and verification
- [ ] Order sync (pull and webhook)
- [ ] Customer sync
- [ ] Inventory push
- [ ] Order fulfillment

### Phase 3: Trendyol Integration (Week 3)
- [ ] Trendyol adapter implementation
- [ ] API authentication
- [ ] Order sync
- [ ] Product sync
- [ ] Inventory management

### Phase 4: Shipping & Payment (Week 4)
- [ ] Basit Kargo adapter (Primary)
  - [ ] Rate comparison across Turkish carriers
  - [ ] Shipment creation
  - [ ] Tracking integration
- [ ] DHL adapter (International)
- [ ] Stripe adapter
- [ ] Payment processing

### Phase 5: Invoicing & Metrics (Week 5)
- [ ] Trendyol E-Fatura integration
  - [ ] Turkish tax compliance
  - [ ] E-invoice generation
  - [ ] Customer invoice delivery
- [ ] Metrics calculation jobs
- [ ] Dashboard and reporting
- [ ] Reconciliation jobs

## Best Practices to Follow

### 1. Webhook Handling
- Respond within 2 seconds (queue processing)
- Verify HMAC signatures
- Detect duplicates using event_id
- Handle out-of-order webhooks with timestamps
- Implement reconciliation jobs (nightly sync)

### 2. API Rate Limiting
- Use queues with rate limiting
- Implement exponential backoff
- Cache frequently accessed data
- Batch operations where possible

### 3. Data Consistency
- Webhooks for real-time updates
- Scheduled jobs for reconciliation
- Transaction logging for debugging
- Idempotent operations

### 4. Security
- Encrypted credential storage
- Webhook signature verification
- API key rotation
- Audit logging

### 5. Monitoring
- Track sync success/failure rates
- Monitor webhook delivery
- Alert on integration failures
- Performance metrics per provider

## Revenue & Cost Tracking

### Per Integration Metrics
- Total orders
- Total revenue (in original currency + TRY)
- Shipping costs paid
- Payment gateway fees
- Refunds issued
- Net profit

### Dashboard Views
- Revenue by channel
- Top performing integrations
- Cost breakdown
- Profit margins
- Order volume trends

## Advantages of This Architecture

1. **Scalability**: Easy to add new providers
2. **Flexibility**: Each integration independently configurable
3. **Maintainability**: Adapters isolate provider-specific code
4. **Testability**: Mock adapters for testing
5. **Observability**: Comprehensive logging and metrics
6. **Reliability**: Webhook + polling ensures data consistency
7. **Multi-channel**: Native support for unlimited channels
8. **Financial Tracking**: Complete revenue/cost visibility

## Laravel Packages to Reduce Duplication

### Webhook Handling
- **spatie/laravel-webhook-client** - https://github.com/spatie/laravel-webhook-client
  - Receive and process webhooks from external services
  - Built-in signature verification
  - Automatic job queuing
  - Perfect for Shopify, Trendyol, payment gateway webhooks

- **spatie/laravel-webhook-server** - https://github.com/spatie/laravel-webhook-server
  - Send webhooks to external services
  - Retry failed deliveries
  - Sign outgoing webhooks
  - Useful for notifying external systems of inventory changes

### Activity Logging
- **spatie/laravel-activitylog** - https://spatie.be/docs/laravel-activitylog/v4/introduction
  - Track all integration activities
  - Log order syncs, inventory updates, API calls
  - Audit trail for debugging
  - Search and filter activity history
  - Perfect for tracking which integration made which change

### Usage Example
```php
// Receiving webhooks from Shopify
use Spatie\WebhookClient\WebhookConfig;

WebhookConfig::create()
    ->name('shopify')
    ->signingSecret(config('services.shopify.webhook_secret'))
    ->webhookProfile(ShopifyWebhookProfile::class)
    ->webhookModel(WebhookCall::class)
    ->processWebhookJob(ProcessShopifyWebhookJob::class);

// Logging integration activity
activity('integration')
    ->performedOn($order)
    ->causedBy($integration)
    ->withProperties(['channel' => 'shopify', 'external_id' => '12345'])
    ->log('Order synced from Shopify');
```

## Next Steps

1. ✅ Review and approve this architecture
2. ✅ Create database migrations
3. ✅ Implement base models and relationships
4. ✅ Build Filament resources for management
5. Install recommended Spatie packages
6. Start with Shopify adapter implementation
7. Add Trendyol sales channel
8. Implement Basit Kargo shipping integration
9. Add Trendyol E-Fatura invoice generation
