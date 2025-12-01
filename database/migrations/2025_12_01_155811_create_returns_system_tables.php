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
        // Add return_status to orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->string('return_status')->nullable()->after('fulfillment_status')->comment('none, partial, full');
        });

        // Main returns table
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Return identification
            $table->string('return_number')->unique();
            $table->string('platform')->comment('trendyol, shopify, internal, etc.');
            $table->string('external_return_id')->nullable()->comment('Platform-specific return ID');

            // Return lifecycle
            $table->string('status')->default('requested');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('label_generated_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            // Return reason
            $table->string('reason_code')->nullable();
            $table->string('reason_name')->nullable();
            $table->text('customer_note')->nullable();
            $table->text('internal_note')->nullable();

            // Shipping information for return
            $table->string('return_shipping_carrier')->nullable();
            $table->string('return_tracking_number')->nullable();
            $table->text('return_tracking_url')->nullable();
            $table->text('return_label_url')->nullable()->comment('URL to downloadable return label');
            $table->bigInteger('return_shipping_cost')->default(0)->comment('Cost to ship product back in minor units');

            // Financial tracking
            $table->bigInteger('original_shipping_cost')->default(0)->comment('Original shipping cost for this order in minor units');
            $table->bigInteger('total_refund_amount')->default(0)->comment('Total refunded to customer in minor units');
            $table->bigInteger('restocking_fee')->default(0)->comment('Restocking fee charged in minor units');

            // Who handled the return
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();

            // Platform-specific data
            $table->json('platform_data')->nullable();

            $table->string('currency', 3)->default('TRY');
            $table->timestamps();

            // Indexes
            $table->index('order_id');
            $table->index('return_number');
            $table->index('platform');
            $table->index('external_return_id');
            $table->index('status');
        });

        // Return items table
        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();

            // Item details
            $table->integer('quantity')->default(1);
            $table->string('reason_code')->nullable();
            $table->string('reason_name')->nullable();
            $table->text('note')->nullable();

            // Item condition when received
            $table->string('received_condition')->nullable()->comment('good, damaged, defective, etc.');
            $table->text('inspection_note')->nullable();

            // Financial
            $table->bigInteger('refund_amount')->default(0)->comment('Refund amount for this item in minor units');

            // External IDs for platform mapping
            $table->string('external_item_id')->nullable();
            $table->json('platform_data')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('return_id');
            $table->index('order_item_id');
        });

        // Note: Return media/attachments use Spatie Media Library
        // Returns model will use HasMedia trait

        // Return refunds table (track multiple refunds if needed)
        Schema::create('return_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained()->cascadeOnDelete();

            // Refund details
            $table->string('refund_number')->unique();
            $table->bigInteger('amount')->comment('Refund amount in minor units');
            $table->string('currency', 3)->default('TRY');
            $table->string('method')->nullable()->comment('original_payment, store_credit, etc.');
            $table->string('status')->default('pending')->comment('pending, processing, completed, failed');

            // External payment gateway reference
            $table->string('external_refund_id')->nullable();
            $table->string('payment_gateway')->nullable();

            // Dates
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Who processed
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();

            // Notes
            $table->text('note')->nullable();
            $table->text('failure_reason')->nullable();

            // Platform data
            $table->json('platform_data')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('return_id');
            $table->index('refund_number');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_refunds');
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('returns');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('return_status');
        });
    }
};
