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
        Schema::table('returns', function (Blueprint $table) {
            // Add currency_id foreign key (nullable for backward compatibility)
            $table->foreignId('currency_id')
                ->nullable()
                ->after('currency')
                ->constrained('currencies')
                ->restrictOnDelete();

            // Add exchange_rate - captures the rate at return creation time
            // Usually copied from the parent order
            $table->decimal('exchange_rate', 16, 8)
                ->nullable()
                ->after('currency_id')
                ->comment('Exchange rate from return currency to default currency at return time');

            // Add index for currency varchar for analytics
            $table->index('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropIndex(['currency']);
            $table->dropForeign(['currency_id']);
            $table->dropColumn(['currency_id', 'exchange_rate']);
        });
    }
};
