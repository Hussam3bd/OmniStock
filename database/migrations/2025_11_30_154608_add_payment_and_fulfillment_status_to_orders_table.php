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
        Schema::table('orders', function (Blueprint $table) {
            // Rename existing status column to order_status
            $table->renameColumn('status', 'order_status');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Add new status columns
            $table->string('payment_status')->default('pending')->after('order_status');
            $table->string('fulfillment_status')->default('unfulfilled')->after('payment_status');
        });

        // Update existing data
        DB::statement("UPDATE orders SET order_status = 'pending' WHERE order_status IS NULL OR order_status = ''");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'fulfillment_status']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('order_status', 'status');
        });
    }
};
