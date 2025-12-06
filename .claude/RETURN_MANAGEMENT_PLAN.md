# Return Management System - Implementation Plan

## Overview
This document outlines the complete implementation plan for the return management system across all sales channels (Shopify, Trendyol, future channels).

## Table of Contents
- [Current State Analysis](#current-state-analysis)
- [Proposed Workflow](#proposed-workflow)
- [Technical Architecture](#technical-architecture)
- [Database Schema](#database-schema)
- [Implementation Phases](#implementation-phases)
- [Action Pattern Design](#action-pattern-design)

---

## Current State Analysis

### ✅ What's Already Built

#### 1. OrderReturn Model
**Location:** `app/Models/Order/OrderReturn.php`

Complete model with all necessary fields:
- **Status Tracking:** `requested_at`, `approved_at`, `label_generated_at`, `shipped_at`, `received_at`, `inspected_at`, `completed_at`, `rejected_at`
- **User Tracking:** `approved_by`, `rejected_by`, `inspected_by`
- **Shipping Fields:** `return_tracking_number`, `return_label_url`, `return_shipping_cost_excluding_vat`, `return_shipping_desi`, `return_shipping_vat_rate`, `return_shipping_vat_amount`, `return_shipping_rate_id`
- **Financial Fields:** `total_refund_amount`, `restocking_fee`, `original_shipping_cost`
- **Business Methods:** `approve()`, `reject()`, `markAsReceived()`, `startInspection()`, `complete()`

#### 2. ReturnStatus Enum
**Location:** `app/Enums/Order/ReturnStatus.php`

Perfect workflow states:
```
Requested → PendingReview → Approved → LabelGenerated → InTransit → Received → Inspecting → Completed
                                                                                               ↓
                                                                                          Rejected/Cancelled
```

#### 3. Trendyol Claims Integration
**Location:** `app/Services/Integrations/SalesChannels/Trendyol/Mappers/ClaimsMapper.php`

- ✅ Syncs claims from Trendyol API
- ✅ Auto-creates OrderReturn records
- ✅ Maps claim status to ReturnStatus
- ✅ Calculates shipping costs from original order

#### 4. BasitKargo Adapter
**Location:** `app/Services/Integrations/ShippingProviders/BasitKargo/BasitKargoAdapter.php`

Partially implemented:
- ✅ `getRates()` - Get shipping costs by desi
- ✅ `trackShipment()` - Track shipments by tracking number
- ✅ `getShipmentCost()` - Get cost breakdown from tracking number
- ✅ `filterOrders()` - Filter shipments by date/status
- ❌ `createShipment()` - NOT IMPLEMENTED
- ❌ `printLabel()` - NOT IMPLEMENTED
- ❌ `createReturnShipment()` - NOT IMPLEMENTED

### ❌ What's Missing

1. **Shopify Return Requests Sync**
   - Return requests visible in Shopify dashboard not appearing in our system
   - Need to implement Shopify Returns API integration
   - Endpoint: `/admin/api/2024-10/returns.json`

2. **Return Shipping Label Generation**
   - No implementation for creating return shipments via BasitKargo
   - Need to research BasitKargo return shipment API
   - Need to store and display label PDFs to customers

3. **Shipping Aggregator Tracking**
   - No field to track which shipping aggregator integration was used
   - No field to store external shipment ID from aggregator
   - Critical for multi-integration support and shipment fetching

4. **Webhook Listeners**
   - No BasitKargo webhook handler
   - Cannot auto-update return status when shipment is delivered
   - Need to listen for status changes on return shipments

5. **Dashboard Actions**
   - No Filament actions for return workflow
   - Staff cannot approve/reject returns from dashboard
   - No inspection workflow UI
   - No restock functionality

6. **Action Pattern Architecture**
   - Business logic mixed in model methods
   - No reusable action classes
   - Difficult to maintain and extend

---

## Proposed Workflow

### For Shopify Returns

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Customer Requests Return on Shopify                      │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│ 2. Webhook/Sync Creates OrderReturn                         │
│    - Status: Requested                                       │
│    - Fetched from Shopify Returns API                       │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│ 3. Admin Reviews in Dashboard                               │
│    → View return reason, customer note, items               │
│    → Decision: Approve or Reject                            │
└─────────────────────────────────────────────────────────────┘
          ↓                                    ↓
    [APPROVE]                             [REJECT]
          ↓                                    ↓
┌──────────────────────────────┐    ┌────────────────────────┐
│ 4a. Generate Return Label     │    │ 4b. Reject Return      │
│    - BasitKargo API call      │    │    - Update status     │
│    - Create return shipment   │    │    - Notify customer   │
│    - Get label PDF            │    │    - Log reason        │
│    - Store tracking number    │    └────────────────────────┘
│    - Status: LabelGenerated   │
└──────────────────────────────┘
          ↓
┌──────────────────────────────┐
│ 5. Send Label to Customer     │
│    - Upload to Shopify        │
│    - Or email directly        │
└──────────────────────────────┘
          ↓
┌──────────────────────────────┐
│ 6. Customer Ships Product     │
│    - Status: InTransit        │
│    - Tracked via BasitKargo   │
└──────────────────────────────┘
          ↓
┌──────────────────────────────┐
│ 7. BasitKargo Webhook         │
│    - Shipment delivered       │
│    - Status: Received (auto)  │
└──────────────────────────────┘
          ↓
┌──────────────────────────────┐
│ 8. Dashboard: "Needs Review"  │
│    - Default filter           │
│    - Shows received returns   │
└──────────────────────────────┘
          ↓
┌──────────────────────────────┐
│ 9. Staff Inspects Physical    │
│    Product → Approve/Reject   │
└──────────────────────────────┘
          ↓                    ↓
   [APPROVE & RESTOCK]    [REJECT - DAMAGED]
          ↓                    ↓
┌──────────────────────────────┐    ┌────────────────────────┐
│ 10a. Complete Return          │    │ 10b. Reject After      │
│     - Update inventory in DB  │    │      Inspection        │
│     - Push to Shopify         │    │     - No restock       │
│     - Push to Trendyol        │    │     - Status: Rejected │
│     - Status: Completed       │    └────────────────────────┘
└──────────────────────────────┘
```

### For Trendyol Claims

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Customer Creates Claim on Trendyol                       │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│ 2. Sync Creates OrderReturn                                 │
│    - Status: PendingReview                                   │
│    - Trendyol auto-approves (most cases)                    │
│    - Trendyol auto-generates label                          │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│ 3. (Skip Manual Approval Step)                              │
│    - Already approved by Trendyol                           │
│    - Label already provided by Trendyol                     │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│ 4. Customer Ships → Trendyol Provides Tracking             │
│    - Status: InTransit                                       │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│ 5. Listen for Delivery via Trendyol/BasitKargo             │
│    - Status: Received (auto)                                 │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│ 6-10. Same Inspection Workflow as Shopify                   │
│       (Steps 8-10 from Shopify workflow)                    │
└─────────────────────────────────────────────────────────────┘
```

---

## Technical Architecture

### Database Schema Changes

#### 1. Add Shipping Aggregator Tracking to Orders Table

**Migration:** `add_shipping_aggregator_to_orders_table`

```php
Schema::table('orders', function (Blueprint $table) {
    // Which shipping aggregator integration was used
    $table->foreignId('shipping_aggregator_integration_id')
        ->nullable()
        ->after('shipping_tracking_url')
        ->constrained('integrations')
        ->nullOnDelete();

    // External shipment ID from the aggregator
    $table->string('shipping_aggregator_shipment_id')
        ->nullable()
        ->after('shipping_aggregator_integration_id')
        ->index();

    // Platform data from aggregator (for reference)
    $table->json('shipping_aggregator_data')
        ->nullable()
        ->after('shipping_aggregator_shipment_id');
});
```

**Benefits:**
- Track which BasitKargo integration (if multiple) was used
- Store external shipment ID for future API calls
- Enable fetching shipment details from aggregator
- Support multiple shipping aggregator integrations

#### 2. Add Shipping Aggregator Tracking to Returns Table

**Migration:** `add_shipping_aggregator_to_returns_table`

```php
Schema::table('returns', function (Blueprint $table) {
    // Which shipping aggregator integration was used for return
    $table->foreignId('return_shipping_aggregator_integration_id')
        ->nullable()
        ->after('return_tracking_url')
        ->constrained('integrations')
        ->nullOnDelete();

    // External return shipment ID from the aggregator
    $table->string('return_shipping_aggregator_shipment_id')
        ->nullable()
        ->after('return_shipping_aggregator_integration_id')
        ->index();

    // Platform data from aggregator for return shipment
    $table->json('return_shipping_aggregator_data')
        ->nullable()
        ->after('return_shipping_aggregator_shipment_id');
});
```

**Benefits:**
- Track return shipment separately from outbound shipment
- Support different aggregators for outbound vs return
- Enable webhook matching by external shipment ID
- Store complete return shipment data for auditing

#### 3. Update Order Model

**Location:** `app/Models/Order/Order.php`

```php
// Add relationships
public function shippingAggregatorIntegration(): BelongsTo
{
    return $this->belongsTo(Integration::class, 'shipping_aggregator_integration_id');
}

// Add fillable fields
protected $fillable = [
    // ... existing fields
    'shipping_aggregator_integration_id',
    'shipping_aggregator_shipment_id',
    'shipping_aggregator_data',
];

// Add casts
protected function casts(): array
{
    return [
        // ... existing casts
        'shipping_aggregator_data' => 'array',
    ];
}
```

#### 4. Update OrderReturn Model

**Location:** `app/Models/Order/OrderReturn.php`

```php
// Add relationships
public function returnShippingAggregatorIntegration(): BelongsTo
{
    return $this->belongsTo(Integration::class, 'return_shipping_aggregator_integration_id');
}

// Add fillable fields
protected $fillable = [
    // ... existing fields
    'return_shipping_aggregator_integration_id',
    'return_shipping_aggregator_shipment_id',
    'return_shipping_aggregator_data',
];

// Add casts
protected function casts(): array
{
    return [
        // ... existing casts
        'return_shipping_aggregator_data' => 'array',
    ];
}
```

---

## Implementation Phases

### Phase 1: Shopify Return Requests Sync
**Priority:** HIGH - Shows missing returns immediately

**Files to create:**
1. `app/Services/Integrations/SalesChannels/Shopify/ShopifyAdapter.php`
   - Add `fetchReturns()` method
   - Add `fetchReturn($returnId)` method

2. `app/Services/Integrations/SalesChannels/Shopify/Mappers/ReturnRequestsMapper.php`
   - Map Shopify return requests to OrderReturn model
   - Handle return items mapping
   - Calculate refund amounts

3. `app/Jobs/SyncShopifyReturns.php`
   - Background job to sync individual return
   - Batchable for bulk syncing

**Files to modify:**
1. `app/Filament/Resources/Integration/Integrations/Tables/IntegrationsTable.php`
   - Add "Sync Returns" action for Shopify integrations

**Estimated Time:** 4-6 hours

---

### Phase 2: Database Schema Updates
**Priority:** HIGH - Required for all future phases

**Files to create:**
1. `database/migrations/YYYY_MM_DD_add_shipping_aggregator_to_orders_table.php`
2. `database/migrations/YYYY_MM_DD_add_shipping_aggregator_to_returns_table.php`

**Files to modify:**
1. `app/Models/Order/Order.php` - Add relationships and fillable fields
2. `app/Models/Order/OrderReturn.php` - Add relationships and fillable fields

**Estimated Time:** 1-2 hours

---

### Phase 3: BasitKargo Return Shipping
**Priority:** HIGH - Core functionality

**Files to create:**
1. `app/Services/Integrations/ShippingProviders/BasitKargo/DTOs/ReturnShipmentRequest.php`
2. `app/Services/Integrations/ShippingProviders/BasitKargo/DTOs/ReturnShipmentResponse.php`
3. `app/Services/Integrations/ShippingProviders/BasitKargo/DTOs/LabelResponse.php`

**Files to modify:**
1. `BasitKargoAdapter.php`
   - Implement `createReturnShipment(OrderReturn $return): ReturnShipmentResponse`
   - Implement `getReturnLabel(string $shipmentId): LabelResponse`
   - Research BasitKargo API for exact endpoints

**Estimated Time:** 6-8 hours (includes API research)

---

### Phase 4: Action Pattern (Reusable Business Logic)
**Priority:** HIGH - Clean architecture foundation

**Directory structure:**
```
app/Actions/Returns/
├── ApproveReturnAction.php              # Approve + generate label
├── RejectReturnAction.php               # Reject return request
├── GenerateReturnLabelAction.php        # BasitKargo label generation
├── UploadLabelToShopifyAction.php       # Upload label to Shopify return
├── SendLabelToCustomerAction.php        # Email or SMS label to customer
├── MarkReturnAsInTransitAction.php      # Update status when shipped
├── MarkReturnAsReceivedAction.php       # Mark as received
├── StartReturnInspectionAction.php      # Start inspection workflow
├── CompleteReturnWithRestockAction.php  # Approve inspection + restock
├── RestockInventoryAction.php           # Update inventory in database
├── SyncInventoryToChannelAction.php     # Push to Shopify/Trendyol
└── CalculateReturnCostsAction.php       # Calculate total loss/costs
```

**Action Pattern Example:**

```php
<?php

namespace App\Actions\Returns;

use App\Enums\Order\ReturnStatus;
use App\Models\Order\OrderReturn;
use App\Models\User;

class ApproveReturnAction
{
    public function __construct(
        protected GenerateReturnLabelAction $generateLabel,
        protected UploadLabelToShopifyAction $uploadToShopify,
        protected SendLabelToCustomerAction $sendToCustomer
    ) {}

    public function execute(OrderReturn $return, User $user, array $options = []): OrderReturn
    {
        // 1. Validate
        if (!$return->canApprove()) {
            throw new \Exception('Cannot approve this return. Current status: ' . $return->status->value);
        }

        // 2. Generate label via BasitKargo
        $label = $this->generateLabel->execute($return);

        // 3. Update return record
        $return->update([
            'status' => ReturnStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $user->id,
            'return_label_url' => $label->url,
            'return_tracking_number' => $label->trackingNumber,
            'return_shipping_aggregator_integration_id' => $label->integrationId,
            'return_shipping_aggregator_shipment_id' => $label->shipmentId,
            'return_shipping_aggregator_data' => $label->rawData,
        ]);

        // 4. Upload to channel if Shopify
        if ($return->channel->value === 'shopify' && ($options['upload_to_shopify'] ?? true)) {
            $this->uploadToShopify->execute($return, $label);
        }

        // 5. Send to customer if requested
        if ($options['send_to_customer'] ?? false) {
            $this->sendToCustomer->execute($return, $label, $options['send_method'] ?? 'email');
        }

        // 6. Log activity
        activity()
            ->performedOn($return)
            ->causedBy($user)
            ->withProperties([
                'tracking_number' => $label->trackingNumber,
                'label_url' => $label->url,
            ])
            ->log('return_approved');

        return $return->fresh();
    }
}
```

**Estimated Time:** 10-12 hours

---

### Phase 5: Webhook Listeners
**Priority:** MEDIUM - Enables automation

**Files to create:**
1. `app/Http/Controllers/Webhooks/BasitKargoWebhookController.php`
2. `app/Jobs/ProcessBasitKargoWebhook.php`
3. `app/Listeners/ReturnShipmentDeliveredListener.php`
4. `app/Listeners/ReturnShipmentStatusChangedListener.php`

**Files to modify:**
1. `routes/api.php` - Add webhook route
2. `config/webhook-client.php` - Add BasitKargo webhook config

**Webhook Flow:**
```
BasitKargo sends webhook
    ↓
BasitKargoWebhookController receives
    ↓
ProcessBasitKargoWebhook job dispatched
    ↓
Job parses webhook payload
    ↓
Finds OrderReturn by return_shipping_aggregator_shipment_id
    ↓
Updates return status based on shipment status
    ↓
Fires ReturnShipmentDeliveredListener (if delivered)
    ↓
Listener marks return as Received
```

**Estimated Time:** 4-6 hours

---

### Phase 6: Dashboard UI & Actions
**Priority:** MEDIUM - User interface

**Files to create:**
1. `app/Filament/Resources/Order/Returns/Tables/ReturnsTable.php`
2. `app/Filament/Resources/Order/Returns/Pages/ListReturns.php`
3. `app/Filament/Resources/Order/Returns/Pages/ViewReturn.php`
4. `app/Filament/Resources/Order/Returns/Pages/EditReturn.php`
5. `app/Filament/Resources/Order/Returns/ReturnsResource.php`
6. `app/Filament/Actions/Returns/ApproveReturnAction.php` (Filament action, not business logic)
7. `app/Filament/Actions/Returns/RejectReturnAction.php`
8. `app/Filament/Actions/Returns/InspectReturnAction.php`
9. `app/Filament/Actions/Returns/CompleteReturnAction.php`

**Dashboard Features:**
- **List View:**
  - Default filter: "Needs Review" (Received + Inspecting statuses)
  - Bulk actions: Mark as Received, Start Inspection
  - Quick filters: By status, channel, date range
  - Search: Return number, tracking number, customer

- **View Return:**
  - Return details, timeline, items
  - Customer information, shipping addresses
  - Label download, tracking link
  - Cost breakdown (shipping, restocking, refund)
  - Actions: Approve, Reject, Inspect, Complete, Print Label

- **Inspection Modal:**
  - Upload inspection photos
  - Item condition checklist
  - Approve/Reject each item
  - Restocking options (Shopify, Trendyol, both, neither)
  - Calculate final refund amount

**Estimated Time:** 12-15 hours

---

### Phase 7: Inventory Restocking
**Priority:** MEDIUM - Completes the workflow

**Files to create:**
1. `app/Actions/Returns/RestockInventoryAction.php`
2. `app/Actions/Returns/SyncInventoryToShopifyAction.php`
3. `app/Actions/Returns/SyncInventoryToTrendyolAction.php`

**Files to modify:**
1. `app/Services/Integrations/SalesChannels/Shopify/ShopifyAdapter.php`
   - Add `updateInventory(ProductVariant $variant, int $quantity)`

2. `app/Services/Integrations/SalesChannels/Trendyol/TrendyolAdapter.php`
   - Already has `updateInventory()` method

**Restock Workflow:**
```
Staff approves inspection
    ↓
CompleteReturnWithRestockAction executed
    ↓
RestockInventoryAction updates variant quantity in DB
    ↓
If "Sync to Shopify" checked:
    SyncInventoryToShopifyAction pushes inventory
    ↓
If "Sync to Trendyol" checked:
    SyncInventoryToTrendyolAction pushes inventory
    ↓
Return status → Completed
Order return_status updated
```

**Estimated Time:** 6-8 hours

---

## Action Pattern Design

### Principles
1. **Single Responsibility** - Each action does one thing well
2. **Dependency Injection** - Actions compose other actions
3. **Reusability** - Actions can be called from anywhere (jobs, commands, controllers, Filament)
4. **Testability** - Easy to unit test without framework dependencies
5. **Separation** - Business logic separate from presentation (Filament actions just call business actions)

### Pattern Structure

```php
<?php

namespace App\Actions\Returns;

use App\Models\Order\OrderReturn;

abstract class BaseReturnAction
{
    /**
     * Execute the action
     */
    abstract public function execute(OrderReturn $return, ...$params);

    /**
     * Validate before execution
     */
    protected function validate(OrderReturn $return): void
    {
        // Override in child classes
    }

    /**
     * Log the action
     */
    protected function logActivity(OrderReturn $return, string $event, array $properties = []): void
    {
        activity()
            ->performedOn($return)
            ->withProperties($properties)
            ->log($event);
    }
}
```

### Usage Examples

**From Filament Action:**
```php
use App\Actions\Returns\ApproveReturnAction;

Action::make('approve')
    ->action(function (OrderReturn $record) {
        $action = app(ApproveReturnAction::class);
        $action->execute($record, auth()->user(), [
            'upload_to_shopify' => true,
            'send_to_customer' => true,
        ]);

        Notification::make()
            ->success()
            ->title('Return approved')
            ->send();
    });
```

**From API Controller:**
```php
public function approve(Request $request, OrderReturn $return)
{
    $action = app(ApproveReturnAction::class);
    $action->execute($return, $request->user(), $request->all());

    return response()->json(['message' => 'Return approved']);
}
```

**From Job:**
```php
public function handle()
{
    $action = app(MarkReturnAsReceivedAction::class);
    $action->execute($this->return, $this->triggeredBy);
}
```

---

## Total Estimated Time

| Phase | Hours | Priority |
|-------|-------|----------|
| Phase 1: Shopify Returns Sync | 4-6 | HIGH |
| Phase 2: Database Schema | 1-2 | HIGH |
| Phase 3: BasitKargo Integration | 6-8 | HIGH |
| Phase 4: Action Pattern | 10-12 | HIGH |
| Phase 5: Webhook Listeners | 4-6 | MEDIUM |
| Phase 6: Dashboard UI | 12-15 | MEDIUM |
| Phase 7: Inventory Restocking | 6-8 | MEDIUM |
| **TOTAL** | **43-57 hours** | |

---

## Future Considerations

### Multi-Aggregator Support
When adding new shipping aggregators (e.g., Aras Kargo, MNG Kargo):
1. Implement `ShippingProviderAdapter` interface
2. Create adapter in `app/Services/Integrations/ShippingProviders/{Provider}/`
3. Business logic (Actions) remains unchanged
4. Webhook controller routes new webhooks to appropriate processor

### Multi-Channel Support
When adding new sales channels (e.g., Amazon, eBay):
1. Implement channel adapter in `app/Services/Integrations/SalesChannels/{Channel}/`
2. Create ReturnsMapper for channel
3. Business logic (Actions) remains unchanged
4. Add channel-specific return sync job

### Advanced Features (Future)
- Automatic return approval based on rules
- Customer self-service return portal
- Return analytics dashboard
- Predictive return rate analysis
- Automated fraud detection
- Multi-language return reasons
- Return shipping cost allocation (who pays)

---

## Notes for Implementation

1. **Always use Actions for business logic** - Never put complex logic in controllers, models, or Filament actions
2. **Test each phase independently** - Don't move to next phase until current is working
3. **Document as you go** - Update this plan with actual implementation details
4. **Use feature flags** - Enable/disable features per integration
5. **Monitor performance** - Log slow operations, optimize later
6. **Security first** - Validate all inputs, authorize all actions
7. **Think multi-tenant** - Even if single tenant now, design for future

---

Last Updated: 2025-12-06
