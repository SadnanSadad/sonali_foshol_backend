<?php

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DemoDataSeeder::class);
    $this->admin = User::query()->where('role', 'superadmin')->firstOrFail();
});

it('logs in an active admin by email or username', function () {
    $this->postJson('/api/auth/admin/login', [
        'login' => 'admin@sonalifoshol.com',
        'password' => 'sonali123',
    ])->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'admin@sonalifoshol.com')
        ->assertJsonPath('data.user.role', 'superadmin')
        ->assertJsonMissingPath('data.user.password');

    $this->assertAuthenticatedAs($this->admin, 'admin');

    $this->getJson('/api/auth/admin/me')
        ->assertOk()
        ->assertJsonPath('data.username', 'admin');

    $this->postJson('/api/auth/admin/logout')->assertOk();
    $this->assertGuest('admin');
});

it('rejects wrong credentials and inactive accounts with the same message', function () {
    $this->postJson('/api/auth/admin/login', [
        'login' => 'admin@sonalifoshol.com',
        'password' => 'wrong-password',
    ])->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');

    $inactive = User::factory()->create([
        'email' => 'inactive@example.com',
        'username' => 'inactive',
        'is_active' => false,
        'password' => 'password123',
    ]);

    $this->postJson('/api/auth/admin/login', [
        'login' => $inactive->email,
        'password' => 'password123',
    ])->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
});

it('requires admin authentication on admin routes', function () {
    $this->getJson('/api/admin/products')->assertStatus(401);
});

it('blocks staff from users and settings updates', function () {
    $staff = User::factory()->create([
        'email' => 'staff@example.com',
        'username' => 'staff',
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($staff, 'admin');

    $this->getJson('/api/admin/products')->assertOk();
    $this->getJson('/api/admin/users')->assertForbidden();
    $this->putJson('/api/admin/settings', ['storeName' => 'Nope'])->assertForbidden();
});

it('lets a superadmin create and manage admin users', function () {
    $this->actingAs($this->admin, 'admin');

    $created = $this->postJson('/api/admin/users', [
        'name' => 'New Staff',
        'email' => 'new.staff@example.com',
        'username' => 'newstaff',
        'password' => 'longpassword123',
        'password_confirmation' => 'longpassword123',
        'role' => 'admin',
    ])->assertCreated()
        ->assertJsonPath('data.role', 'admin');

    $this->getJson('/api/admin/users')->assertOk()->assertJsonCount(2, 'data');

    $this->patchJson("/api/admin/users/{$created->json('data.id')}", [
        'isActive' => false,
    ])->assertOk()->assertJsonPath('data.isActive', false);

    $this->postJson("/api/admin/users/{$created->json('data.id')}/reset-password", [
        'password' => 'anotherpassword123',
        'password_confirmation' => 'anotherpassword123',
    ])->assertOk()->assertJsonPath('data.success', true);
});

it('registers, logs in and logs out a customer', function () {
    $this->postJson('/api/auth/customer/register', [
        'name' => 'Customer One',
        'phone' => '01912345678',
        'email' => 'customer@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'district' => 'Dhaka',
        'address' => 'Dhanmondi, Dhaka',
    ])->assertCreated()->assertJsonPath('data.email', 'customer@example.com');

    $this->assertAuthenticated('customer');

    $this->postJson('/api/auth/customer/logout')->assertOk();
    $this->assertGuest('customer');

    $this->postJson('/api/auth/customer/login', [
        'email' => 'customer@example.com',
        'password' => 'password123',
    ])->assertOk()->assertJsonPath('data.phone', '01912345678');

    $this->getJson('/api/auth/customer/me')->assertOk()->assertJsonPath('data.name', 'Customer One');

    $this->postJson('/api/auth/customer/login', [
        'email' => 'customer@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(401);
});

it('rejects guest checkout customers from customer order history', function () {
    $this->getJson('/api/storefront/customer/orders')->assertStatus(401);
});
