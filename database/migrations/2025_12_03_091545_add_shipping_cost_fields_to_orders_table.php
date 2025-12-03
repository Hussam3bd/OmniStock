<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Carrier information
            $table->string('carrier')->nullable()->after('shipping_desi');

            // Shipping cost breakdown
            $table->bigInteger('shipping_cost_excluding_vat')->nullable()->comment('Base shipping cost in minor units (cents)')->after('carrier');
            $table->decimal('shipping_vat_rate', 5, 2)->default(20.00)->comment('VAT rate percentage')->after('shipping_cost_excluding_vat');
            $table->bigInteger('shipping_vat_amount')->nullable()->comment('VAT amount in minor units (cents)')->after('shipping_vat_rate');

            // Reference to shipping rate used for calculation (audit trail)
            $table->foreignId('shipping_rate_id')->nullable()->constrained('shipping_rates')->nullOnDelete()->after('shipping_vat_amount');

            // Add index for carrier queries
            $table->index('carrier');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['shipping_rate_id']);
            $table->dropIndex(['carrier']);
            $table->dropColumn([
                'carrier',
                'shipping_cost_excluding_vat',
                'shipping_vat_rate',
                'shipping_vat_amount',
                'shipping_rate_id',
            ]);
        });
    }
};
