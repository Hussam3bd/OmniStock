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
        Schema::create('attribute_value_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_mapping_id')->constrained()->cascadeOnDelete();
            $table->string('platform_value'); // Original value from platform (e.g., "Kahverengi", "38")
            $table->foreignId('variant_option_value_id')->nullable()->constrained()->nullOnDelete();
            $table->string('normalized_value'); // Normalized value for matching (e.g., "brown", "38")
            $table->boolean('is_verified')->default(false); // Admin verified this mapping
            $table->timestamps();

            $table->unique(['attribute_mapping_id', 'platform_value'], 'attr_val_map_unique');
            $table->index(['attribute_mapping_id', 'normalized_value'], 'attr_val_map_norm_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attribute_value_mappings');
    }
};
