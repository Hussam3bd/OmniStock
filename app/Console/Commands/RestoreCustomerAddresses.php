<?php

namespace App\Console\Commands;

use App\Models\Address\Address;
use App\Models\Customer\Customer;
use App\Models\Order\Order;
use Illuminate\Console\Command;

class RestoreCustomerAddresses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:migrate-to-address-snapshots {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate orders to use address snapshots (create copies of customer addresses for each order)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in DRY RUN mode - no changes will be made');
        }

        $this->info('Migrating orders to use address snapshots...');

        // Get all orders that have addresses
        $orders = Order::query()
            ->whereNotNull('customer_id')
            ->where(function ($query) {
                $query->whereNotNull('shipping_address_id')
                    ->orWhereNotNull('billing_address_id');
            })
            ->with(['shippingAddress', 'billingAddress', 'customer'])
            ->get();

        $this->info("Found {$orders->count()} orders to process");

        $migratedCount = 0;
        $skippedCount = 0;
        $progressBar = $this->output->createProgressBar($orders->count());
        $progressBar->start();

        foreach ($orders as $order) {
            if (! $order->customer) {
                $progressBar->advance();

                continue;
            }

            $needsMigration = false;

            // Check shipping address
            if ($order->shipping_address_id && $order->shippingAddress) {
                $shippingAddress = $order->shippingAddress;

                // If address belongs to customer, we need to create a snapshot
                if ($shippingAddress->addressable_type === Customer::class) {
                    if (! $dryRun) {
                        // Create snapshot
                        $snapshot = $shippingAddress->replicate();
                        $snapshot->addressable_type = Order::class;
                        $snapshot->addressable_id = $order->id;
                        $snapshot->is_default = false;
                        $snapshot->save();

                        // Update order to use snapshot
                        $order->update(['shipping_address_id' => $snapshot->id]);
                    }

                    $needsMigration = true;
                }
            }

            // Check billing address
            if ($order->billing_address_id && $order->billingAddress) {
                $billingAddress = $order->billingAddress;

                // Skip if it's the same as shipping (already handled)
                if ($order->billing_address_id === $order->shipping_address_id) {
                    // Update to use the new snapshot
                    if (! $dryRun && $needsMigration) {
                        $order->update(['billing_address_id' => $order->shipping_address_id]);
                    }
                } elseif ($billingAddress->addressable_type === Customer::class) {
                    if (! $dryRun) {
                        // Create snapshot
                        $snapshot = $billingAddress->replicate();
                        $snapshot->addressable_type = Order::class;
                        $snapshot->addressable_id = $order->id;
                        $snapshot->is_default = false;
                        $snapshot->save();

                        // Update order to use snapshot
                        $order->update(['billing_address_id' => $snapshot->id]);
                    }

                    $needsMigration = true;
                }
            }

            if ($needsMigration) {
                $migratedCount++;
            } else {
                $skippedCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Migration Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Migrated', $migratedCount],
                ['Skipped (already snapshots)', $skippedCount],
            ]
        );

        if ($dryRun) {
            $this->warn('This was a DRY RUN - no changes were made');
            $this->info('Run without --dry-run to apply changes');
        } else {
            $this->info('Migration completed successfully!');
        }

        return self::SUCCESS;
    }
}
