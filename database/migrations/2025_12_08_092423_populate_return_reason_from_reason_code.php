<?php

use App\Enums\Order\ReturnReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Migrate existing data from reason_code to return_reason
        $returns = DB::table('returns')
            ->whereNotNull('reason_code')
            ->whereNull('return_reason')
            ->get();

        foreach ($returns as $return) {
            $reasonCode = $return->reason_code;

            // Try to map from Trendyol code first
            $returnReason = ReturnReason::fromTrendyolCode($reasonCode);

            // If no Trendyol match, try text-based fuzzy matching from reason_name
            if (! $returnReason && $return->reason_name) {
                $returnReason = ReturnReason::fromText($return->reason_name);
            }

            // If still no match, use OTHER
            if (! $returnReason) {
                $returnReason = ReturnReason::OTHER;
            }

            DB::table('returns')
                ->where('id', $return->id)
                ->update(['return_reason' => $returnReason->value]);
        }
    }

    public function down(): void
    {
        // Clear return_reason values
        DB::table('returns')->update(['return_reason' => null]);
    }
};
