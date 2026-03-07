<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_items', function (Blueprint $table) {
            // null = unknown (Trendyol / manual) → treated as restocked
            // true  = Shopify restock_type 'return'     → physically restocked
            // false = Shopify restock_type 'no_restock' → not restocked, skip inventory
            $table->boolean('restocked')->nullable()->after('quantity');
        });

        // Backfill from existing platform_data for Shopify return items (MySQL only)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                UPDATE return_items
                SET restocked = CASE
                    WHEN JSON_UNQUOTE(JSON_EXTRACT(platform_data, '$.restock_type')) = 'return'     THEN 1
                    WHEN JSON_UNQUOTE(JSON_EXTRACT(platform_data, '$.restock_type')) = 'no_restock' THEN 0
                    ELSE NULL
                END
                WHERE platform_data IS NOT NULL
                  AND JSON_EXTRACT(platform_data, '$.restock_type') IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        Schema::table('return_items', function (Blueprint $table) {
            $table->dropColumn('restocked');
        });
    }
};
