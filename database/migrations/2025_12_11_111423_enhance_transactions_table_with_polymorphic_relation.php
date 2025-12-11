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
        Schema::table('transactions', function (Blueprint $table) {
            // Add polymorphic relationship fields
            $table->string('transactionable_type')->nullable()->after('account_id');
            $table->unsignedBigInteger('transactionable_id')->nullable()->after('transactionable_type');
            $table->index(['transactionable_type', 'transactionable_id']);

            // Add foreign key to purchase_orders for expense tracking
            $table->foreignId('purchase_order_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropColumn(['transactionable_type', 'transactionable_id', 'purchase_order_id']);
            $table->dropIndex(['transactionable_type', 'transactionable_id']);
        });
    }
};
