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
        // Only run if carrier column exists (production databases)
        // In fresh databases (tests), these columns were never created
        if (! Schema::hasColumn('orders', 'carrier') && ! Schema::hasColumn('returns', 'carrier')) {
            return;
        }

        // Migrate data from carrier to shipping_carrier in orders table
        if (Schema::hasColumn('orders', 'carrier')) {
            DB::statement('UPDATE orders SET shipping_carrier = carrier WHERE carrier IS NOT NULL AND shipping_carrier IS NULL');

            // Drop index first (may not exist in all environments)
            try {
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropIndex('orders_carrier_index');
                });
            } catch (\Exception $e) {
                // Index doesn't exist, continue
            }

            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('carrier');
            });
        }

        // Migrate data from carrier to return_shipping_carrier in returns table
        if (Schema::hasColumn('returns', 'carrier')) {
            DB::statement('UPDATE returns SET return_shipping_carrier = carrier WHERE carrier IS NOT NULL AND return_shipping_carrier IS NULL');

            // Drop index first (may not exist in all environments)
            try {
                Schema::table('returns', function (Blueprint $table) {
                    $table->dropIndex('returns_carrier_index');
                });
            } catch (\Exception $e) {
                // Index doesn't exist, continue
            }

            Schema::table('returns', function (Blueprint $table) {
                $table->dropColumn('carrier');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add carrier column to orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->string('carrier')->nullable()->after('shipping_desi');
            $table->index('carrier');
        });

        // Re-add carrier column to returns table
        Schema::table('returns', function (Blueprint $table) {
            $table->string('carrier')->nullable()->after('return_shipping_desi');
            $table->index('carrier');
        });

        // Migrate data back from shipping_carrier to carrier in orders table
        DB::statement('UPDATE orders SET carrier = shipping_carrier WHERE shipping_carrier IS NOT NULL');

        // Migrate data back from return_shipping_carrier to carrier in returns table
        DB::statement('UPDATE returns SET carrier = return_shipping_carrier WHERE return_shipping_carrier IS NOT NULL');
    }
};
