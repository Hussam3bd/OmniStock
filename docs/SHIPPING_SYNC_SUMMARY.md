# Shipping Sync & Auto-Return Implementation Summary

## Quick Overview

This implementation creates a **unified shipping synchronization system** that:

1. ✅ Preserves customer-charged shipping fees (`shipping_amount`)
2. ✅ Auto-creates returns for ALL returned shipments (COD and non-COD)
3. ✅ Uses correct carrier-updated desi values
4. ✅ Provides single source of truth for all sync operations
5. ✅ Unified return reason system across all channels

## Key Changes

### 1. Return Reason Enum (NEW)
**File**: `app/Enums/Order/ReturnReason.php`

- Unified return reasons across Trendyol, Shopify, BasitKargo
- Smart `fromText()` method for fuzzy matching
- Examples: `COD_REJECTED`, `WRONG_SIZE`, `DEFECTIVE`, `RETURNED_BY_CARRIER`, etc.

### 2. OrderStatus::RETURNED (NEW)
**File**: `app/Enums/Order/OrderStatus.php`

- New case for post-delivery returns
- Different from `REJECTED` (which is for COD rejections at delivery)

### 3. Auto-Return Logic

| Payment Method | Return Status | Order Status | Reason |
|----------------|---------------|--------------|--------|
| COD | `PendingReview` | `REJECTED` | "Customer did not accept COD delivery" |
| Non-COD | `Received` | `RETURNED` | "Shipment returned by carrier" |

### 4. Column Preservation

| Column | Updated During Resync? | Purpose |
|--------|------------------------|---------|
| `shipping_amount` | ❌ **NEVER** | Customer-charged amount (with markup) |
| `shipping_cost_excluding_vat` | ✅ YES | Actual carrier cost |
| `shipping_vat_amount` | ✅ YES | VAT portion |
| `shipping_desi` | ✅ YES | From `handlerDesi` |
| `shipping_carrier` | ✅ YES | Carrier used |

### 5. Architecture

```
Entry Points (Webhook, Manual Resync, Command)
    ↓
ShippingDataSyncService (Orchestrator)
    ↓
    ├─→ UpdateShippingCostAction (preserves shipping_amount)
    ├─→ UpdateShippingInfoAction (updates carrier, desi)
    └─→ ProcessReturnedShipmentAction (auto-creates returns)
```

## Implementation Order

1. **Phase 1: Foundation**
   - Create `ReturnReason` enum
   - Update `OrderStatus` enum
   - Run migration for `return_reason` column

2. **Phase 2: Core Actions**
   - `UpdateShippingCostAction`
   - `UpdateShippingInfoAction`
   - `ProcessReturnedShipmentAction`

3. **Phase 3: Orchestrator**
   - `ShippingDataSyncService`

4. **Phase 4: Update Entry Points**
   - `ProcessBasitKargoWebhook`
   - `ResyncShippingCostAction`
   - `SyncShopifyShippingCosts` command

5. **Phase 5: Cleanup**
   - Remove duplicate logic
   - Refactor existing services

6. **Phase 6: Testing**
   - Unit tests
   - Feature tests
   - Manual testing

7. **Phase 7: Documentation**
   - Update developer docs
   - Add inline comments

## Idempotency

All operations are idempotent:
- ✅ Check for existing returns before creating
- ✅ Skip processing if return already exists
- ✅ Safe to re-run sync multiple times

## Testing Scenarios

### Scenario 1: COD Order Returned
```
Before: Order with COD payment, shipped
Webhook: ShipmentStatus::RETURNED
After:
  - OrderReturn created with status=PendingReview
  - Order status = REJECTED
  - Return reason = COD_REJECTED
  - Requires employee approval
```

### Scenario 2: Non-COD Order Returned
```
Before: Order with online payment, shipped
Webhook: ShipmentStatus::RETURNED
After:
  - OrderReturn created with status=Received
  - Order status = RETURNED
  - Return reason = RETURNED_BY_CARRIER
  - Auto-received, ready for inspection
```

### Scenario 3: Manual Resync
```
User clicks "Resync Shipping Cost"
Action:
  - Fetch latest data from BasitKargo
  - Update shipping_cost_excluding_vat (actual cost)
  - Update shipping_desi (from handlerDesi)
  - PRESERVE shipping_amount (customer charge)
  - If returned: auto-create return (if not exists)
```

### Scenario 4: Resync Already Returned Order
```
Order already has OrderReturn
User clicks "Resync Shipping Cost"
Action:
  - Update costs and info
  - Skip return creation (already exists)
  - Log activity: 'shipment_return_already_exists'
```

## Benefits

### For Developers
- ✅ Single service for all shipping sync operations
- ✅ Clear separation of concerns (actions)
- ✅ Easy to test and debug
- ✅ Consistent behavior across all entry points

### For Business
- ✅ Accurate shipping costs tracking
- ✅ Preserved customer-charged amounts
- ✅ Automatic return creation reduces manual work
- ✅ Unified return reasons for reporting

### For Operations
- ✅ COD rejections require approval (fraud prevention)
- ✅ Non-COD returns auto-received (faster processing)
- ✅ No duplicate returns created
- ✅ Clear audit trail via activity logs

## Migration & Rollout

### Database Changes
```sql
-- Add return_reason column to order_returns
ALTER TABLE order_returns ADD COLUMN return_reason VARCHAR(255) NULL;
ALTER TABLE order_returns ADD INDEX idx_return_reason (return_reason);
```

### Backward Compatibility
- ✅ Existing code continues to work
- ✅ New enums are additive
- ✅ Migration is non-destructive
- ✅ Can rollback webhook changes independently

### Monitoring
Watch for these activity logs:
- `shipment_return_auto_created` - Return successfully created
- `shipment_return_already_exists` - Idempotency check passed
- `shipping_data_synced` - Costs and info updated

## Questions & Answers

### Q: What happens if BasitKargo reports RETURNED but we already have a manual return?
**A**: The system checks for existing returns first. It will skip creating a new one and log `shipment_return_already_exists`.

### Q: What if the order has partial items returned already?
**A**: The system only checks if ANY return exists in active statuses. It won't create a duplicate. The `return_status` ('partial'/'full') is managed by the OrderReturn model automatically.

### Q: Can we still manually create returns?
**A**: Yes! Manual return creation through the dashboard still works. Auto-creation only happens when BasitKargo reports RETURNED status.

### Q: What about Trendyol and Shopify returns?
**A**: They continue to work as before. The unified `ReturnReason` enum can map their reasons to our standard set using `fromText()`.

### Q: What if the carrier is not recognized?
**A**: The system will use the order's original carrier as fallback and log a warning. Costs will still be updated.

## Next Steps

1. **Review this plan** - Confirm approach is correct
2. **Start Phase 1** - Create enums and migration
3. **Implement Phase 2** - Build core actions
4. **Test incrementally** - Test after each phase
5. **Deploy carefully** - Consider feature flag for gradual rollout

## Full Documentation

For complete implementation details, see:
- **[SHIPPING_SYNC_IMPLEMENTATION_PLAN.md](./SHIPPING_SYNC_IMPLEMENTATION_PLAN.md)** - Full technical plan
- **[INVENTORY_TRACKING.md](./INVENTORY_TRACKING.md)** - Inventory system (for reference)
