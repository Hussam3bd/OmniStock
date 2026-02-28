<?php

use App\Filament\Resources\User\Users\Pages\CreateUser;
use App\Filament\Resources\User\Users\Pages\EditUser;
use App\Filament\Resources\User\Users\Pages\ListUsers;
use App\Filament\Resources\User\Users\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole(
        Role::create(['name' => 'super_admin', 'guard_name' => 'web'])
    );
    $this->actingAs($this->admin);
});

it('can render the list page', function () {
    Livewire::test(ListUsers::class)
        ->assertSuccessful();
});

it('can list users', function () {
    $users = User::factory()->count(3)->create();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords($users);
});

it('can render the create page', function () {
    Livewire::test(CreateUser::class)
        ->assertSuccessful();
});

it('can create a user', function () {
    $role = Role::create(['name' => 'viewer', 'guard_name' => 'web']);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'john@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('John Doe');
    expect($user->hasRole('viewer'))->toBeTrue();
});

it('requires name and email on create', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => '',
            'email' => '',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required', 'email' => 'required']);
});

it('requires password on create', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => '',
            'password_confirmation' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['password' => 'required']);
});

it('validates unique email on create', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'John Doe',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique']);
});

it('can render the edit page', function () {
    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->assertSuccessful();
});

it('can update a user', function () {
    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm([
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();
    expect($user->name)->toBe('Updated Name');
    expect($user->email)->toBe('updated@example.com');
});

it('does not require password on edit', function () {
    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm([
            'name' => 'Updated Name',
            'email' => $user->email,
            'password' => '',
            'password_confirmation' => '',
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('allows the same email for the current user on edit', function () {
    $user = User::factory()->create(['email' => 'same@example.com']);

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm([
            'name' => 'Updated Name',
            'email' => 'same@example.com',
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('can search users by name', function () {
    $matchingUser = User::factory()->create(['name' => 'Searchable User']);
    $otherUser = User::factory()->create(['name' => 'Other Person']);

    Livewire::test(ListUsers::class)
        ->searchTable('Searchable')
        ->assertCanSeeTableRecords([$matchingUser])
        ->assertCanNotSeeTableRecords([$otherUser]);
});

it('can filter users by role', function () {
    $role = Role::create(['name' => 'accountant', 'guard_name' => 'web']);
    $userWithRole = User::factory()->create();
    $userWithRole->assignRole($role);
    $userWithoutRole = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->filterTable('roles', $role->id)
        ->assertCanSeeTableRecords([$userWithRole])
        ->assertCanNotSeeTableRecords([$userWithoutRole]);
});

it('shows the resource in the Settings navigation group', function () {
    expect(UserResource::getNavigationGroup())->toBe('Settings');
});
