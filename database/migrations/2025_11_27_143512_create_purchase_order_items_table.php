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
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity_ordered');
            $table->integer('quantity_received')->default(0);
            $table->unsignedBigInteger('unit_cost')->comment('Amount in cents');
            $table->decimal('tax_rate', 5, 2)->default(0)->comment('Tax percentage (e.g., 18.00 for 18%)');
            $table->unsignedBigInteger('subtotal')->comment('Amount in cents');
            $table->unsignedBigInteger('tax_amount')->default(0)->comment('Amount in cents');
            $table->unsignedBigInteger('total')->comment('Amount in cents');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
