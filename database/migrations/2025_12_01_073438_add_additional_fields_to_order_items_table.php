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
        Schema::table('order_items', function (Blueprint $table) {
            // Change existing decimal columns to integer (cents)
            $table->integer('unit_price')->change();
            $table->integer('total_price')->change();

            // Add new fields
            $table->integer('discount_amount')->default(0)->after('total_price');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('discount_amount');
            $table->integer('tax_amount')->default(0)->after('tax_rate');
            $table->integer('commission_amount')->default(0)->after('tax_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Revert to decimal
            $table->decimal('unit_price', 10, 2)->change();
            $table->decimal('total_price', 10, 2)->change();

            // Drop new columns
            $table->dropColumn([
                'discount_amount',
                'tax_rate',
                'tax_amount',
                'commission_amount',
            ]);
        });
    }
};
