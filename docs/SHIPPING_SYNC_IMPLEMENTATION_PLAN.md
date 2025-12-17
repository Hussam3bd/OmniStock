# Shipping Sync & Auto-Return Implementation Plan

## Overview
This document outlines the complete plan for implementing unified shipping cost synchronization and automatic return creation for all returned shipments across all channels (Shopify, Trendyol, BasitKargo webhooks).

## Problem Statement

### Current Issues
1. **`shipping_amount` overwrite**: Customer-charged shipping fees (including markup) are overwritten with actual carrier costs during resync
2. **Missing auto-return creation**: When BasitKargo reports shipment as RETURNED, no OrderReturn is automatically created for non-COD orders
3. **Wrong desi value**: Using original `desi` instead of updated `raw_data.shipmentInfo.handlerDesi` from carrier
4. **Duplicate logic**: Multiple code paths (webhook, manual resync, command) have similar but different logic
5. **Inconsistent return reasons**: Each channel (Trendyol, Shopify, BasitKargo) uses different reason codes and text

### User Requirements
- Preserve `shipping_amount` (what customer was charged)
- Auto-create returns for ALL returned shipments (not just COD)
- Use correct desi value from carrier
- Unified service used by all entry points
- Idempotent operations (don't recreate returns if already exist)
- Unified return reason system across all channels

## Architecture Decisions

### 1. Order Status Handling
- **COD orders that are returned** → `order_status = REJECTED` (customer rejected delivery)
- **Non-COD orders that are returned** → `order_status = RETURNED` (post-delivery return)
- Add new `OrderStatus::RETURNED` case to enum

### 2. Auto-Return Creation Logic
| Scenario | Status | Reason | Notes |
|----------|--------|--------|-------|
| COD + RETURNED | `PendingReview` | "Customer did not accept COD delivery" | Requires approval |
| Non-COD + RETURNED | `Received` | "Shipment returned by carrier" | Auto-received |

### 3. Return Reason Enum
Create `App\Enums\Order\ReturnReason` enum with:
- Common reasons from Trendyol, Shopify, and BasitKargo
- Smart mapping method `fromText(string $text): ?ReturnReason`
- Supports fuzzy matching for variations

### 4. Column Usage
| Column | Purpose | When Updated |
|--------|---------|--------------|
| `shipping_amount` | Customer-charged amount (with markup) | **NEVER** during resync |
| `shipping_cost_excluding_vat` | Actual carrier cost (no VAT) | During resync |
| `shipping_vat_amount` | VAT portion of carrier cost | During resync |
| `shipping_desi` | Carrier-confirmed desi | During resync (from `handlerDesi`) |
| `shipping_carrier` | Carrier used | During resync |
| `return_status` | 'none', 'partial', 'full' | Auto-calculated by OrderReturn model |
| `order_status` | Order state | Updated during return creation |

### 5. Idempotency Strategy
- Check for existing returns before creating new ones
- Use `firstOrCreate()` or `updateOrCreate()` patterns
- Skip processing if return already exists for order

## Implementation Plan

### Phase 1: Foundation
#### 1.1 Create Return Reason Enum
**File**: `app/Enums/Order/ReturnReason.php`

```php
enum ReturnReason: string
{
    // Customer-initiated reasons
    case CHANGED_MIND = 'changed_mind';
    case WRONG_SIZE = 'wrong_size';
    case WRONG_COLOR = 'wrong_color';
    case DEFECTIVE = 'defective';
    case NOT_AS_DESCRIBED = 'not_as_described';
    case ARRIVED_LATE = 'arrived_late';
    case DAMAGED_IN_TRANSIT = 'damaged_in_transit';

    // Delivery-related reasons
    case COD_REJECTED = 'cod_rejected';
    case REFUSED_DELIVERY = 'refused_delivery';
    case ADDRESS_UNREACHABLE = 'address_unreachable';
    case CUSTOMER_NOT_AVAILABLE = 'customer_not_available';

    // Carrier/System reasons
    case RETURNED_BY_CARRIER = 'returned_by_carrier';
    case UNDELIVERABLE = 'undeliverable';
    case LOST_IN_TRANSIT = 'lost_in_transit';

    // Other
    case OTHER = 'other';

    public static function fromText(string $text): ?self
    {
        // Smart fuzzy matching logic
        $text = strtolower(trim($text));

        // COD patterns
        if (str_contains($text, 'cod') || str_contains($text, 'cash on delivery') ||
            str_contains($text, 'kabul etmedi') || str_contains($text, 'teslim alamadı')) {
            return self::COD_REJECTED;
        }

        // Size patterns
        if (str_contains($text, 'size') || str_contains($text, 'beden')) {
            return self::WRONG_SIZE;
        }

        // Defective patterns
        if (str_contains($text, 'defect') || str_contains($text, 'broken') ||
            str_contains($text, 'arızalı') || str_contains($text, 'hasarlı')) {
            return self::DEFECTIVE;
        }

        // Changed mind patterns
        if (str_contains($text, 'changed mind') || str_contains($text, 'fikir değiştir')) {
            return self::CHANGED_MIND;
        }

        // Add more patterns...

        return self::OTHER;
    }

    public function getLabel(): string { /* ... */ }
    public function getDescription(): string { /* ... */ }
}
```

#### 1.2 Update OrderStatus Enum
**File**: `app/Enums/Order/OrderStatus.php`

Add new case:
```php
case RETURNED = 'returned';
```

Add to `getLabel()`, `getColor()`, `getIcon()`, `getDescription()` methods.

#### 1.3 Add Migration for Return Reason Column
**Migration**: `add_return_reason_to_order_returns_table.php`

```php
Schema::table('order_returns', function (Blueprint $table) {
    $table->string('return_reason')->nullable()->after('reason_name');
    $table->index('return_reason');
});
```

### Phase 2: Core Actions
#### 2.1 UpdateShippingCostAction
**File**: `app/Actions/Shipping/UpdateShippingCostAction.php`

**Responsibility**: Update shipping cost data from BasitKargo, preserving `shipping_amount`

```php
class UpdateShippingCostAction
{
    public function execute(Order $order, array $shipmentData, Integration $integration): bool
    {
        // Extract cost data from shipmentData
        $priceInfo = $shipmentData['raw_data']['priceInfo'] ?? null;
        if (!$priceInfo) return false;

        // DO NOT TOUCH shipping_amount - only update actual costs
        $order->update([
            'shipping_cost_excluding_vat' => $priceExcludingVat,
            'shipping_vat_rate' => $vatRate,
            'shipping_vat_amount' => $vatAmount,
            // shipping_amount is INTENTIONALLY not updated
        ]);

        return true;
    }
}
```

#### 2.2 UpdateShippingInfoAction
**File**: `app/Actions/Shipping/UpdateShippingInfoAction.php`

**Responsibility**: Update carrier, desi, tracking info

```php
class UpdateShippingInfoAction
{
    public function execute(Order $order, array $shipmentData): bool
    {
        // Use handlerDesi (updated by carrier), not original desi
        $desi = $shipmentData['raw_data']['shipmentInfo']['handlerDesi']
             ?? $shipmentData['desi']
             ?? $order->shipping_desi;

        $carrier = $this->mapBasitKargoCodeToCarrier(
            $shipmentData['carrier_code'] ?? null
        );

        $order->update([
            'shipping_carrier' => $carrier?->value ?? $order->shipping_carrier,
            'shipping_desi' => $desi,
        ]);

        return true;
    }
}
```

#### 2.3 ProcessReturnedShipmentAction
**File**: `app/Actions/Shipping/ProcessReturnedShipmentAction.php`

**Responsibility**: Auto-create OrderReturn for returned shipments

```php
class ProcessReturnedShipmentAction
{
    public function __construct(
        protected UpdateShippingCostAction $updateCostAction,
        protected UpdateShippingInfoAction $updateInfoAction
    ) {}

    public function execute(
        Order $order,
        array $shipmentData,
        Integration $integration
    ): ?OrderReturn {
        // Check if return already exists (idempotency)
        $existingReturn = OrderReturn::where('order_id', $order->id)
            ->whereIn('status', [
                ReturnStatus::Requested,
                ReturnStatus::PendingReview,
                ReturnStatus::Approved,
                ReturnStatus::Received,
                ReturnStatus::Completed,
            ])
            ->first();

        if ($existingReturn) {
            activity()
                ->performedOn($order)
                ->log('shipment_return_already_exists');
            return $existingReturn;
        }

        // Determine if COD
        $isCOD = strtolower($order->payment_method ?? '') === 'cod';

        // Set status and reason based on payment method
        if ($isCOD) {
            $status = ReturnStatus::PendingReview;
            $reason = ReturnReason::COD_REJECTED;
            $reasonText = 'Customer did not accept COD delivery';
            $orderStatus = OrderStatus::REJECTED;
        } else {
            $status = ReturnStatus::Received;
            $reason = ReturnReason::RETURNED_BY_CARRIER;
            $reasonText = 'Shipment returned by carrier';
            $orderStatus = OrderStatus::RETURNED;
        }

        // Create return
        $return = OrderReturn::create([
            'order_id' => $order->id,
            'channel' => $order->channel,
            'status' => $status,
            'return_reason' => $reason->value,
            'reason_name' => $reasonText,
            'requested_at' => now(),
            'received_at' => $isCOD ? null : now(),
            // ... other fields
        ]);

        // Copy all order items to return items
        foreach ($order->items as $orderItem) {
            $return->items()->create([
                'order_item_id' => $orderItem->id,
                'quantity' => $orderItem->quantity,
                'refund_amount' => $orderItem->total_price,
                'reason_name' => $reasonText,
            ]);
        }

        // Update order status
        $order->update(['order_status' => $orderStatus]);

        activity()
            ->performedOn($return)
            ->withProperties([
                'is_cod' => $isCOD,
                'auto_created' => true,
            ])
            ->log('shipment_return_auto_created');

        return $return;
    }
}
```

### Phase 3: Orchestrator Service
#### 3.1 ShippingDataSyncService
**File**: `app/Services/Shipping/ShippingDataSyncService.php`

**Responsibility**: Unified entry point for all shipping sync operations

```php
class ShippingDataSyncService
{
    public function __construct(
        protected UpdateShippingCostAction $updateCostAction,
        protected UpdateShippingInfoAction $updateInfoAction,
        protected ProcessReturnedShipmentAction $processReturnAction
    ) {}

    /**
     * Main sync method - used by webhooks, manual resync, commands
     */
    public function syncShippingData(
        Order $order,
        Integration $integration,
        bool $force = false
    ): array {
        // Fetch latest shipment data from BasitKargo
        $adapter = new BasitKargoAdapter($integration);
        $shipmentData = $adapter->trackShipment($order->shipping_tracking_number);

        if (!$shipmentData) {
            return ['success' => false, 'error' => 'shipment_not_found'];
        }

        $results = [
            'cost_updated' => false,
            'info_updated' => false,
            'return_created' => false,
        ];

        // 1. Update shipping costs (preserving shipping_amount)
        $results['cost_updated'] = $this->updateCostAction->execute(
            $order,
            $shipmentData,
            $integration
        );

        // 2. Update shipping info (carrier, desi)
        $results['info_updated'] = $this->updateInfoAction->execute(
            $order,
            $shipmentData
        );

        // 3. Check if shipment is returned - auto-create return
        $isReturned = $shipmentData['is_returned'] ?? false;
        if ($isReturned) {
            $return = $this->processReturnAction->execute(
                $order,
                $shipmentData,
                $integration
            );
            $results['return_created'] = $return !== null;
        }

        return ['success' => true, 'results' => $results];
    }
}
```

### Phase 4: Update Entry Points
#### 4.1 Update ProcessBasitKargoWebhook
**File**: `app/Jobs/ProcessBasitKargoWebhook.php`

Replace existing logic with:
```php
protected function handleOrderShipmentUpdate(/* ... */): void
{
    // Use unified service
    $syncService = app(ShippingDataSyncService::class);
    $syncService->syncShippingData($order, $integration);
}

// Remove handleCODReturn() method - now handled by ProcessReturnedShipmentAction
```

#### 4.2 Update ResyncShippingCostAction
**File**: `app/Filament/Actions/Order/ResyncShippingCostAction.php` (or wherever it lives)

```php
public function handle()
{
    $syncService = app(ShippingDataSyncService::class);
    $result = $syncService->syncShippingData(
        $this->record,
        $integration,
        force: true
    );

    if ($result['success']) {
        Notification::make()
            ->title('Shipping data synced successfully')
            ->success()
            ->send();
    }
}
```

#### 4.3 Update SyncShopifyShippingCosts Command
**File**: `app/Console/Commands/SyncShopifyShippingCosts.php`

Replace existing logic to use `ShippingDataSyncService`.

### Phase 5: Cleanup & Refactoring
#### 5.1 Remove Duplicate Logic
- Remove duplicate `return_status` calculation from `ClaimsMapper.php` (lines 126-149)
- Use `OrderReturn::updateOrderReturnStatus()` method instead

#### 5.2 Update ShippingCostSyncService
**File**: `app/Services/Shipping/ShippingCostSyncService.php`

- Update `syncShippingCostWithBreakdown()` to NOT overwrite `shipping_amount`
- Use `handlerDesi` instead of `desi`
- Consider deprecating in favor of `ShippingDataSyncService`

### Phase 6: Testing
#### 6.1 Test Cases

**Unit Tests**:
- `ReturnReason::fromText()` fuzzy matching
- Each action in isolation
- Idempotency checks

**Feature Tests**:
- COD order returned → PendingReview status, REJECTED order_status
- Non-COD order returned → Received status, RETURNED order_status
- Resync doesn't recreate existing returns
- `shipping_amount` preserved during resync
- `handlerDesi` used correctly

### Phase 7: Documentation
#### 7.1 Update Documentation
- Document new `ReturnReason` enum and usage
- Document `ShippingDataSyncService` and when to use it
- Document column purposes and which are preserved
- Document return creation flow for COD vs non-COD

## Migration Strategy

### Order of Execution
1. ✅ Create `ReturnReason` enum
2. ✅ Update `OrderStatus` enum (add RETURNED)
3. ✅ Run migration for `return_reason` column
4. ✅ Create three actions (UpdateShippingCost, UpdateShippingInfo, ProcessReturnedShipment)
5. ✅ Create `ShippingDataSyncService` orchestrator
6. ✅ Update `ProcessBasitKargoWebhook`
7. ✅ Update `ResyncShippingCostAction`
8. ✅ Update `SyncShopifyShippingCosts` command
9. ✅ Remove duplicate logic from `ClaimsMapper`
10. ✅ Write tests
11. ✅ Run Pint formatting
12. ✅ Update documentation

### Rollback Plan
If issues arise:
1. Revert webhook changes first
2. Revert action implementations
3. Keep enum additions (backward compatible)
4. Keep migration (data is additive, not destructive)

## Success Criteria
- [ ] `shipping_amount` never overwritten during resync
- [ ] All returned shipments auto-create OrderReturn records
- [ ] Returns created with correct status (PendingReview for COD, Received for non-COD)
- [ ] Order status updated correctly (REJECTED for COD, RETURNED for non-COD)
- [ ] Unified return reasons used across all channels
- [ ] Idempotent operations - no duplicate returns
- [ ] Correct desi value from `handlerDesi`
- [ ] All tests passing
- [ ] Code formatted with Pint

## Notes
- This is a comprehensive refactoring that touches multiple critical paths
- Extensive testing is required before production deployment
- Consider feature flag for gradual rollout
- Monitor activity logs for `shipment_return_auto_created` events
