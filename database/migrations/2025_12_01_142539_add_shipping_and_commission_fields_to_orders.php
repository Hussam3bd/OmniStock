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
            // Shipping information
            $table->string('shipping_carrier')->nullable()->after('notes');
            $table->string('shipping_tracking_number')->nullable()->after('shipping_carrier');
            $table->text('shipping_tracking_url')->nullable()->after('shipping_tracking_number');
            $table->timestamp('shipped_at')->nullable()->after('shipping_tracking_url');
            $table->timestamp('delivered_at')->nullable()->after('shipped_at');
            $table->timestamp('estimated_delivery_start')->nullable()->after('delivered_at');
            $table->timestamp('estimated_delivery_end')->nullable()->after('estimated_delivery_start');

            // Commission and financial summary
            $table->bigInteger('total_commission')->default(0)->after('total_amount')->comment('Total commission in minor units');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // Commission rate percentage
            $table->decimal('commission_rate', 5, 2)->default(0)->after('commission_amount')->comment('Commission rate percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_carrier',
                'shipping_tracking_number',
                'shipping_tracking_url',
                'shipped_at',
                'delivered_at',
                'estimated_delivery_start',
                'estimated_delivery_end',
                'total_commission',
            ]);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });
    }
};
