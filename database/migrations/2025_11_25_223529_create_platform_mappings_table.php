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
        Schema::create('platform_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('platform'); // shopify, trendyol, etc.
            $table->string('entity_type'); // product, product_variant, customer, order
            $table->unsignedBigInteger('entity_id');
            $table->string('platform_id'); // The ID on the external platform
            $table->json('platform_data')->nullable(); // Additional platform-specific data
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'entity_type', 'entity_id']);
            $table->unique(['platform', 'entity_type', 'platform_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_mappings');
    }
};
