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
        Schema::create('imported_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('source_type'); // Will use ImportSourceType enum
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('external_reference')->nullable(); // Dekont No for bank, null for credit card
            $table->string('transaction_hash'); // MD5 of date+amount+description for deduplication
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->timestamp('imported_at');
            $table->timestamps();

            $table->unique(['account_id', 'transaction_hash']);
            $table->index(['account_id', 'external_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imported_transactions');
    }
};
