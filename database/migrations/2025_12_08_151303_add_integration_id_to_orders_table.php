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
        Schema::table('orders', function (Blueprint $table) {
            // Add integration_id foreign key after channel
            // Nullable for backwards compatibility with existing orders
            $table->foreignId('integration_id')
                ->nullable()
                ->after('channel')
                ->constrained('integrations')
                ->nullOnDelete();

            // Add composite index for efficient querying by channel and integration
            $table->index(['channel', 'integration_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Drop index first
            $table->dropIndex(['channel', 'integration_id']);

            // Drop foreign key and column
            $table->dropForeign(['integration_id']);
            $table->dropColumn('integration_id');
        });
    }
};
