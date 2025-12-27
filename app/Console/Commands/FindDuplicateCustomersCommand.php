<?php

namespace App\Console\Commands;

use App\Actions\Customer\MergeCustomersAction;
use App\Models\Customer\Customer;
use Illuminate\Console\Command;

class FindDuplicateCustomersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customers:find-duplicates
                            {--auto-merge : Automatically merge duplicates without confirmation}
                            {--same-channel-only : Only merge customers from the same channel}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and optionally merge duplicate customers based on email, name, or address';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Searching for duplicate customers...');

        $mergeAction = new MergeCustomersAction;
        $duplicates = $mergeAction->findPotentialDuplicates();

        if (empty($duplicates)) {
            $this->info('No duplicate customers found.');

            return self::SUCCESS;
        }

        $sameChannelOnly = $this->option('same-channel-only');
        $autoMerge = $this->option('auto-merge');

        // Filter out groups with different channels if same-channel-only is set
        if ($sameChannelOnly) {
            $duplicates = array_filter($duplicates, function ($group) {
                $allCustomers = Customer::whereIn('id', array_merge([$group['primary_id']], $group['duplicate_ids']))->get();
                $channels = $allCustomers->pluck('channel')->unique();

                return $channels->count() === 1;
            });
        }

        if (empty($duplicates)) {
            $this->info('No duplicate customers found matching the criteria.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Found '.count($duplicates).' groups of potential duplicates:');
        $this->newLine();

        $mergedCount = 0;

        foreach ($duplicates as $index => $group) {
            $groupNumber = $index + 1;
            $this->line("Group #{$groupNumber}: {$group['reason']}");

            $primaryCustomer = Customer::find($group['primary_id']);
            $duplicateCustomers = Customer::whereIn('id', $group['duplicate_ids'])->get();

            $tableData = [];
            $allChannels = collect([$primaryCustomer->channel?->value]);

            // Add primary customer
            $tableData[] = [
                'ID' => $primaryCustomer->id,
                'Name' => $primaryCustomer->first_name.' '.$primaryCustomer->last_name,
                'Email' => $primaryCustomer->email ?? 'N/A',
                'Phone' => $primaryCustomer->phone ?? 'N/A',
                'Channel' => $primaryCustomer->channel?->value ?? 'N/A',
                'Orders' => $primaryCustomer->orders()->count(),
                'Type' => '<fg=green>PRIMARY</>',
            ];

            // Add duplicate customers
            foreach ($duplicateCustomers as $duplicate) {
                $allChannels->push($duplicate->channel?->value);

                $tableData[] = [
                    'ID' => $duplicate->id,
                    'Name' => $duplicate->first_name.' '.$duplicate->last_name,
                    'Email' => $duplicate->email ?? 'N/A',
                    'Phone' => $duplicate->phone ?? 'N/A',
                    'Channel' => $duplicate->channel?->value ?? 'N/A',
                    'Orders' => $duplicate->orders()->count(),
                    'Type' => '<fg=yellow>DUPLICATE</>',
                ];
            }

            $this->table(
                ['ID', 'Name', 'Email', 'Phone', 'Channel', 'Orders', 'Type'],
                $tableData
            );

            // Check if customers are from different channels
            $uniqueChannels = $allChannels->filter()->unique();
            if ($uniqueChannels->count() > 1) {
                $this->warn('⚠ This group has customers from multiple channels: '.implode(', ', $uniqueChannels->toArray()));
                $this->comment('Consider: Same person ordering from different platforms should be merged to have a unified customer profile.');
            }

            // Merge logic
            $shouldMerge = false;

            if ($autoMerge) {
                $shouldMerge = true;
            } else {
                $shouldMerge = $this->confirm('Merge these customers?', false);
            }

            if ($shouldMerge) {
                try {
                    $result = $mergeAction->execute($primaryCustomer, $duplicateCustomers->all());
                    $this->info("✓ Merged {$duplicateCustomers->count()} customer(s) into #{$result->id}");
                    $mergedCount++;
                } catch (\Exception $e) {
                    $this->error("✗ Failed to merge: {$e->getMessage()}");
                }
            }

            $this->newLine();
        }

        $this->newLine();
        $this->info('Total groups found: '.count($duplicates));
        if ($mergedCount > 0) {
            $this->info("Successfully merged: {$mergedCount} groups");
        }

        if (! $autoMerge && $mergedCount < count($duplicates)) {
            $this->comment('You can also merge customers via the Filament admin panel:');
            $this->comment('Go to Customers → Select customers to merge → Actions → Merge Customers');
        }

        return self::SUCCESS;
    }
}
