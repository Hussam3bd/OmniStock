<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rate_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('e.g., "Trendyol Rates Q1 2025"');
            $table->date('effective_from')->comment('Date when rates become active');
            $table->date('effective_until')->nullable()->comment('Date when rates expire (null = current)');
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Ensure only one active rate table at a time
            $table->unique(['is_active'], 'unique_active_rate_table')
                ->where('is_active', true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rate_tables');
    }
};
