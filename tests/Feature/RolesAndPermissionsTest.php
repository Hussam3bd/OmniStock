<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create all permissions that shield:generate would create
    $resources = [
        'Account', 'Address', 'Currency', 'Customer', 'ExchangeRate',
        'Integration', 'Invoice', 'Location', 'Order', 'OrderReturn',
        'Product', 'PurchaseOrder', 'Supplier', 'Transaction',
        'TransactionCategoryMapping', 'VariantOption',
    ];

    $actions = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete',
        'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny',
        'Replicate', 'Reorder',
    ];

    foreach ($resources as $resource) {
        foreach ($actions as $action) {
            Permission::create(['name' => "{$action}:{$resource}", 'guard_name' => 'web']);
        }
    }

    $pages = [
        'FinancialReports', 'InventoryAnalytics', 'ReorderRecommendations',
        'InventoryReport', 'OrdersReport', 'BarcodeSettings', 'SmsSettings',
        'EditProfilePage',
    ];

    $widgets = [
        'AccountBalancesWidget', 'CategoryBreakdownWidget', 'FinancialStatsOverview',
        'ChannelSalesBreakdown', 'LowStockStatsWidget', 'NetProfitByChannelChart',
        'OrdersByChannelChart', 'OrdersOverTimeChart', 'OrderStatsOverview',
        'ReturnsByChannelChart', 'ReturnsStatsWidget', 'RevenueByChannelChart',
        'RevenueChartWidget',
    ];

    foreach ([...$pages, ...$widgets] as $entity) {
        Permission::create(['name' => "View:{$entity}", 'guard_name' => 'web']);
    }

    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->seed(RolesAndPermissionsSeeder::class);
});

it('creates all seven roles', function () {
    $expectedRoles = [
        'super_admin',
        'operations_manager',
        'accountant',
        'customer_success',
        'warehouse_staff',
        'catalog_manager',
        'viewer',
    ];

    foreach ($expectedRoles as $roleName) {
        expect(Role::where('name', $roleName)->exists())->toBeTrue("Role '{$roleName}' should exist");
    }

    expect(Role::count())->toBe(7);
});

it('assigns super_admin to test user when seeded', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::where('email', 'test@example.com')->first();
    expect($user->hasRole('super_admin'))->toBeTrue();
});

it('allows super_admin to bypass all permission checks via gate', function () {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    expect($user->can('ViewAny:Order'))->toBeTrue();
    expect($user->can('Delete:Account'))->toBeTrue();
    expect($user->can('some_nonexistent_ability'))->toBeTrue();
});

it('allows users with a role to access the panel', function () {
    $user = User::factory()->create();
    $user->assignRole('viewer');

    $panel = filament()->getPanel('admin');
    expect($user->canAccessPanel($panel))->toBeTrue();
});

it('denies panel access to users without any role', function () {
    $user = User::factory()->create();

    $panel = filament()->getPanel('admin');
    expect($user->canAccessPanel($panel))->toBeFalse();
});

it('gives operations_manager full access to order-related resources', function () {
    $user = User::factory()->create();
    $user->assignRole('operations_manager');

    expect($user->can('ViewAny:Order'))->toBeTrue();
    expect($user->can('Create:Order'))->toBeTrue();
    expect($user->can('Update:Order'))->toBeTrue();
    expect($user->can('Delete:Order'))->toBeTrue();
    expect($user->can('ViewAny:Product'))->toBeTrue();
    expect($user->can('ViewAny:PurchaseOrder'))->toBeTrue();
    expect($user->can('ViewAny:Location'))->toBeTrue();
});

it('denies operations_manager access to accounting resources', function () {
    $user = User::factory()->create();
    $user->assignRole('operations_manager');

    expect($user->can('ViewAny:Account'))->toBeFalse();
    expect($user->can('Create:Transaction'))->toBeFalse();
    expect($user->can('ViewAny:Invoice'))->toBeFalse();
});

it('gives accountant full access to financial resources', function () {
    $user = User::factory()->create();
    $user->assignRole('accountant');

    expect($user->can('ViewAny:Account'))->toBeTrue();
    expect($user->can('Create:Account'))->toBeTrue();
    expect($user->can('ViewAny:Transaction'))->toBeTrue();
    expect($user->can('ViewAny:Invoice'))->toBeTrue();
    expect($user->can('ViewAny:TransactionCategoryMapping'))->toBeTrue();
});

it('denies accountant access to order resources', function () {
    $user = User::factory()->create();
    $user->assignRole('accountant');

    expect($user->can('ViewAny:Order'))->toBeFalse();
    expect($user->can('Create:Product'))->toBeFalse();
});

it('gives warehouse_staff view-only access to products', function () {
    $user = User::factory()->create();
    $user->assignRole('warehouse_staff');

    expect($user->can('ViewAny:Product'))->toBeTrue();
    expect($user->can('View:Product'))->toBeTrue();
    expect($user->can('Create:Product'))->toBeFalse();
    expect($user->can('Update:Product'))->toBeFalse();
    expect($user->can('Delete:Product'))->toBeFalse();
});

it('gives warehouse_staff view and update access to purchase orders', function () {
    $user = User::factory()->create();
    $user->assignRole('warehouse_staff');

    expect($user->can('ViewAny:PurchaseOrder'))->toBeTrue();
    expect($user->can('Update:PurchaseOrder'))->toBeTrue();
    expect($user->can('Create:PurchaseOrder'))->toBeFalse();
    expect($user->can('Delete:PurchaseOrder'))->toBeFalse();
});

it('gives catalog_manager full access to products and variant options', function () {
    $user = User::factory()->create();
    $user->assignRole('catalog_manager');

    expect($user->can('ViewAny:Product'))->toBeTrue();
    expect($user->can('Create:Product'))->toBeTrue();
    expect($user->can('Update:Product'))->toBeTrue();
    expect($user->can('Delete:Product'))->toBeTrue();
    expect($user->can('ViewAny:VariantOption'))->toBeTrue();
    expect($user->can('Create:VariantOption'))->toBeTrue();
});

it('gives catalog_manager view-only access to suppliers', function () {
    $user = User::factory()->create();
    $user->assignRole('catalog_manager');

    expect($user->can('ViewAny:Supplier'))->toBeTrue();
    expect($user->can('View:Supplier'))->toBeTrue();
    expect($user->can('Create:Supplier'))->toBeFalse();
    expect($user->can('Update:Supplier'))->toBeFalse();
});

it('gives viewer only read-only permissions across all resources', function () {
    $user = User::factory()->create();
    $user->assignRole('viewer');

    $viewerRole = Role::findByName('viewer', 'web');
    $permissions = $viewerRole->permissions->pluck('name');

    $permissions->each(function (string $permission) {
        expect($permission)->toMatch('/^View(Any)?:/');
    });

    expect($user->can('ViewAny:Order'))->toBeTrue();
    expect($user->can('ViewAny:Account'))->toBeTrue();
    expect($user->can('ViewAny:Product'))->toBeTrue();

    expect($user->can('Create:Order'))->toBeFalse();
    expect($user->can('Update:Account'))->toBeFalse();
    expect($user->can('Delete:Product'))->toBeFalse();
});
