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
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('currency_id');
        });

        // Populate currency_code from currency relationship
        $accounts = DB::table('accounts')->get();
        foreach ($accounts as $account) {
            if ($account->currency_id) {
                $currency = DB::table('currencies')->find($account->currency_id);
                if ($currency) {
                    DB::table('accounts')
                        ->where('id', $account->id)
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
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });
    }
};
