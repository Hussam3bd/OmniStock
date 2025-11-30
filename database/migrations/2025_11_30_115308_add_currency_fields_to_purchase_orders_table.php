<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('location_id')->constrained()->nullOnDelete();
            $table->decimal('exchange_rate', 16, 8)->nullable()->after('currency_id'); // Rate to default currency at order time
        });

        // Set default currency for existing orders
        $defaultCurrency = DB::table('currencies')->where('is_default', true)->first();
        if ($defaultCurrency) {
            DB::table('purchase_orders')->update([
                'currency_id' => $defaultCurrency->id,
                'exchange_rate' => 1.0,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
            $table->dropColumn(['currency_id', 'exchange_rate']);
        });
    }
};
