# Return Reason Cleanup Summary

## Problem
We had **3 redundant columns** for storing return reasons:
1. `reason_code` - Platform-specific codes (e.g., "SIZE_ISSUE" from Trendyol)
2. `reason_name` - Human-readable text (e.g., "Wrong Size")
3. `return_reason` - NEW unified enum value (e.g., "wrong_size")

## Solution
Consolidated to **2 columns only**:
1. ✅ **`return_reason`** - Unified enum (`ReturnReason::WRONG_SIZE`)
2. ✅ **`reason_name`** - Original text for reference

❌ **Removed `reason_code`** - No longer needed

---

## Changes Made

### 📊 Database Migrations

#### 1. Populate `return_reason` from `reason_code`
**File**: `2025_12_08_092423_populate_return_reason_from_reason_code.php`
- Migrated existing data using intelligent mapping:
  1. Try `ReturnReason::fromTrendyolCode()`
  2. Fallback to `ReturnReason::fromText()` (fuzzy matching)
  3. Default to `ReturnReason::OTHER` if no match

#### 2. Drop `reason_code` from `returns` table
**File**: `2025_12_08_092435_drop_reason_code_from_returns_table.php`
- Removed the `reason_code` column after migration

#### 3. Migrate `return_items` table
**File**: `2025_12_08_092554_add_return_reason_to_return_items_table.php`
- Added `return_reason` column
- Migrated data from `reason_code`
- Dropped `reason_code` column

### 🔧 Model Updates

#### `OrderReturn` Model
**File**: `app/Models/Order/OrderReturn.php`
```php
// Changed fillable
'return_reason',  // was 'reason_code'
'reason_name',

// Added cast
'return_reason' => ReturnReason::class,
```

#### `ReturnItem` Model
**File**: `app/Models/Order/ReturnItem.php`
```php
// Changed fillable
'return_reason',  // was 'reason_code'

// Added cast
'return_reason' => ReturnReason::class,
```

### 📝 Code Updates

#### 1. `ProcessReturnedShipmentAction`
**File**: `app/Actions/Shipping/ProcessReturnedShipmentAction.php`
```php
// Before
'reason_code' => $reason->value,
'reason_name' => $reasonText,

// After
'return_reason' => $reason->value,
'reason_name' => $reasonText,
```

#### 2. `ClaimsMapper` (Trendyol)
**File**: `app/Services/Integrations/SalesChannels/Trendyol/Mappers/ClaimsMapper.php`

**For OrderReturn:**
```php
// Map Trendyol reason to unified enum
$trendyolReasonCode = $firstClaimItem['customerClaimItemReason']['code'] ?? null;
$trendyolReasonName = $firstClaimItem['customerClaimItemReason']['name'] ?? null;

$returnReason = null;
if ($trendyolReasonCode) {
    $returnReason = ReturnReason::fromTrendyolCode($trendyolReasonCode);
}
if (!$returnReason && $trendyolReasonName) {
    $returnReason = ReturnReason::fromText($trendyolReasonName);
}

// Store unified enum value
'return_reason' => $returnReason?->value,
'reason_name' => $trendyolReasonName,
```

**For ReturnItem:**
```php
// Same mapping logic per item
$itemReturnReason = ReturnReason::fromTrendyolCode($itemReasonCode);
// ...
'return_reason' => $itemReturnReason?->value,
'reason_name' => $itemReasonName,
```

---

## Benefits

### ✅ Before vs After

| Aspect | Before (3 columns) | After (2 columns) |
|--------|-------------------|-------------------|
| **Consistency** | ❌ Different codes per platform | ✅ Unified enum across all platforms |
| **Type Safety** | ❌ Plain strings | ✅ Enum with validation |
| **Reporting** | ❌ Hard to aggregate | ✅ Easy filtering by enum |
| **Clarity** | ❌ Confusing what each column does | ✅ Clear: enum + description |
| **Storage** | ❌ 3 columns | ✅ 2 columns |

### 🎯 What We Achieved

1. **Unified Data Model**: All channels (Trendyol, Shopify, BasitKargo) use same `ReturnReason` enum
2. **Smart Mapping**: Automatic conversion from platform codes → unified reasons
3. **Preserved Original**: Still keep `reason_name` for reference
4. **Type-Safe Code**: Enum casting in models prevents invalid values
5. **Easy Reporting**: Can now filter/group by `return_reason` consistently

---

## Migration Order

All migrations ran successfully ✅:
```bash
2025_12_08_091553_add_return_reason_to_order_returns_table.php
2025_12_08_092423_populate_return_reason_from_reason_code.php
2025_12_08_092435_drop_reason_code_from_returns_table.php
2025_12_08_092554_add_return_reason_to_return_items_table.php
```

---

## Testing Checklist

- [ ] Create a new return via BasitKargo webhook → Check `return_reason` is populated
- [ ] Create a return from Trendyol claim → Check mapping works
- [ ] View return in Filament → Ensure enum displays correctly
- [ ] Filter returns by reason in UI → Verify filtering works
- [ ] Check existing returns still display properly

---

## Files Modified

**Models:**
- `app/Models/Order/OrderReturn.php`
- `app/Models/Order/ReturnItem.php`

**Actions:**
- `app/Actions/Shipping/ProcessReturnedShipmentAction.php`

**Mappers:**
- `app/Services/Integrations/SalesChannels/Trendyol/Mappers/ClaimsMapper.php`

**Migrations:**
- `database/migrations/2025_12_08_092423_populate_return_reason_from_reason_code.php`
- `database/migrations/2025_12_08_092435_drop_reason_code_from_returns_table.php`
- `database/migrations/2025_12_08_092554_add_return_reason_to_return_items_table.php`

---

## Summary

We successfully cleaned up the redundant return reason columns from **3 → 2**, making the system:
- ✅ **Simpler** - Less confusing
- ✅ **Consistent** - One unified enum
- ✅ **Type-safe** - Enum validation
- ✅ **Flexible** - Still preserves original text

All data migrated successfully without loss! 🎉
