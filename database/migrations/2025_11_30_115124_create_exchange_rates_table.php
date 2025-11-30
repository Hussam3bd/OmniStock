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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->foreignId('to_currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->decimal('rate', 16, 8); // High precision for exchange rates
            $table->date('effective_date'); // Date this rate is effective from
            $table->timestamps();

            // Ensure only one rate per currency pair per date
            $table->unique(['from_currency_id', 'to_currency_id', 'effective_date'], 'exchange_rates_unique');

            // Indexes for faster lookups
            $table->index(['from_currency_id', 'to_currency_id', 'effective_date'], 'exchange_rates_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
