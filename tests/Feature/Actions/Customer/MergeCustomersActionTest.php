<?php

use App\Actions\Customer\MergeCustomersAction;
use App\Models\Currency;
use App\Models\Customer\Customer;
use App\Models\Order\Order;
use App\Models\Platform\PlatformMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Currency::create([
        'code' => 'TRY',
        'name' => 'Turkish Lira',
        'symbol' => '₺',
        'exchange_rate' => 1.0,
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->action = new MergeCustomersAction;
});

test('merges duplicate customer into primary customer', function () {
    $primary = Customer::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
    ]);

    $duplicate = Customer::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
    ]);

    $result = $this->action->execute($primary, [$duplicate]);

    expect($result->id)->toBe($primary->id)
        ->and(Customer::find($duplicate->id))->toBeNull(); // Duplicate should be deleted
});

test('migrates orders from duplicate to primary customer', function () {
    $primary = Customer::factory()->create();
    $duplicate = Customer::factory()->create();

    // Create orders for duplicate customer
    $order1 = Order::factory()->create(['customer_id' => $duplicate->id]);
    $order2 = Order::factory()->create(['customer_id' => $duplicate->id]);

    $this->action->execute($primary, [$duplicate]);

    // Orders should now belong to primary customer
    expect($order1->fresh()->customer_id)->toBe($primary->id)
        ->and($order2->fresh()->customer_id)->toBe($primary->id)
        ->and($primary->fresh()->orders()->count())->toBe(2);
});

test('migrates addresses from duplicate to primary customer', function () {
    $primary = Customer::factory()->create();
    $duplicate = Customer::factory()->create();

    // Create addresses for duplicate customer
    $address1 = $duplicate->addresses()->create([
        'type' => 'residential',
        'first_name' => 'Test',
        'last_name' => 'User',
        'address_line1' => '123 Test St',
    ]);

    $address2 = $duplicate->addresses()->create([
        'type' => 'residential',
        'first_name' => 'Test',
        'last_name' => 'User',
        'address_line1' => '456 Another St',
    ]);

    $this->action->execute($primary, [$duplicate]);

    // Addresses should now belong to primary customer
    expect($address1->fresh()->addressable_id)->toBe($primary->id)
        ->and($address2->fresh()->addressable_id)->toBe($primary->id)
        ->and($primary->fresh()->addresses()->count())->toBe(2);
});

test('migrates platform mappings from duplicate to primary customer', function () {
    $primary = Customer::factory()->create();
    $duplicate = Customer::factory()->create();

    // Create platform mapping for duplicate
    $mapping = $duplicate->platformMappings()->create([
        'platform' => 'trendyol',
        'platform_id' => '12345',
        'entity_type' => Customer::class,
        'platform_data' => ['test' => 'data'],
    ]);

    $this->action->execute($primary, [$duplicate]);

    // Platform mapping should now belong to primary customer
    expect($mapping->fresh()->entity_id)->toBe($primary->id)
        ->and($primary->fresh()->platformMappings()->count())->toBe(1);
});

test('deletes duplicate platform mapping if primary already has same platform', function () {
    $primary = Customer::factory()->create();
    $duplicate = Customer::factory()->create();

    // Both have same platform
    $primaryMapping = $primary->platformMappings()->create([
        'platform' => 'trendyol',
        'platform_id' => '12345',
        'entity_type' => Customer::class,
    ]);

    $duplicateMapping = $duplicate->platformMappings()->create([
        'platform' => 'trendyol',
        'platform_id' => '67890',
        'entity_type' => Customer::class,
    ]);

    $this->action->execute($primary, [$duplicate]);

    // Primary keeps its mapping, duplicate's mapping is deleted
    expect(PlatformMapping::find($primaryMapping->id))->not->toBeNull()
        ->and(PlatformMapping::find($duplicateMapping->id))->toBeNull()
        ->and($primary->fresh()->platformMappings()->count())->toBe(1);
});

test('fills missing email from duplicate customer', function () {
    $primary = Customer::factory()->create([
        'email' => null,
    ]);

    $duplicate = Customer::factory()->create([
        'email' => 'found@example.com',
    ]);

    $result = $this->action->execute($primary, [$duplicate]);

    expect($result->email)->toBe('found@example.com');
});

test('fills missing phone from duplicate customer', function () {
    $primary = Customer::factory()->create([
        'phone' => null,
    ]);

    $duplicate = Customer::factory()->create([
        'phone' => '+905551234567',
    ]);

    $result = $this->action->execute($primary, [$duplicate]);

    expect($result->phone)->toBe('+905551234567');
});

test('appends notes from duplicate customer', function () {
    $primary = Customer::factory()->create([
        'notes' => 'Primary customer notes',
    ]);

    $duplicate = Customer::factory()->create([
        'notes' => 'Duplicate customer notes',
    ]);

    $result = $this->action->execute($primary, [$duplicate]);

    expect($result->notes)->toContain('Primary customer notes')
        ->and($result->notes)->toContain('Duplicate customer notes')
        ->and($result->notes)->toContain('[Merged from customer #'.$duplicate->id.']');
});

test('does not append placeholder notes', function () {
    $primary = Customer::factory()->create([
        'notes' => null,
    ]);

    $duplicate = Customer::factory()->create([
        'notes' => 'Customer data pending from Trendyol',
    ]);

    $result = $this->action->execute($primary, [$duplicate]);

    expect($result->notes)->toBeNull();
});

test('merges multiple duplicate customers at once', function () {
    $primary = Customer::factory()->create();
    $duplicate1 = Customer::factory()->create();
    $duplicate2 = Customer::factory()->create();

    Order::factory()->create(['customer_id' => $duplicate1->id]);
    Order::factory()->create(['customer_id' => $duplicate2->id]);

    $result = $this->action->execute($primary, [$duplicate1, $duplicate2]);

    expect($result->orders()->count())->toBe(2)
        ->and(Customer::find($duplicate1->id))->toBeNull()
        ->and(Customer::find($duplicate2->id))->toBeNull();
});

test('logs merge activity', function () {
    $primary = Customer::factory()->create();
    $duplicate = Customer::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane@example.com',
    ]);

    Order::factory()->count(3)->create(['customer_id' => $duplicate->id]);

    $this->action->execute($primary, [$duplicate]);

    $activity = \Spatie\Activitylog\Models\Activity::query()
        ->where('subject_type', Customer::class)
        ->where('subject_id', $primary->id)
        ->where('description', 'customer_merged')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['merged_customer_id'])->toBe($duplicate->id)
        ->and($activity->properties['merged_customer_name'])->toBe('Jane Smith')
        ->and($activity->properties['merged_customer_email'])->toBe('jane@example.com')
        ->and($activity->properties['orders_migrated'])->toBe(3);
});

test('findPotentialDuplicates finds customers with same email', function () {
    Customer::factory()->create(['email' => 'duplicate@example.com']);
    Customer::factory()->create(['email' => 'duplicate@example.com']);
    Customer::factory()->create(['email' => 'unique@example.com']);

    $duplicates = $this->action->findPotentialDuplicates();

    expect($duplicates)->toHaveCount(1)
        ->and($duplicates[0]['reason'])->toContain('Same email')
        ->and($duplicates[0]['duplicate_ids'])->toHaveCount(1);
});

test('findPotentialDuplicates finds customers with same name', function () {
    Customer::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john1@example.com',
    ]);

    Customer::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john2@example.com',
    ]);

    $duplicates = $this->action->findPotentialDuplicates();

    expect($duplicates)->toHaveCount(1)
        ->and($duplicates[0]['reason'])->toContain('Same name')
        ->and($duplicates[0]['duplicate_ids'])->toHaveCount(1);
});

test('findPotentialDuplicates excludes masked data', function () {
    Customer::factory()->create([
        'first_name' => '***',
        'last_name' => '***',
        'email' => '***',
    ]);

    Customer::factory()->create([
        'first_name' => '***',
        'last_name' => '***',
        'email' => '***',
    ]);

    $duplicates = $this->action->findPotentialDuplicates();

    expect($duplicates)->toBeEmpty();
});

test('findPotentialDuplicates excludes placeholder customers', function () {
    Customer::factory()->create([
        'first_name' => 'Trendyol',
        'last_name' => 'Customer',
    ]);

    Customer::factory()->create([
        'first_name' => 'Trendyol',
        'last_name' => 'Customer',
    ]);

    $duplicates = $this->action->findPotentialDuplicates();

    expect($duplicates)->toBeEmpty();
});
