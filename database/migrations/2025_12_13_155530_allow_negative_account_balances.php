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
        Schema::table('accounts', function (Blueprint $table) {
            // Change balance from unsigned to signed to allow negative balances
            // This is important for credit cards (negative = debt) and overdrafts
            $table->bigInteger('balance')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Revert back to unsigned (only if all balances are positive)
            $table->unsignedBigInteger('balance')->change();
        });
    }
};
