<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convert existing decimal values to cents (multiply by 100)
        DB::statement('UPDATE product_variants SET price = ROUND(price * 100), cost_price = ROUND(COALESCE(cost_price, 0) * 100)');

        // Change column types to unsignedBigInteger
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('price')->comment('Amount in cents')->change();
            $table->unsignedBigInteger('cost_price')->nullable()->comment('Amount in cents')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Change columns back to decimal
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->change();
            $table->decimal('cost_price', 10, 2)->nullable()->change();
        });

        // Convert cents back to decimal (divide by 100)
        DB::statement('UPDATE product_variants SET price = price / 100, cost_price = cost_price / 100');
    }
};
