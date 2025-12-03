<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_rate_table_id')->constrained('shipping_rate_tables')->cascadeOnDelete();
            $table->string('carrier')->comment('Shipping carrier (aras, dhl, ptt, etc.)');
            $table->decimal('desi_from', 8, 2)->comment('Minimum desi/weight');
            $table->decimal('desi_to', 8, 2)->nullable()->comment('Maximum desi/weight (null = unlimited)');
            $table->bigInteger('price_excluding_vat')->comment('Price in minor units (cents) excluding VAT');
            $table->decimal('vat_rate', 5, 2)->default(20.00)->comment('VAT rate percentage');
            $table->boolean('is_heavy_cargo')->default(false)->comment('Heavy cargo (100+ desi)');
            $table->timestamps();

            // Indexes for fast lookups
            $table->index(['shipping_rate_table_id', 'carrier', 'desi_from', 'desi_to'], 'rate_lookup_idx');
            $table->index(['carrier', 'is_heavy_cargo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
