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
        Schema::create('attribute_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('platform'); // trendyol, shopify, etc.
            $table->string('platform_attribute_id')->nullable(); // External attribute ID
            $table->string('platform_attribute_name'); // e.g., "Beden", "Renk"
            $table->foreignId('variant_option_id')->constrained()->cascadeOnDelete();
            $table->json('mapping_rules')->nullable(); // For complex mapping logic
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['platform', 'platform_attribute_name']);
            $table->index(['platform', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attribute_mappings');
    }
};
