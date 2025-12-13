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
        Schema::table('transactions', function (Blueprint $table) {
            // Add currency_id foreign key (nullable for now, will populate then make required)
            $table->foreignId('currency_id')
                ->nullable()
                ->after('currency')
                ->constrained('currencies')
                ->restrictOnDelete();

            // Add exchange_rate - captures the rate at transaction creation time
            // This ensures historical accuracy even if rates change later
            $table->decimal('exchange_rate', 16, 8)
                ->nullable()
                ->after('currency_id')
                ->comment('Exchange rate from transaction currency to account currency at transaction time');

            // Keep existing 'currency' varchar for backward compatibility and fast queries
            // Add index for better performance on GROUP BY currency queries
            $table->index('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['currency']);
            $table->dropForeign(['currency_id']);
            $table->dropColumn(['currency_id', 'exchange_rate']);
        });
    }
};
