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
        // Convert accounts.balance
        DB::statement('UPDATE accounts SET balance = ROUND(balance * 100)');
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('balance')->default(0)->comment('Amount in cents')->change();
        });

        // Convert transactions.amount
        DB::statement('UPDATE transactions SET amount = ROUND(amount * 100)');
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('amount')->comment('Amount in cents')->change();
        });

        // Convert orders money fields
        DB::statement('
            UPDATE orders SET
                subtotal = ROUND(subtotal * 100),
                tax_amount = ROUND(tax_amount * 100),
                shipping_amount = ROUND(shipping_amount * 100),
                discount_amount = ROUND(discount_amount * 100),
                total_amount = ROUND(total_amount * 100)
        ');
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('subtotal')->default(0)->comment('Amount in cents')->change();
            $table->unsignedBigInteger('tax_amount')->default(0)->comment('Amount in cents')->change();
            $table->unsignedBigInteger('shipping_amount')->default(0)->comment('Amount in cents')->change();
            $table->unsignedBigInteger('discount_amount')->default(0)->comment('Amount in cents')->change();
            $table->unsignedBigInteger('total_amount')->comment('Amount in cents')->change();
        });

        // Convert order_items money fields
        DB::statement('
            UPDATE order_items SET
                unit_price = ROUND(unit_price * 100),
                total_price = ROUND(total_price * 100)
        ');
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_price')->comment('Amount in cents')->change();
            $table->unsignedBigInteger('total_price')->comment('Amount in cents')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert accounts.balance
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('balance', 15, 2)->default(0)->change();
        });
        DB::statement('UPDATE accounts SET balance = balance / 100');

        // Revert transactions.amount
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->change();
        });
        DB::statement('UPDATE transactions SET amount = amount / 100');

        // Revert orders money fields
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('subtotal', 10, 2)->default(0)->change();
            $table->decimal('tax_amount', 10, 2)->default(0)->change();
            $table->decimal('shipping_amount', 10, 2)->default(0)->change();
            $table->decimal('discount_amount', 10, 2)->default(0)->change();
            $table->decimal('total_amount', 10, 2)->change();
        });
        DB::statement('
            UPDATE orders SET
                subtotal = subtotal / 100,
                tax_amount = tax_amount / 100,
                shipping_amount = shipping_amount / 100,
                discount_amount = discount_amount / 100,
                total_amount = total_amount / 100
        ');

        // Revert order_items money fields
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_price', 10, 2)->change();
            $table->decimal('total_price', 10, 2)->change();
        });
        DB::statement('
            UPDATE order_items SET
                unit_price = unit_price / 100,
                total_price = total_price / 100
        ');
    }
};
