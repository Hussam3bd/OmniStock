# Product System Redesign Plan

## Overview
Rebuild the product management system following Lunar's proven architecture but adapted to our needs for multi-channel e-commerce (Shopify + Trendyol).

## Current Database Schema (Already Exists)

### Core Tables
1. **products** - Main product information
   - id, title, description, vendor, product_type, status

2. **product_variants** - Individual sellable units
   - id, product_id, sku, barcode, title, price, cost_price
   - inventory_quantity, weight, weight_unit
   - requires_shipping, taxable

3. **variant_options** - System-level shared options (Color, Size, Material, etc.)
   - id, name, position

4. **variant_option_values** - Values for each option (Red, Blue, Small, Large, etc.)
   - id, variant_option_id, value, position

5. **product_variant_options** - Which options are used by each product
   - id, product_id, variant_option_id, position

6. **product_variant_option_values** - Which option values belong to each variant
   - id, product_variant_id, variant_option_value_id

## Implementation Plan

### Phase 1: Variant Options Management (System-Level)
Create a Filament resource to manage shared options that can be reused across all products.

**Resource: VariantOptionResource**
- Location: `app/Filament/Resources/Product/VariantOptions/`
- Pages: List, Create, Edit
- Features:
  - CRUD for options (Color, Size, Material, etc.)
  - Nested management of option values
  - Drag-and-drop reordering (position)
  - Translatable names (TR/EN)
  - Bulk operations

**UI Structure:**
```
List View:
┌─────────────────────────────────────────┐
│ Variant Options                         │
├─────────────┬───────────────┬───────────┤
│ Name        │ Values Count  │ Position  │
├─────────────┼───────────────┼───────────┤
│ Color       │ 12 values     │ 1         │
│ Size        │ 8 values      │ 2         │
│ Material    │ 5 values      │ 3         │
└─────────────┴───────────────┴───────────┘

Edit View:
┌─────────────────────────────────────────┐
│ Option Name: [Color      ]              │
│ Position: [1]                           │
│                                         │
│ Values:                                 │
│ ┌─────────────────────────────────────┐ │
│ │ ☰ Black    │ Position: 1 │ [Delete] │ │
│ │ ☰ White    │ Position: 2 │ [Delete] │ │
│ │ ☰ Red      │ Position: 3 │ [Delete] │ │
│ └─────────────────────────────────────┘ │
│ [+ Add Value]                           │
└─────────────────────────────────────────┘
```

### Phase 2: Product Resource Redesign
Simplify product creation and focus on core product information.

**Resource: ProductResource** (Rebuild)
- Location: `app/Filament/Resources/Product/Products/`
- Pages: List, Create, Edit, View

**Page Structure:**
```
List Products → Create/Edit Product (Basic Info) → Tabs:
                                                    ├─ Basic Information
                                                    ├─ Variants ⭐
                                                    ├─ Inventory
                                                    └─ Media
```

**Create Product Flow:**
```
Step 1: Basic Information
┌─────────────────────────────────────────┐
│ Product Name: [                       ] │
│ Product Type: [▼ Footwear            ] │
│ Vendor: [                       ]       │
│ Status: [▼ Draft                ]       │
│ Description: [                        ] │
│                                         │
│ Initial Variant:                        │
│ SKU: [                       ]          │
│ Base Price: [          ] TRY            │
│                                         │
│ [Create Product]                        │
└─────────────────────────────────────────┘
```

When created, product automatically gets:
- 1 default variant with provided SKU and price
- Status: draft
- Can then configure options to generate more variants

### Phase 3: Variant Management (Lunar-Style)
The most important part - managing product variants with options.

**Page: ManageProductVariants**
- Type: Custom Page (not ManageRelatedRecords)
- Uses Widget: ProductOptionsWidget

**UI Flow:**

```
┌───────────────────────────────────────────────────────────┐
│  Product Options                    [Configure Options]   │
├───────────────────────────────────────────────────────────┤
│  Option    │ Values                                       │
├────────────┼──────────────────────────────────────────────┤
│  Color     │ Black, White, Red                            │
│  Size      │ 36, 37, 38, 39, 40                           │
└────────────┴──────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────────┐
│  Product Variants                                         │
├────────────┬──────────┬──────────┬──────────┬────────────┤
│  Option    │ SKU      │ Price    │ Stock    │ Actions    │
├────────────┼──────────┼──────────┼──────────┼────────────┤
│ Black / 36 │ [SKU-1 ] │ [85.99 ] │ [100   ] │ [Delete]   │
│ Black / 37 │ [SKU-2 ] │ [85.99 ] │ [100   ] │ [Delete]   │
│ White / 36 │ [SKU-3 ] │ [85.99 ] │ [100   ] │ [Delete]   │
│ White / 37 │ [SKU-4 ] │ [85.99 ] │ [100   ] │ [Delete]   │
└────────────┴──────────┴──────────┴──────────┴────────────┘

[Save Variants]
```

**Configure Options Modal:**
```
┌───────────────────────────────────────────────────────────┐
│  Configure Product Options                                │
├───────────────────────────────────────────────────────────┤
│  [Add Shared Option ▼]                                    │
│                                                           │
│  Color:                          [Remove Option]          │
│  ┌─────────────────────────────────────────────────────┐ │
│  │ ☐ All Colors (12)                                   │ │
│  │ ☑ Black     ☑ White     ☑ Red     ☐ Blue          │ │
│  │ ☐ Green     ☐ Yellow    ☐ Orange  ☐ Purple        │ │
│  └─────────────────────────────────────────────────────┘ │
│                                                           │
│  Size:                           [Remove Option]          │
│  ┌─────────────────────────────────────────────────────┐ │
│  │ ☑ All Sizes (8)                                     │ │
│  │ ☑ 36  ☑ 37  ☑ 38  ☑ 39  ☑ 40  ☑ 41  ☑ 42  ☑ 43   │ │
│  └─────────────────────────────────────────────────────┘ │
│                                                           │
│  [Save Options]  [Cancel]                                 │
└───────────────────────────────────────────────────────────┘
```

**Logic:**
1. When options are saved, generate all permutations (cartesian product)
2. Existing variants are preserved with their data
3. New variants are created with base values from first variant
4. Inline editing of SKU, Price, Stock
5. Single "Save Variants" button to save all changes

### Phase 4: Inventory Management
Track inventory levels and movements per variant.

**Page: ManageProductInventory**
- Location: `/{record}/inventory`
- Features:
  - View current stock levels for all variants
  - Adjust inventory (add/remove stock)
  - View inventory movements history
  - Reasons for adjustments

**UI:**
```
┌───────────────────────────────────────────────────────────┐
│  Inventory Levels                                         │
├────────────┬──────────┬──────────┬──────────┬────────────┤
│  Variant   │ SKU      │ Stock    │ Reserved │ Available  │
├────────────┼──────────┼──────────┼──────────┼────────────┤
│ Black / 36 │ SKU-1    │ 100      │ 5        │ 95         │
│ Black / 37 │ SKU-2    │ 85       │ 2        │ 83         │
│ White / 36 │ SKU-3    │ 120      │ 0        │ 120        │
└────────────┴──────────┴──────────┴──────────┴────────────┘

[Adjust Inventory]

┌───────────────────────────────────────────────────────────┐
│  Recent Inventory Movements                               │
├──────────┬──────────┬─────────┬───────────┬──────────────┤
│  Date    │ Variant  │ Change  │ Reason    │ User         │
├──────────┼──────────┼─────────┼───────────┼──────────────┤
│ Nov 26   │ SKU-1    │ +50     │ Restock   │ Admin        │
│ Nov 25   │ SKU-2    │ -1      │ Sale      │ System       │
│ Nov 25   │ SKU-1    │ -2      │ Sale      │ System       │
└──────────┴──────────┴─────────┴───────────┴──────────────┘
```

### Phase 5: Media Management
Handle product images and variant-specific images.

**Page: ManageProductMedia**
- Location: `/{record}/media`
- Features:
  - Upload product images
  - Assign images to specific variants
  - Drag-and-drop reordering
  - Set featured image

## Technical Implementation Details

### Models & Relationships

**Product Model:**
```php
class Product extends Model
{
    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function variantOptions()
    {
        return $this->belongsToMany(
            VariantOption::class,
            'product_variant_options'
        )->withPivot('position')->orderBy('position');
    }
}
```

**ProductVariant Model:**
```php
class ProductVariant extends Model
{
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function optionValues()
    {
        return $this->belongsToMany(
            VariantOptionValue::class,
            'product_variant_option_values'
        );
    }
}
```

**VariantOption Model:**
```php
class VariantOption extends Model
{
    public function values()
    {
        return $this->hasMany(VariantOptionValue::class)
            ->orderBy('position');
    }

    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'product_variant_options'
        );
    }
}
```

**VariantOptionValue Model:**
```php
class VariantOptionValue extends Model
{
    public function variantOption()
    {
        return $this->belongsTo(VariantOption::class);
    }

    public function variants()
    {
        return $this->belongsToMany(
            ProductVariant::class,
            'product_variant_option_values'
        );
    }
}
```

### Widget Logic (ProductOptionsWidget)

**Key Methods:**
```php
class ProductOptionsWidget extends Widget
{
    public ?Model $record = null;
    public array $variants = [];
    public array $configuredOptions = [];
    public bool $configuringOptions = false;

    // 1. Load product's current options
    public function mount(): void
    {
        $this->loadConfiguredOptions();
        $this->generateVariantPermutations();
    }

    // 2. Load configured options with enabled values
    protected function loadConfiguredOptions(): void
    {
        // Get options attached to this product
        // Mark which values are enabled (have variants)
    }

    // 3. Generate all variant permutations
    protected function generateVariantPermutations(): void
    {
        // Cartesian product of selected option values
        // Match with existing variants
        // Create array for inline editing
    }

    // 4. Save all variants at once
    public function saveVariants(): void
    {
        // Create/update variants
        // Sync option values
        // Delete removed variants
    }
}
```

### Cartesian Product Algorithm
```php
protected function cartesianProduct(array $arrays): array
{
    $result = [[]];

    foreach ($arrays as $key => $values) {
        $append = [];
        foreach ($result as $product) {
            foreach ($values as $value) {
                $product[$key] = $value;
                $append[] = $product;
            }
        }
        $result = $append;
    }

    return $result;
}
```

## Navigation Structure

```
Products (Group)
├─ Products (List all products)
├─ Variant Options (Manage shared options)
└─ Product Groups (Optional: categorize products)
```

## File Structure

```
app/
├─ Filament/
│  └─ Resources/
│     └─ Product/
│        ├─ VariantOptions/
│        │  ├─ VariantOptionResource.php
│        │  ├─ Pages/
│        │  │  ├─ ListVariantOptions.php
│        │  │  ├─ CreateVariantOption.php
│        │  │  └─ EditVariantOption.php
│        │  └─ Tables/
│        │     └─ VariantOptionsTable.php
│        │
│        └─ Products/
│           ├─ ProductResource.php
│           ├─ Pages/
│           │  ├─ ListProducts.php
│           │  ├─ CreateProduct.php
│           │  ├─ EditProduct.php
│           │  ├─ ManageProductVariants.php
│           │  ├─ ManageProductInventory.php
│           │  └─ ManageProductMedia.php
│           ├─ Widgets/
│           │  └─ ProductOptionsWidget.php
│           ├─ Tables/
│           │  └─ ProductsTable.php
│           └─ Schemas/
│              └─ ProductForm.php
│
└─ Models/
   └─ Product/
      ├─ Product.php
      ├─ ProductVariant.php
      ├─ VariantOption.php
      └─ VariantOptionValue.php
```

## Next Steps

1. ✅ Create VariantOption resource
2. ✅ Rebuild Product resource
3. ✅ Implement variant generation widget
4. ✅ Add inventory management
5. ✅ Add media management
6. Test complete flow
7. Add translations (TR/EN)
8. Optimize queries (eager loading)
9. Add tests

## Key Differences from Current Implementation

**Before:**
- Complex ProductGroup concept
- Confusing variant management
- No clear separation of concerns
- Hard to understand flow

**After:**
- Simple, clear hierarchy: Products → Variants → Options
- System-level shared options (reusable)
- Lunar-proven UX pattern
- Inline editing of variants
- Single source of truth
