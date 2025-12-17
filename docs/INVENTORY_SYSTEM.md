# Inventory Management System

## Overview

The inventory system tracks all stock movements through purchase orders, sales, returns, and cancellations. Every inventory change creates an immutable movement record with before/after quantities for complete audit trail.

## Inventory Movement Behavior

### 1. Normal Order Flow

**When an order is created:**
- Creates a **sale movement** (deducts inventory: -1)
- Inventory is reduced immediately
- Movement shows: `type=sale, quantity=-1, reference="Order #123"`
- Before/after values track the change: `before=10, after=9`

### 2. Order Cancellation (Real-time)

**When order status changes to Cancelled:**
- Creates a **cancellation movement** (restores inventory: +1)
- Original sale movement remains in history (audit trail)
- New cancellation movement restores the stock
- Both movements visible in history
- Net effect: -1 (sale) + 1 (cancellation) = 0

**Example History:**
```
1. Sale movement: -1 (before=10, after=9)
2. Cancellation movement: +1 (before=9, after=10)
```

### 3. Completed Returns

**When return status changes to Completed:**
- Creates a **return movement** (restores inventory: +1)
- Original sale movement stays in history
- Return movement restores stock
- Full audit trail preserved

**Example:**
```
1. Sale movement: -1 (before=10, after=9)
2. Return movement: +1 (before=9, after=10)
```

### 4. Purchase Orders

**When purchase order is received:**
- Creates a **purchase_received movement** (adds inventory: +quantity)
- Inventory increases by quantity received
- Tracks which purchase order added the stock

### 5. Idempotency Protection

All inventory operations have idempotency checks:
- If a movement already exists for the same order+variant+type, it skips creating duplicates
- Prevents double-processing bugs
- Safe to retry operations

## Movement Types

| Type | Description | Quantity | When Created |
|------|-------------|----------|--------------|
| `purchase_received` | Stock added from purchase order | Positive (+) | When PO marked as received |
| `sale` | Stock sold via order | Negative (-) | When order item created |
| `return` | Stock returned from customer | Positive (+) | When return completed |
| `cancellation` | Stock restored from cancelled order | Positive (+) | When order cancelled |

## Architecture

### Event Flow

```
Order Created
    ↓
OrderItemObserver::created()
    ↓
OrderItemCreated Event
    ↓
DeductInventoryForOrderItem Listener (Queued)
    ↓
InventoryService::deductInventoryForOrderItem()
    ↓
Creates sale movement + Updates inventory
```

```
Order Cancelled
    ↓
OrderObserver::updated()
    ↓
OrderCancelled Event
    ↓
RestoreInventoryForCancellation Listener (Queued)
    ↓
InventoryService::restoreInventoryForCancellation()
    ↓
Creates cancellation movement + Restores inventory
```

```
Return Completed
    ↓
OrderReturnObserver::updated()
    ↓
OrderReturnCompleted Event
    ↓
RestoreInventoryForReturn Listener (Queued)
    ↓
InventoryService::restoreInventoryForReturn()
    ↓
Creates return movement + Restores inventory
```

### Key Files

- **Models:**
  - `app/Models/Inventory/InventoryMovement.php` - Movement records
  - `app/Models/Inventory/LocationInventory.php` - Current stock per location
  - `app/Models/Product/ProductVariant.php` - Product variant with total inventory

- **Services:**
  - `app/Services/Inventory/InventoryService.php` - Core inventory operations

- **Observers:**
  - `app/Observers/OrderItemObserver.php` - Triggers inventory deduction on order creation
  - `app/Observers/OrderObserver.php` - Triggers inventory restoration on cancellation
  - `app/Observers/OrderReturnObserver.php` - Triggers inventory restoration on return

- **Listeners:**
  - `app/Listeners/Inventory/DeductInventoryForOrderItem.php` - Handles sale movements
  - `app/Listeners/Inventory/RestoreInventoryForCancellation.php` - Handles cancellations
  - `app/Listeners/Inventory/RestoreInventoryForReturn.php` - Handles returns

## Maintenance Commands

### Verification

```bash
# Verify all inventory movements match expected values
php artisan inventory:verify

# Verify specific SKU
php artisan inventory:verify --sku=REV-0004-BEJ-36

# Show detailed movements for each variant
php artisan inventory:verify --detailed

# Show only variants with issues
php artisan inventory:verify --missing
```

### Cleanup Commands

```bash
# Remove duplicate sale movements (idempotency failures)
php artisan inventory:cleanup-duplicates [--dry-run]

# Recalculate movement history before/after values
php artisan inventory:recalculate-history [--dry-run]

# Process completed returns without inventory movements
php artisan inventory:process-returns [--dry-run]

# Process cancelled orders without restoration movements
php artisan inventory:process-cancellations [--dry-run]

# Remove movements from orders imported already cancelled
php artisan inventory:remove-cancelled-movements [--dry-run]

# Remove duplicate return movements (for orders with both cancellation AND return)
php artisan inventory:remove-duplicate-returns [--dry-run]
```

## Production Deployment Commands

When deploying to production or after importing data, run these commands in order:

```bash
# 1. CRITICAL: Backup database first!
php artisan backup:run  # Or your backup command

# 2. Remove duplicate sale movements (from double processing)
php artisan inventory:cleanup-duplicates

# 3. Process completed returns that were imported without movements
php artisan inventory:process-returns

# 4. Remove movements from orders imported already cancelled
php artisan inventory:remove-cancelled-movements

# 5. Remove duplicate return movements (orders with both cancellation AND return)
php artisan inventory:remove-duplicate-returns

# 6. Recalculate all before/after values in movement history
php artisan inventory:recalculate-history

# 7. Verify everything is correct
php artisan inventory:verify
```

**Optional - Test with dry-run first:**

```bash
php artisan inventory:cleanup-duplicates --dry-run
php artisan inventory:process-returns --dry-run
php artisan inventory:remove-cancelled-movements --dry-run
php artisan inventory:remove-duplicate-returns --dry-run
php artisan inventory:recalculate-history --dry-run
```

## Expected Results

After running cleanup commands:

- ✅ All duplicate sale movements removed
- ✅ All completed returns will restore inventory
- ✅ Pre-cancelled orders will have no movements
- ✅ All movement history before/after values will be accurate
- ✅ All variants should pass verification with 0 issues

## Troubleshooting

### Issue: Inventory showing incorrect count

**Diagnosis:**
```bash
php artisan inventory:verify --sku=YOUR-SKU --detailed
```

**Common causes:**
1. Duplicate movements (run `inventory:cleanup-duplicates`)
2. Missing return movements (run `inventory:process-returns`)
3. Pre-cancelled orders with movements (run `inventory:remove-cancelled-movements`)
4. Incorrect before/after values (run `inventory:recalculate-history`)

### Issue: Movement history shows duplicates

**Solution:**
```bash
php artisan inventory:cleanup-duplicates
php artisan inventory:recalculate-history
```

### Issue: Returns not restoring inventory

**Solution:**
```bash
php artisan inventory:process-returns
php artisan inventory:recalculate-history
```

### Issue: Cancelled orders showing in history

**Solution:**
```bash
php artisan inventory:remove-cancelled-movements
php artisan inventory:recalculate-history
```

## Database Schema

### inventory_movements

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| product_variant_id | bigint | Variant this movement affects |
| location_id | bigint | Warehouse location |
| order_id | bigint | Related order (nullable) |
| type | enum | Movement type (sale, return, cancellation, purchase_received) |
| quantity | int | Change amount (negative for deductions) |
| quantity_before | int | Stock level before this movement |
| quantity_after | int | Stock level after this movement |
| reference | varchar | Human-readable reference |
| notes | text | Additional notes |
| created_at | timestamp | When movement occurred |

### location_inventories

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| location_id | bigint | Warehouse location |
| product_variant_id | bigint | Variant being tracked |
| quantity | int | Current stock level |

## Best Practices

1. **Never manually delete movements** - They are immutable audit records
2. **Always use commands with --dry-run first** in production
3. **Backup before running cleanup commands**
4. **Run inventory:verify regularly** to catch issues early
5. **Check logs if movements fail** - Idempotency will prevent duplicates on retry
6. **Movement timestamps are important** - They determine the order of before/after calculations

## Future Enhancements

- Inventory adjustments (for damaged goods, loss, found stock)
- Transfer movements (between locations)
- Reserved inventory (for unfulfilled orders)
- Low stock alerts
- Inventory forecasting
