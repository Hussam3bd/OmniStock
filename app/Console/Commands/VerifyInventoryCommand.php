<?php

namespace App\Console\Commands;

use App\Enums\Inventory\InventoryMovementType;
use App\Enums\Order\ReturnStatus;
use App\Models\Inventory\InventoryMovement;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;
use App\Models\Product\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyInventoryCommand extends Command
{
    protected $signature = 'inventory:verify
                          {--sku= : Verify specific SKU only}
                          {--detailed : Show detailed movements for each variant}
                          {--missing : Show only variants with missing movements}';

    protected $description = 'Verify inventory accuracy by comparing movements with orders and returns';

    public function handle(): int
    {
        $this->info('🔍 Starting Inventory Verification...');
        $this->newLine();

        $sku = $this->option('sku');
        $detailed = $this->option('detailed');
        $showMissingOnly = $this->option('missing');

        // Get variants to check
        $variantsQuery = ProductVariant::query()->with(['product', 'locationInventories']);

        if ($sku) {
            $variantsQuery->where('sku', $sku);
        }

        $variants = $variantsQuery->get();

        if ($variants->isEmpty()) {
            $this->warn('No variants found to verify.');

            return Command::SUCCESS;
        }

        $this->info("Checking {$variants->count()} variant(s)...");
        $this->newLine();

        $discrepancies = [];
        $totalChecked = 0;
        $totalWithIssues = 0;

        foreach ($variants as $variant) {
            $totalChecked++;
            $result = $this->verifyVariant($variant, $detailed);

            if ($result['has_issues']) {
                $totalWithIssues++;
                $discrepancies[] = $result;
            }

            if (! $showMissingOnly || $result['has_issues']) {
                $this->displayVariantResult($result);
            }
        }

        // Summary
        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 VERIFICATION SUMMARY');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line("Total variants checked: <info>{$totalChecked}</info>");

        if ($totalWithIssues > 0) {
            $this->line("Variants with issues: <fg=red;options=bold>{$totalWithIssues}</>");
            $this->newLine();
            $this->warn('⚠️  Found discrepancies - please review above.');
        } else {
            $this->line('Variants with issues: <fg=green;options=bold>0</> ✅');
            $this->newLine();
            $this->info('✅ All inventory movements match expected values!');
        }

        // Overall statistics
        $this->displayOverallStatistics();

        return $totalWithIssues > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    protected function verifyVariant(ProductVariant $variant, bool $detailed = false): array
    {
        $result = [
            'variant' => $variant,
            'sku' => $variant->sku,
            'name' => $variant->product->name,
            'has_issues' => false,
            'issues' => [],
            'statistics' => [],
            'movements' => [],
        ];

        // Get current inventory
        $currentInventory = $variant->locationInventories->sum('quantity');
        $result['statistics']['current_inventory'] = $currentInventory;

        // Count expected deductions from orders
        $expectedSales = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.product_variant_id', $variant->id)
            ->whereNotIn('orders.order_status', ['cancelled', 'rejected'])
            ->sum('order_items.quantity');

        $result['statistics']['expected_sales'] = $expectedSales;

        // Count actual sale movements
        $actualSales = InventoryMovement::where('product_variant_id', $variant->id)
            ->where('type', InventoryMovementType::Sale->value)
            ->sum(DB::raw('ABS(quantity)'));

        $result['statistics']['actual_sales'] = $actualSales;

        // Count expected returns from completed returns
        $expectedReturns = DB::table('return_items')
            ->join('returns', 'return_items.return_id', '=', 'returns.id')
            ->join('order_items', 'return_items.order_item_id', '=', 'order_items.id')
            ->where('order_items.product_variant_id', $variant->id)
            ->where('returns.status', ReturnStatus::Completed->value)
            ->sum('return_items.quantity');

        $result['statistics']['expected_returns'] = $expectedReturns;

        // Count actual return movements
        $actualReturns = InventoryMovement::where('product_variant_id', $variant->id)
            ->where('type', InventoryMovementType::Return->value)
            ->sum('quantity');

        $result['statistics']['actual_returns'] = $actualReturns;

        // Count purchase order receipts
        $purchaseReceipts = InventoryMovement::where('product_variant_id', $variant->id)
            ->where('type', InventoryMovementType::PurchaseReceived->value)
            ->sum('quantity');

        $result['statistics']['purchase_receipts'] = $purchaseReceipts;

        // Calculate expected inventory
        // Starting from 0 + purchases - sales + returns
        $expectedInventory = $purchaseReceipts - $expectedSales + $expectedReturns;
        $result['statistics']['expected_inventory'] = $expectedInventory;

        // Check for discrepancies
        if ($expectedSales != $actualSales) {
            $result['has_issues'] = true;
            $result['issues'][] = "Sale movements mismatch: Expected {$expectedSales}, Recorded {$actualSales}";
        }

        if ($expectedReturns != $actualReturns) {
            $result['has_issues'] = true;
            $result['issues'][] = "Return movements mismatch: Expected {$expectedReturns}, Recorded {$actualReturns}";
        }

        if ($currentInventory != $expectedInventory) {
            $result['has_issues'] = true;
            $difference = $currentInventory - $expectedInventory;
            $result['issues'][] = "Inventory mismatch: Current {$currentInventory}, Expected {$expectedInventory} (Diff: {$difference})";
        }

        // Get detailed movements if requested
        if ($detailed) {
            $result['movements'] = InventoryMovement::where('product_variant_id', $variant->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn ($m) => [
                    'type' => $m->type->getLabel(),
                    'quantity' => $m->quantity,
                    'reference' => $m->reference ?? 'N/A',
                    'date' => $m->created_at->format('Y-m-d H:i:s'),
                ])
                ->toArray();
        }

        return $result;
    }

    protected function displayVariantResult(array $result): void
    {
        $variant = $result['variant'];
        $stats = $result['statistics'];

        if ($result['has_issues']) {
            $this->warn("❌ {$result['sku']} - {$result['name']}");
        } else {
            $this->info("✅ {$result['sku']} - {$result['name']}");
        }

        // Statistics table
        $this->table(
            ['Metric', 'Value'],
            [
                ['Current Inventory', $stats['current_inventory']],
                ['Expected Inventory', $stats['expected_inventory']],
                ['', ''],
                ['Purchase Receipts', "+{$stats['purchase_receipts']}"],
                ['Expected Sales', "-{$stats['expected_sales']}"],
                ['Actual Sales', "-{$stats['actual_sales']}"],
                ['Expected Returns', "+{$stats['expected_returns']}"],
                ['Actual Returns', "+{$stats['actual_returns']}"],
            ]
        );

        // Display issues
        if (! empty($result['issues'])) {
            foreach ($result['issues'] as $issue) {
                $this->error("  ⚠️  {$issue}");
            }
        }

        // Display movements if detailed
        if (! empty($result['movements'])) {
            $this->newLine();
            $this->line('  <fg=cyan>Movement History:</>');
            $this->table(
                ['Type', 'Quantity', 'Reference', 'Date'],
                array_map(fn ($m) => [
                    $m['type'],
                    $m['quantity'] > 0 ? "+{$m['quantity']}" : $m['quantity'],
                    $m['reference'],
                    $m['date'],
                ], $result['movements'])
            );
        }

        $this->newLine();
    }

    protected function displayOverallStatistics(): void
    {
        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📈 OVERALL STATISTICS');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Total orders
        $totalOrders = Order::whereNotIn('order_status', ['cancelled', 'rejected'])->count();
        $this->line("Total Orders: <info>{$totalOrders}</info>");

        // Total returns
        $totalReturns = OrderReturn::count();
        $completedReturns = OrderReturn::where('status', ReturnStatus::Completed)->count();
        $this->line("Total Returns: <info>{$totalReturns}</info> (Completed: <info>{$completedReturns}</info>)");

        // Pending returns (not completed)
        $pendingReturns = $totalReturns - $completedReturns;
        if ($pendingReturns > 0) {
            $this->warn("  ⚠️  {$pendingReturns} returns are not completed - inventory not restored yet");
        }

        // Total inventory movements
        $totalMovements = InventoryMovement::count();
        $saleMovements = InventoryMovement::where('type', InventoryMovementType::Sale)->count();
        $returnMovements = InventoryMovement::where('type', InventoryMovementType::Return)->count();
        $purchaseMovements = InventoryMovement::where('type', InventoryMovementType::PurchaseReceived)->count();

        $this->newLine();
        $this->line("Total Movements: <info>{$totalMovements}</info>");
        $this->line("  - Sales: <info>{$saleMovements}</info>");
        $this->line("  - Returns: <info>{$returnMovements}</info>");
        $this->line("  - Purchases: <info>{$purchaseMovements}</info>");

        // Check for orders without movements
        $ordersWithoutMovements = DB::table('orders')
            ->whereNotIn('order_status', ['cancelled', 'rejected'])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('inventory_movements')
                    ->whereColumn('inventory_movements.order_id', 'orders.id')
                    ->where('inventory_movements.type', InventoryMovementType::Sale->value);
            })
            ->count();

        if ($ordersWithoutMovements > 0) {
            $this->newLine();
            $this->warn("  ⚠️  {$ordersWithoutMovements} orders don't have inventory movements");
        }

        // Check for completed returns without movements
        $returnsWithoutMovements = DB::table('returns')
            ->where('status', ReturnStatus::Completed->value)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('inventory_movements')
                    ->whereColumn('inventory_movements.order_id', 'returns.order_id')
                    ->where('inventory_movements.type', InventoryMovementType::Return->value);
            })
            ->count();

        if ($returnsWithoutMovements > 0) {
            $this->newLine();
            $this->warn("  ⚠️  {$returnsWithoutMovements} completed returns don't have inventory movements");
        }
    }
}
