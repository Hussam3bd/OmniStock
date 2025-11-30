<?php

use App\Models\Inventory\Location;
use App\Models\Product\ProductVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create default location
        $defaultLocation = Location::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
            'is_default' => true,
            'notes' => 'Default location created during migration',
        ]);

        // Migrate existing inventory quantities to location_inventory
        ProductVariant::query()
            ->where('inventory_quantity', '>', 0)
            ->chunkById(100, function ($variants) use ($defaultLocation) {
                foreach ($variants as $variant) {
                    DB::table('location_inventory')->insert([
                        'location_id' => $defaultLocation->id,
                        'product_variant_id' => $variant->id,
                        'quantity' => $variant->inventory_quantity,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

        // Update existing inventory movements to use the default location
        DB::table('inventory_movements')
            ->whereNull('location_id')
            ->update(['location_id' => $defaultLocation->id]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Delete the default location (cascade will remove location_inventory records)
        Location::where('code', 'MAIN')->delete();

        // Clear location_id from inventory movements
        DB::table('inventory_movements')->update(['location_id' => null]);
    }
};
