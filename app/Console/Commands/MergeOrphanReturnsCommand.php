<?php

namespace App\Console\Commands;

use App\Models\Order\OrderReturn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MergeOrphanReturnsCommand extends Command
{
    protected $signature = 'returns:merge-orphans
                            {--dry-run : Show what would be deleted without making changes}';

    protected $description = 'Delete orphan return records (null external_return_id) that have a duplicate with a real external_return_id for the same order';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('Scanning for orphan return records...');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made');
        }

        $this->newLine();

        // Find returns with no external_return_id where the same order+channel
        // already has at least one return WITH an external_return_id.
        $orphans = OrderReturn::whereNull('external_return_id')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('returns as r2')
                    ->whereColumn('r2.order_id', 'returns.order_id')
                    ->whereColumn('r2.channel', 'returns.channel')
                    ->whereNotNull('r2.external_return_id');
            })
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('No orphan return records found.');

            return self::SUCCESS;
        }

        $this->warn("Found {$orphans->count()} orphan return record(s):");
        $this->newLine();

        $this->table(
            ['Return #', 'Order ID', 'Channel', 'Status', 'Created At'],
            $orphans->map(fn ($r) => [
                $r->return_number,
                $r->order_id,
                $r->channel,
                $r->status,
                $r->created_at,
            ])
        );

        $this->newLine();

        if ($dryRun) {
            $this->info('Run without --dry-run to delete these orphan records.');

            return self::SUCCESS;
        }

        $deleted = OrderReturn::whereIn('id', $orphans->pluck('id'))->delete();

        $this->info("Deleted {$deleted} orphan return record(s).");

        return self::SUCCESS;
    }
}
