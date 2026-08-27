<?php

test('a user can log in with valid credentials and receives a token', function () {
    [$tenant, $user] = makeTenantUser();

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.tenant_id', $tenant->id)
        ->assertJsonPath('data.tenant_slug', $tenant->slug)
        ->assertJsonStructure(['data' => ['id', 'name', 'email', 'role', 'tenant_id', 'tenant_slug'], 'token']);
});

test('login fails with an invalid password', function () {
    [, $user] = makeTenantUser();

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('login fails for an email that does not exist', function () {
    $response = $this->postJson('/api/v1/login', [
        'email' => 'nobody@nowhere.test',
        'password' => 'password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('a logged in user can log out and the token is revoked', function () {
    [, $user] = makeTenantUser();

    $token = $user->createToken('api-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/logout');

    $response->assertOk();

    expect($user->tokens()->count())->toBe(0);
});
