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
            $table->string('currency_code', 3)->nullable()->after('currency_id');
        });

        // Populate currency_code from currency relationship
        $orders = DB::table('purchase_orders')->get();
        foreach ($orders as $order) {
            if ($order->currency_id) {
                $currency = DB::table('currencies')->find($order->currency_id);
                if ($currency) {
                    DB::table('purchase_orders')
                        ->where('id', $order->id)
                        ->update(['currency_code' => $currency->code]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });
    }
};
