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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('barcode')->unique();
            $table->string('title')->nullable();
            $table->string('option1')->nullable(); // e.g., Color
            $table->string('option2')->nullable(); // e.g., Size
            $table->string('option3')->nullable(); // e.g., Material
            $table->decimal('price', 10, 2);
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->integer('inventory_quantity')->default(0);
            $table->decimal('weight', 10, 2)->nullable();
            $table->string('weight_unit')->default('kg');
            $table->boolean('requires_shipping')->default(true);
            $table->boolean('taxable')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
