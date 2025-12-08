<?php

use App\Enums\Order\ReturnReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add return_reason column
        Schema::table('return_items', function (Blueprint $table) {
            $table->string('return_reason')->nullable()->after('quantity');
            $table->index('return_reason');
        });

        // Migrate existing data
        $items = DB::table('return_items')
            ->whereNotNull('reason_code')
            ->whereNull('return_reason')
            ->get();

        foreach ($items as $item) {
            $reasonCode = $item->reason_code;

            // Try to map from Trendyol code
            $returnReason = ReturnReason::fromTrendyolCode($reasonCode);

            // If no match, try text-based fuzzy matching from reason_name
            if (! $returnReason && $item->reason_name) {
                $returnReason = ReturnReason::fromText($item->reason_name);
            }

            // If still no match, use OTHER
            if (! $returnReason) {
                $returnReason = ReturnReason::OTHER;
            }

            DB::table('return_items')
                ->where('id', $item->id)
                ->update(['return_reason' => $returnReason->value]);
        }

        // Drop reason_code column
        Schema::table('return_items', function (Blueprint $table) {
            $table->dropColumn('reason_code');
        });
    }

    public function down(): void
    {
        // Restore reason_code column
        Schema::table('return_items', function (Blueprint $table) {
            $table->string('reason_code')->nullable()->after('quantity');
        });

        // Drop return_reason
        Schema::table('return_items', function (Blueprint $table) {
            $table->dropIndex(['return_reason']);
            $table->dropColumn('return_reason');
        });
    }
};
