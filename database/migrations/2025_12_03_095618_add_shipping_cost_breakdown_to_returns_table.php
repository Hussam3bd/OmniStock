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
        Schema::table('returns', function (Blueprint $table) {
            // Add VAT breakdown fields after return_shipping_desi
            $table->decimal('return_shipping_vat_rate', 5, 2)->default(20.00)->after('return_shipping_desi')->comment('VAT rate percentage for return shipping');
            $table->bigInteger('return_shipping_vat_amount')->nullable()->after('return_shipping_vat_rate')->comment('VAT amount in minor units (cents)');

            // Add reference to shipping rate used (audit trail)
            $table->foreignId('return_shipping_rate_id')->nullable()->constrained('shipping_rates')->nullOnDelete()->after('return_shipping_vat_amount')->comment('Reference to rate table used for calculation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropForeign(['return_shipping_rate_id']);
            $table->dropColumn([
                'return_shipping_vat_rate',
                'return_shipping_vat_amount',
                'return_shipping_rate_id',
            ]);
        });
    }
};
