# Shipping Sync Flows - Visual Guide

## Flow 1: BasitKargo Webhook (Order Returned)

```
┌─────────────────────────────────────────────────────────────────┐
│                    BasitKargo Webhook                           │
│              ShipmentStatus = RETURNED                           │
└───────────────────────┬─────────────────────────────────────────┘
                        ↓
┌───────────────────────────────────────────────────────────────────┐
│              ProcessBasitKargoWebhook Job                         │
│  - Find order by tracking number                                 │
│  - Detect ShipmentStatus::RETURNED                               │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
┌───────────────────────────────────────────────────────────────────┐
│           ShippingDataSyncService::syncShippingData()             │
│  1. Fetch latest shipment data from BasitKargo                   │
│  2. Call UpdateShippingCostAction                                │
│  3. Call UpdateShippingInfoAction                                │
│  4. Detect is_returned = true                                    │
│  5. Call ProcessReturnedShipmentAction                           │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
┌───────────────────────────────────────────────────────────────────┐
│         ProcessReturnedShipmentAction::execute()                  │
│                                                                   │
│  Check existing return? ────Yes───→ Skip (return existing)       │
│         │                                                         │
│         No                                                        │
│         ↓                                                         │
│  Is COD? ────Yes───→ Create Return:                              │
│    │                  - Status: PendingReview                    │
│    │                  - Reason: COD_REJECTED                     │
│    │                  - Order Status: REJECTED                   │
│    │                                                              │
│    No                                                             │
│    ↓                                                              │
│  Create Return:                                                   │
│    - Status: Received                                            │
│    - Reason: RETURNED_BY_CARRIER                                 │
│    - Order Status: RETURNED                                      │
│                                                                   │
│  Copy all order items → return items                             │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
                  ✅ Complete
```

## Flow 2: Manual Resync Shipping Cost

```
┌───────────────────────────────────────────────────────────────────┐
│           User clicks "Resync Shipping Cost"                      │
│                    (Filament Action)                              │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
┌───────────────────────────────────────────────────────────────────┐
│              ResyncShippingCostAction::handle()                   │
│  - Get active BasitKargo integration                              │
│  - Call ShippingDataSyncService with force=true                  │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
┌───────────────────────────────────────────────────────────────────┐
│           ShippingDataSyncService::syncShippingData()             │
│  (Same flow as webhook above)                                     │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
┌───────────────────────────────────────────────────────────────────┐
│                 Update Shipping Costs                             │
│                                                                   │
│  shipping_amount             : UNCHANGED ❌ (customer charge)     │
│  shipping_cost_excluding_vat : UPDATED ✅ (actual carrier cost)   │
│  shipping_vat_amount         : UPDATED ✅ (VAT portion)           │
│  shipping_desi               : UPDATED ✅ (from handlerDesi)      │
│  shipping_carrier            : UPDATED ✅ (carrier enum)          │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
                  ✅ Complete
```

## Flow 3: Artisan Command (Bulk Sync)

```
┌───────────────────────────────────────────────────────────────────┐
│        php artisan sync:shopify-shipping-costs                    │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
┌───────────────────────────────────────────────────────────────────┐
│           Find orders needing sync:                               │
│  - Has tracking number                                            │
│  - Missing carrier OR missing shipping_cost_excluding_vat         │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
                  For each order
                        ↓
┌───────────────────────────────────────────────────────────────────┐
│           ShippingDataSyncService::syncShippingData()             │
│  (Same flow as webhook above)                                     │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
                  200ms delay
                        ↓
                  Next order...
```

## Flow 4: Return Reason Mapping

```
┌───────────────────────────────────────────────────────────────────┐
│           External Reason Text                                    │
│  (from Trendyol, Shopify, BasitKargo)                            │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
┌───────────────────────────────────────────────────────────────────┐
│          ReturnReason::fromText($text)                            │
│                                                                   │
│  Text contains:                                                   │
│  - "cod" / "kabul etmedi"      → COD_REJECTED                    │
│  - "size" / "beden"            → WRONG_SIZE                      │
│  - "defect" / "hasarlı"        → DEFECTIVE                       │
│  - "changed mind"              → CHANGED_MIND                    │
│  - No match                    → OTHER                           │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
┌───────────────────────────────────────────────────────────────────┐
│                 Save to order_returns                             │
│  - return_reason: enum value (e.g., 'cod_rejected')              │
│  - reason_name: original text for reference                      │
└───────────────────────────────────────────────────────────────────┘
```

## Flow 5: Order Return Status Calculation (Automatic)

```
┌───────────────────────────────────────────────────────────────────┐
│        OrderReturn status changes to Approved/Completed           │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
┌───────────────────────────────────────────────────────────────────┐
│      OrderReturn::updateOrderReturnStatus() (automatic)           │
│                                                                   │
│  1. Calculate total items in order                               │
│  2. Calculate total returned items (Approved/Completed only)     │
│  3. Determine return_status:                                     │
│     - 0 items returned       → 'none'                            │
│     - All items returned     → 'full' + order_status=REFUNDED   │
│     - Some items returned    → 'partial' + PARTIALLY_REFUNDED   │
└───────────────────────┬───────────────────────────────────────────┘
                        ↓
┌───────────────────────────────────────────────────────────────────┐
│              Update orders table                                  │
│  - return_status: 'none' / 'partial' / 'full'                    │
│  - order_status: may change to REFUNDED / PARTIALLY_REFUNDED     │
└───────────────────────────────────────────────────────────────────┘
```

## Flow 6: COD vs Non-COD Decision Tree

```
                    Shipment RETURNED
                           ↓
                   Is payment_method = 'cod'?
                      ╱              ╲
                   Yes                No
                    ↓                  ↓
        ┌──────────────────┐  ┌──────────────────┐
        │   COD Return     │  │  Regular Return  │
        ├──────────────────┤  ├──────────────────┤
        │ Status:          │  │ Status:          │
        │   PendingReview  │  │   Received       │
        ├──────────────────┤  ├──────────────────┤
        │ Reason:          │  │ Reason:          │
        │   COD_REJECTED   │  │   RETURNED_BY_   │
        │                  │  │   CARRIER        │
        ├──────────────────┤  ├──────────────────┤
        │ Order Status:    │  │ Order Status:    │
        │   REJECTED       │  │   RETURNED       │
        ├──────────────────┤  ├──────────────────┤
        │ Requires:        │  │ Requires:        │
        │   Employee       │  │   Inspection     │
        │   Approval       │  │   Only           │
        └──────────────────┘  └──────────────────┘
                    ↓                  ↓
            Pending Review      Ready for Inspection
```

## Flow 7: Idempotency Check

```
         ShippingDataSyncService called
                     ↓
         ProcessReturnedShipmentAction
                     ↓
      ┌──────────────────────────────┐
      │ Query: OrderReturn exists    │
      │   WHERE order_id = X         │
      │   AND status IN (            │
      │     Requested,               │
      │     PendingReview,           │
      │     Approved,                │
      │     Received,                │
      │     Completed                │
      │   )                          │
      └──────────┬───────────────────┘
                 │
        ╱────────┴────────╲
     Found            Not Found
       ↓                  ↓
   ┌─────────┐      ┌──────────┐
   │  Skip   │      │  Create  │
   │ Return  │      │  Return  │
   │ Exists  │      │          │
   └─────────┘      └──────────┘
       ↓                  ↓
   Log Activity      Copy Items
   'already_exists'  Update Order
                     Log Activity
                     'auto_created'
```

## Flow 8: Complete End-to-End Example

### Scenario: Shopify COD Order Returned by Customer

```
Day 1:
  Order #12345 placed on Shopify
  - Payment: COD
  - Total: ₺500
  - Shipping charged to customer: ₺50 (markup included)
  ↓
  Order synced to system
  - shipping_amount: ₺50 (customer charge)
  - shipping_cost_excluding_vat: null (not known yet)

Day 2:
  Shipment created via BasitKargo
  - Tracking: BK123456
  - Actual cost: ₺35 + ₺7 VAT = ₺42
  ↓
  BasitKargo webhook: ShipmentStatus::SHIPPED
  - Order updated with tracking info
  - Costs synced:
    * shipping_amount: ₺50 (UNCHANGED ✅)
    * shipping_cost_excluding_vat: ₺35
    * shipping_vat_amount: ₺7

Day 5:
  Customer rejects COD delivery
  ↓
  BasitKargo webhook: ShipmentStatus::RETURNED
  ↓
  ShippingDataSyncService triggered:
    1. UpdateShippingCostAction
       - Fetch latest costs (may have return fee added)
       - Update shipping_cost_excluding_vat
       - PRESERVE shipping_amount ✅

    2. UpdateShippingInfoAction
       - Update shipping_desi (from handlerDesi)
       - Carrier confirmed

    3. ProcessReturnedShipmentAction
       - Check existing return: None found
       - Detect payment_method = 'cod'
       - Create OrderReturn:
         * Status: PendingReview
         * Reason: COD_REJECTED
         * Copy all items (full return)
       - Update Order:
         * order_status: REJECTED
  ↓
  Employee reviews return in dashboard
  - Sees auto-created return with reason "Customer did not accept COD delivery"
  - Approves return
  ↓
  OrderReturn::approve() called
  - Status: PendingReview → Approved
  - approved_at: now()
  - approved_by: [employee_id]
  ↓
  OrderReturn::updateOrderReturnStatus() triggered automatically
  - All items returned (full return)
  - Update Order:
    * return_status: 'full'
    * order_status: REFUNDED
  ↓
  Inventory restored (via OrderReturnCompleted event)
  ↓
  ✅ Complete
```

## Data Examples

### Before Sync
```php
Order #12345:
  shipping_amount: 5000                      // ₺50.00 charged to customer
  shipping_cost_excluding_vat: null          // Not synced yet
  shipping_vat_amount: null
  shipping_desi: 2.5                         // Original weight
  shipping_carrier: null
  order_status: 'completed'
  return_status: 'none'
```

### After Shipping Cost Sync
```php
Order #12345:
  shipping_amount: 5000                      // ₺50.00 (UNCHANGED ✅)
  shipping_cost_excluding_vat: 3500          // ₺35.00 actual cost
  shipping_vat_amount: 700                   // ₺7.00 VAT
  shipping_desi: 2.8                         // Updated by carrier (from handlerDesi)
  shipping_carrier: 'yurtici'
  order_status: 'completed'
  return_status: 'none'
```

### After Auto-Return Creation (COD)
```php
Order #12345:
  // Shipping costs unchanged
  order_status: 'rejected'                   // Changed from 'completed'
  return_status: 'none'                      // Still 'none' (pending approval)

OrderReturn #456:
  order_id: 12345
  status: 'pending_review'
  return_reason: 'cod_rejected'
  reason_name: 'Customer did not accept COD delivery'
  requested_at: '2025-12-08 14:30:00'
  approved_at: null                          // Awaiting approval
```

### After Employee Approves
```php
Order #12345:
  order_status: 'refunded'                   // Auto-updated
  return_status: 'full'                      // Auto-calculated

OrderReturn #456:
  status: 'approved'
  approved_at: '2025-12-08 15:00:00'
  approved_by: 1                             // Employee ID
```

---

## Summary

All flows converge on the **ShippingDataSyncService** which ensures:
- ✅ Consistent behavior across all entry points
- ✅ Idempotent operations
- ✅ Proper separation of concerns
- ✅ Comprehensive audit logging
- ✅ Preservation of customer-charged amounts
