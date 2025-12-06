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
            // Which shipping aggregator integration was used (e.g., BasitKargo integration)
            $table->foreignId('shipping_aggregator_integration_id')
                ->nullable()
                ->after('shipping_tracking_url')
                ->constrained('integrations')
                ->nullOnDelete();

            // External shipment ID from the aggregator (e.g., BasitKargo order ID)
            $table->string('shipping_aggregator_shipment_id')
                ->nullable()
                ->after('shipping_aggregator_integration_id')
                ->index()
                ->comment('External shipment ID from shipping aggregator');

            // Platform data from aggregator (for reference/debugging)
            $table->json('shipping_aggregator_data')
                ->nullable()
                ->after('shipping_aggregator_shipment_id')
                ->comment('Raw data from shipping aggregator API');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['shipping_aggregator_integration_id']);
            $table->dropColumn([
                'shipping_aggregator_integration_id',
                'shipping_aggregator_shipment_id',
                'shipping_aggregator_data',
            ]);
        });
    }
};
