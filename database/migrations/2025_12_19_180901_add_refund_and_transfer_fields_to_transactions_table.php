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
            $table->boolean('is_refund')->default(false)->after('description');
            $table->string('refund_type')->nullable()->after('is_refund'); // Will use RefundType enum: 'original' = refunded transaction, 'refund' = the refund itself
            $table->foreignId('linked_transaction_id')->nullable()->constrained('transactions')->nullOnDelete()->after('refund_type'); // Links refund pairs and internal transfers
            $table->boolean('is_internal_transfer')->default(false)->after('linked_transaction_id');

            $table->index(['is_refund', 'refund_type']);
            $table->index('is_internal_transfer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['linked_transaction_id']);
            $table->dropIndex(['is_refund', 'refund_type']);
            $table->dropIndex(['is_internal_transfer']);
            $table->dropColumn(['is_refund', 'refund_type', 'linked_transaction_id', 'is_internal_transfer']);
        });
    }
};
