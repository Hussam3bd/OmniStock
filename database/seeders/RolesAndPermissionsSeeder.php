<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /** @var list<string> */
    private const RESOURCE_ACTIONS = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete',
        'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny',
        'Replicate', 'Reorder',
    ];

    /** @var list<string> */
    private const VIEW_ONLY_ACTIONS = ['ViewAny', 'View'];

    /** @var list<string> */
    private const VIEW_UPDATE_ACTIONS = ['ViewAny', 'View', 'Update'];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->createRoles();
        $this->assignPermissions();
        $this->assignSuperAdminToTestUser();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function createRoles(): void
    {
        $roles = [
            'super_admin',
            'operations_manager',
            'accountant',
            'customer_success',
            'warehouse_staff',
            'catalog_manager',
            'viewer',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function assignPermissions(): void
    {
        $this->assignOperationsManagerPermissions();
        $this->assignAccountantPermissions();
        $this->assignCustomerSuccessPermissions();
        $this->assignWarehouseStaffPermissions();
        $this->assignCatalogManagerPermissions();
        $this->assignViewerPermissions();
    }

    private function assignOperationsManagerPermissions(): void
    {
        $resources = ['Order', 'OrderReturn', 'Customer', 'Product', 'VariantOption', 'PurchaseOrder', 'Supplier', 'Location'];

        $pages = ['OrdersReport', 'InventoryReport', 'InventoryAnalytics', 'ReorderRecommendations'];

        $widgets = [
            'OrderStatsOverview', 'OrdersByChannelChart', 'OrdersOverTimeChart',
            'RevenueByChannelChart', 'RevenueChartWidget', 'NetProfitByChannelChart',
            'ChannelSalesBreakdown', 'ReturnsByChannelChart', 'ReturnsStatsWidget',
            'LowStockStatsWidget',
        ];

        $permissions = [
            ...$this->fullResourcePermissions($resources),
            ...$this->pagePermissions($pages),
            ...$this->widgetPermissions($widgets),
        ];

        $this->syncRolePermissions('operations_manager', $permissions);
    }

    private function assignAccountantPermissions(): void
    {
        $resources = ['Account', 'Transaction', 'TransactionCategoryMapping', 'Invoice'];

        $pages = ['FinancialReports'];

        $widgets = [
            'AccountBalancesWidget', 'CategoryBreakdownWidget', 'FinancialStatsOverview',
            'RevenueByChannelChart', 'RevenueChartWidget', 'NetProfitByChannelChart',
        ];

        $permissions = [
            ...$this->fullResourcePermissions($resources),
            ...$this->pagePermissions($pages),
            ...$this->widgetPermissions($widgets),
        ];

        $this->syncRolePermissions('accountant', $permissions);
    }

    private function assignCustomerSuccessPermissions(): void
    {
        $fullResources = ['Customer', 'Order', 'OrderReturn', 'Address'];

        $pages = ['OrdersReport'];

        $widgets = [
            'OrderStatsOverview', 'OrdersByChannelChart', 'OrdersOverTimeChart',
            'ReturnsByChannelChart', 'ReturnsStatsWidget',
        ];

        $permissions = [
            ...$this->fullResourcePermissions($fullResources),
            ...$this->pagePermissions($pages),
            ...$this->widgetPermissions($widgets),
        ];

        $this->syncRolePermissions('customer_success', $permissions);
    }

    private function assignWarehouseStaffPermissions(): void
    {
        $fullResources = ['Location'];
        $viewUpdateResources = ['PurchaseOrder'];
        $viewOnlyResources = ['Product'];

        $pages = ['InventoryReport', 'InventoryAnalytics', 'ReorderRecommendations'];

        $widgets = ['LowStockStatsWidget'];

        $permissions = [
            ...$this->fullResourcePermissions($fullResources),
            ...$this->resourcePermissions($viewUpdateResources, self::VIEW_UPDATE_ACTIONS),
            ...$this->resourcePermissions($viewOnlyResources, self::VIEW_ONLY_ACTIONS),
            ...$this->pagePermissions($pages),
            ...$this->widgetPermissions($widgets),
        ];

        $this->syncRolePermissions('warehouse_staff', $permissions);
    }

    private function assignCatalogManagerPermissions(): void
    {
        $fullResources = ['Product', 'VariantOption'];
        $viewOnlyResources = ['Supplier'];

        $permissions = [
            ...$this->fullResourcePermissions($fullResources),
            ...$this->resourcePermissions($viewOnlyResources, self::VIEW_ONLY_ACTIONS),
        ];

        $this->syncRolePermissions('catalog_manager', $permissions);
    }

    private function assignViewerPermissions(): void
    {
        $allResources = [
            'Account', 'Address', 'Currency', 'Customer', 'ExchangeRate',
            'Integration', 'Invoice', 'Location', 'Order', 'OrderReturn',
            'Product', 'PurchaseOrder', 'Supplier', 'Transaction',
            'TransactionCategoryMapping', 'VariantOption',
        ];

        $allPages = [
            'FinancialReports', 'InventoryAnalytics', 'ReorderRecommendations',
            'InventoryReport', 'OrdersReport',
        ];

        $allWidgets = [
            'AccountBalancesWidget', 'CategoryBreakdownWidget', 'FinancialStatsOverview',
            'ChannelSalesBreakdown', 'LowStockStatsWidget', 'NetProfitByChannelChart',
            'OrdersByChannelChart', 'OrdersOverTimeChart', 'OrderStatsOverview',
            'ReturnsByChannelChart', 'ReturnsStatsWidget', 'RevenueByChannelChart',
            'RevenueChartWidget',
        ];

        $permissions = [
            ...$this->resourcePermissions($allResources, self::VIEW_ONLY_ACTIONS),
            ...$this->pagePermissions($allPages),
            ...$this->widgetPermissions($allWidgets),
        ];

        $this->syncRolePermissions('viewer', $permissions);
    }

    private function assignSuperAdminToTestUser(): void
    {
        $emails = ['test@example.com', 'husamettin@revanstep.com'];

        User::whereIn('email', $emails)->each(function (User $user) {
            $user->assignRole('super_admin');
        });
    }

    /**
     * @param  list<string>  $resources
     * @return list<string>
     */
    private function fullResourcePermissions(array $resources): array
    {
        return $this->resourcePermissions($resources, self::RESOURCE_ACTIONS);
    }

    /**
     * @param  list<string>  $resources
     * @param  list<string>  $actions
     * @return list<string>
     */
    private function resourcePermissions(array $resources, array $actions): array
    {
        $permissions = [];

        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $permissions[] = "{$action}:{$resource}";
            }
        }

        return $permissions;
    }

    /**
     * @param  list<string>  $pages
     * @return list<string>
     */
    private function pagePermissions(array $pages): array
    {
        return array_map(fn (string $page): string => "View:{$page}", $pages);
    }

    /**
     * @param  list<string>  $widgets
     * @return list<string>
     */
    private function widgetPermissions(array $widgets): array
    {
        return array_map(fn (string $widget): string => "View:{$widget}", $widgets);
    }

    private function syncRolePermissions(string $roleName, array $permissions): void
    {
        $existingPermissions = Permission::whereIn('name', $permissions)->pluck('name')->all();

        Role::findByName($roleName, 'web')->syncPermissions($existingPermissions);
    }
}
