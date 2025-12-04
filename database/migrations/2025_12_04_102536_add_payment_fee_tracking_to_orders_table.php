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
            // Payment gateway fees (e.g., from Iyzico, Stripe)
            $table->unsignedBigInteger('payment_gateway_fee')->nullable()->after('payment_transaction_id')
                ->comment('Fixed fee charged by payment gateway (in minor units)');

            $table->decimal('payment_gateway_commission_rate', 10, 4)->nullable()->after('payment_gateway_fee')
                ->comment('Commission rate percentage charged by gateway (e.g., 2.50 for 2.5%)');

            $table->unsignedBigInteger('payment_gateway_commission_amount')->nullable()->after('payment_gateway_commission_rate')
                ->comment('Commission amount charged by gateway (in minor units)');

            $table->unsignedBigInteger('payment_payout_amount')->nullable()->after('payment_gateway_commission_amount')
                ->comment('Net payout amount after gateway fees (in minor units)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_gateway_fee',
                'payment_gateway_commission_rate',
                'payment_gateway_commission_amount',
                'payment_payout_amount',
            ]);
        });
    }
};
