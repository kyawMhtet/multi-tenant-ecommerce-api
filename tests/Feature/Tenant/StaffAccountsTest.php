<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function addStaff(\App\Models\Tenant $tenant, string $role, string $email, string $name = 'Staff'): User
{
    return User::create([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'email' => $email,
        'password' => Hash::make('password'),
        'role' => $role,
    ]);
}

test('an owner can add staff and sees the seat count', function () {
    [$tenant, $owner] = makeTenantUser();
    // The trial grants Pro (unlimited seats), so the plan under test is set
    // explicitly rather than inherited from config.
    $tenant->subscription->update(['plan' => 'starter']);
    $token = $owner->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff', [
            'name' => 'Mya Mya',
            'email' => 'mya@shop.test',
            'password' => 'password123',
            'role' => 'cashier',
        ])
        ->assertCreated()
        ->assertJsonPath('data.role', 'cashier')
        ->assertJsonPath('data.role_label', 'Cashier');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.used', 2)
        ->assertJsonPath('meta.limit', 3);
});

test('starter refuses a fourth login, counting the owner', function () {
    [$tenant, $owner] = makeTenantUser();
    $tenant->subscription->update(['plan' => 'starter']);
    addStaff($tenant, 'cashier', 'a@shop.test');
    addStaff($tenant, 'cashier', 'b@shop.test');

    $token = $owner->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff', [
            'name' => 'Fourth',
            'email' => 'c@shop.test',
            'password' => 'password123',
            'role' => 'cashier',
        ])
        ->assertStatus(402)
        ->assertJsonPath('reason', 'plan_limit_exceeded')
        ->assertJsonPath('maximum', 3);

    expect($tenant->users()->count())->toBe(3);
});

test('pro has no seat ceiling', function () {
    [$tenant, $owner] = makeTenantUser();
    $tenant->subscription->update(['plan' => 'pro']);
    addStaff($tenant, 'cashier', 'a@shop.test');
    addStaff($tenant, 'cashier', 'b@shop.test');

    $token = $owner->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff', [
            'name' => 'Fourth',
            'email' => 'c@shop.test',
            'password' => 'password123',
            'role' => 'cashier',
        ])
        ->assertCreated();
});

test('a cashier cannot manage staff', function () {
    [$tenant] = makeTenantUser();
    $cashier = addStaff($tenant, 'cashier', 'cash@shop.test');
    $token = $cashier->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff')
        ->assertStatus(403)
        ->assertJsonPath('reason', 'insufficient_role')
        ->assertJsonPath('required_role', 'owner');
});

test('a shop cannot be left without an owner', function () {
    [$tenant, $owner] = makeTenantUser();
    $second = addStaff($tenant, 'owner', 'second@shop.test');
    $token = $owner->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/staff/{$second->id}", ['role' => 'cashier'])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/staff/{$second->id}", ['role' => 'manager'])
        ->assertOk();

    // $owner is now the only owner left, and demoting themselves is refused
    // separately from the last-owner rule.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/staff/{$owner->id}", ['role' => 'manager'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'staff_action_unavailable');

    expect($owner->fresh()->role)->toBe('owner');
});

test('an owner cannot remove their own account', function () {
    [$tenant, $owner] = makeTenantUser();
    $token = $owner->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/{$owner->id}")
        ->assertStatus(422);

    expect($tenant->users()->count())->toBe(1);
});

test('removing staff revokes their tokens immediately', function () {
    [$tenant, $owner] = makeTenantUser();
    $cashier = addStaff($tenant, 'cashier', 'cash@shop.test');
    $cashier->createToken('t');

    expect($cashier->tokens()->count())->toBe(1);

    $token = $owner->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/{$cashier->id}")
        ->assertNoContent();

    expect(User::find($cashier->id))->toBeNull()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $cashier->id)->count())->toBe(0);
});

test('tenant A cannot see, edit or remove tenant B staff', function () {
    [$tenantA, $ownerA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $staffB = addStaff($tenantB, 'cashier', 'b-cashier@shop.test', 'B Cashier');
    addStaff($tenantA, 'cashier', 'a-cashier@shop.test', 'A Cashier');

    $tokenA = $ownerA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/staff')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissing(['name' => 'B Cashier']);

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->patchJson("/api/v1/staff/{$staffB->id}", ['name' => 'Hijacked'])
        ->assertNotFound();

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->deleteJson("/api/v1/staff/{$staffB->id}")
        ->assertNotFound();

    expect($staffB->fresh()->name)->toBe('B Cashier');
});

test('another shop staff count does not consume this shop seats', function () {
    [$tenantA, $ownerA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    addStaff($tenantB, 'cashier', 'b1@shop.test');
    addStaff($tenantB, 'cashier', 'b2@shop.test');

    $tokenA = $ownerA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/staff', [
            'name' => 'A Staff',
            'email' => 'a1@shop.test',
            'password' => 'password123',
            'role' => 'cashier',
        ])
        ->assertCreated();
});

test('staff email must be globally unique', function () {
    [$tenant, $owner] = makeTenantUser();
    $token = $owner->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff', [
            'name' => 'Clash',
            'email' => $owner->email,
            'password' => 'password123',
            'role' => 'cashier',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('a new staff member can log in with the password the owner set', function () {
    [$tenant, $owner] = makeTenantUser();
    $token = $owner->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff', [
            'name' => 'Mya Mya',
            'email' => 'mya@shop.test',
            'password' => 'password123',
            'role' => 'manager',
        ])
        ->assertCreated();

    $this->postJson('/api/v1/login', [
        'email' => 'mya@shop.test',
        'password' => 'password123',
    ])->assertOk()->assertJsonPath('data.role', 'manager');
});
