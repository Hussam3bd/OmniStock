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
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique(); // ISO 4217 currency code (USD, EUR, TRY)
            $table->string('name'); // Full currency name (US Dollar, Euro, Turkish Lira)
            $table->string('symbol', 10); // Currency symbol ($, €, ₺)
            $table->unsignedTinyInteger('decimal_places')->default(2); // Decimal precision
            $table->boolean('is_default')->default(false); // Default currency for the system
            $table->boolean('is_active')->default(true); // Active/inactive currencies
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
