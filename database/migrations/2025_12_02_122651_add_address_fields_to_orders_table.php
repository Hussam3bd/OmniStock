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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('shipping_address_id')->nullable()->after('customer_id')->constrained('addresses')->nullOnDelete();
            $table->foreignId('billing_address_id')->nullable()->after('shipping_address_id')->constrained('addresses')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['shipping_address_id']);
            $table->dropForeign(['billing_address_id']);
            $table->dropColumn(['shipping_address_id', 'billing_address_id']);
        });
    }
};
