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
            $table->foreignId('currency_id')->nullable()->after('type')->constrained()->nullOnDelete();

            // Migrate existing currency codes to currency_id
            // This will run after the column is added
        });

        // Migrate data: convert currency codes to currency_id
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('
                UPDATE accounts
                SET currency_id = (SELECT id FROM currencies WHERE currencies.code = accounts.currency)
            ');
        } else {
            DB::statement('
                UPDATE accounts a
                INNER JOIN currencies c ON a.currency = c.code
                SET a.currency_id = c.id
            ');
        }

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('currency')->default('TRY')->after('type');
        });

        // Migrate data back: convert currency_id to currency code
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('
                UPDATE accounts
                SET currency = (SELECT code FROM currencies WHERE currencies.id = accounts.currency_id)
            ');
        } else {
            DB::statement('
                UPDATE accounts a
                INNER JOIN currencies c ON a.currency_id = c.id
                SET a.currency = c.code
            ');
        }

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
            $table->dropColumn('currency_id');
        });
    }
};
