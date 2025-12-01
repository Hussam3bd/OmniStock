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
        Schema::create('product_channel_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->cascadeOnDelete();
            $table->string('channel'); // trendyol, shopify, portal, etc.
            $table->boolean('is_enabled')->default(true);
            $table->json('channel_settings')->nullable(); // Channel-specific settings
            $table->timestamps();

            // Ensure a variant can only have one entry per channel
            $table->unique(['product_variant_id', 'channel']);

            // Index for querying by channel
            $table->index(['channel', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_channel_availability');
    }
};
