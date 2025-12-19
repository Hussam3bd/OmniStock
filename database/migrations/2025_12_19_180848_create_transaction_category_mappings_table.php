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
        Schema::create('transaction_category_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('pattern'); // Text to match in description (e.g., "FACEBK", "TRENDYOL")
            $table->string('category'); // Income or Expense category enum value
            $table->string('type'); // Transaction type (will use TransactionType enum)
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete(); // Optional: specific to account
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0); // Lower number = higher priority
            $table->timestamps();

            $table->index(['pattern', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_category_mappings');
    }
};
