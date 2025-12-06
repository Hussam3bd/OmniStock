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
            // Which shipping aggregator integration was used for return shipment
            $table->foreignId('return_shipping_aggregator_integration_id')
                ->nullable()
                ->after('return_tracking_url')
                ->constrained('integrations')
                ->nullOnDelete();

            // External return shipment ID from the aggregator
            $table->string('return_shipping_aggregator_shipment_id')
                ->nullable()
                ->after('return_shipping_aggregator_integration_id')
                ->index()
                ->comment('External return shipment ID from shipping aggregator');

            // Platform data from aggregator for return shipment
            $table->json('return_shipping_aggregator_data')
                ->nullable()
                ->after('return_shipping_aggregator_shipment_id')
                ->comment('Raw data from shipping aggregator API for return shipment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropForeign(['return_shipping_aggregator_integration_id']);
            $table->dropColumn([
                'return_shipping_aggregator_integration_id',
                'return_shipping_aggregator_shipment_id',
                'return_shipping_aggregator_data',
            ]);
        });
    }
};
