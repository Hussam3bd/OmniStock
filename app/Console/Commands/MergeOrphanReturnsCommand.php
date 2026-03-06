<?php

namespace App\Console\Commands;

use App\Models\Order\OrderReturn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MergeOrphanReturnsCommand extends Command
{
    protected $signature = 'returns:merge-orphans
                            {--dry-run : Show what would be deleted without making changes}';

    protected $description = 'Delete duplicate return records caused by multiple sync runs without proper deduplication';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('Scanning for duplicate return records...');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made');
        }

        $this->newLine();

        $toDelete = collect();

        // Case 1: orphan with null external_return_id where a sibling has a real external_return_id.
        // The sibling is authoritative — delete the orphan.
        $case1 = OrderReturn::whereNull('external_return_id')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('returns as r2')
                    ->whereColumn('r2.order_id', 'returns.order_id')
                    ->whereColumn('r2.channel', 'returns.channel')
                    ->whereNotNull('r2.external_return_id');
            })
            ->get();

        $toDelete = $toDelete->merge($case1);

        // Case 2: multiple returns for the same order+channel, all with null external_return_id.
        // Keep the record with the highest total_refund_amount (or the newest if tied).
        // Delete the rest.
        $duplicateGroups = DB::table('returns')
            ->whereNull('external_return_id')
            ->select('order_id', 'channel')
            ->groupBy('order_id', 'channel')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $siblings = OrderReturn::where('order_id', $group->order_id)
                ->where('channel', $group->channel)
                ->whereNull('external_return_id')
                ->orderByDesc('total_refund_amount')
                ->orderByDesc('id')
                ->get();

            // Keep the first (best) record, delete the rest
            $toDelete = $toDelete->merge($siblings->skip(1));
        }

        $toDelete = $toDelete->unique('id');

        if ($toDelete->isEmpty()) {
            $this->info('No duplicate return records found.');

            return self::SUCCESS;
        }

        $this->warn("Found {$toDelete->count()} duplicate return record(s) to remove:");
        $this->newLine();

        $this->table(
            ['Return #', 'Order ID', 'Channel', 'Status', 'Refund Amount', 'Created At'],
            $toDelete->map(fn ($r) => [
                $r->return_number,
                $r->order_id,
                $r->channel,
                $r->status,
                $r->total_refund_amount ?? 0,
                $r->created_at,
            ])
        );

        $this->newLine();

        if ($dryRun) {
            $this->info('Run without --dry-run to delete these records.');

            return self::SUCCESS;
        }

        $deleted = OrderReturn::whereIn('id', $toDelete->pluck('id'))->delete();

        $this->info("Deleted {$deleted} duplicate return record(s).");

        return self::SUCCESS;
    }
}
