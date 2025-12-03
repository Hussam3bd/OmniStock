<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add unit_cost to order_items table for historical product cost tracking
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_cost')->nullable()->after('unit_price');
        });

        // Add total_product_cost to orders table for aggregated COGS
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('total_product_cost')->nullable()->after('total_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('total_product_cost');
        });
    }
};
