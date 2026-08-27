<?php

use App\Models\Tenant;

function shopHours(array $overrides = []): array
{
    return array_merge([
        'mon' => [['open' => '09:00', 'close' => '18:00']],
        'tue' => [['open' => '09:00', 'close' => '18:00']],
        'wed' => [['open' => '09:00', 'close' => '18:00']],
        'thu' => [['open' => '09:00', 'close' => '18:00']],
        'fri' => [['open' => '09:00', 'close' => '18:00']],
        'sat' => [['open' => '10:00', 'close' => '16:00']],
        'sun' => [],
    ], $overrides);
}

beforeEach(function () {
    [$this->tenant, $this->user] = makeTenantUser();
    $this->token = $this->user->createToken('t')->plainTextToken;
});

function patchProfile(array $payload)
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->patchJson('/api/v1/tenant', $payload);
}

test('updates contact fields and returns them', function () {
    patchProfile([
        'name' => 'Yangon Mini Mart',
        'address' => "No. 123, 5th Floor\nKamayut Township, Yangon",
        'business_phone' => '09123456789',
        'business_email' => 'shop@yangonmart.test',
    ])->assertOk()
        ->assertJsonPath('data.name', 'Yangon Mini Mart')
        ->assertJsonPath('data.business_phone', '09123456789')
        ->assertJsonPath('data.business_email', 'shop@yangonmart.test');

    expect($this->tenant->fresh()->address)->toContain('Kamayut Township');
});

/**
 * The most important test here: settings is a shared bucket, so a
 * shop-profile save that assigned the whole blob would silently wipe any
 * other feature's keys. Saving hours + social, then patching an unrelated
 * plain column, must leave both untouched.
 */
test('a partial patch leaves other settings keys untouched', function () {
    patchProfile([
        'business_hours' => shopHours(),
        'social_links' => ['facebook' => 'https://facebook.com/myshop'],
    ])->assertOk();

    patchProfile(['address' => 'Somewhere else'])->assertOk();

    $settings = $this->tenant->fresh()->settings;

    expect($settings['business_hours']['mon'][0]['open'])->toBe('09:00')
        ->and($settings['social_links']['facebook'])->toBe('https://facebook.com/myshop');
});

test('business_hours null clears hours but leaves social links intact', function () {
    patchProfile([
        'business_hours' => shopHours(),
        'social_links' => ['facebook' => 'https://facebook.com/myshop'],
    ])->assertOk();

    patchProfile(['business_hours' => null])->assertOk();

    $settings = $this->tenant->fresh()->settings;

    expect($settings)->not->toHaveKey('business_hours')
        ->and($settings['social_links']['facebook'])->toBe('https://facebook.com/myshop');
});

test('a null social link removes only that platform', function () {
    patchProfile(['social_links' => [
        'facebook' => 'https://facebook.com/myshop',
        'tiktok' => 'https://tiktok.com/@myshop',
    ]])->assertOk();

    patchProfile(['social_links' => ['facebook' => null]])->assertOk();

    $links = $this->tenant->fresh()->settings['social_links'];

    expect($links)->not->toHaveKey('facebook')
        ->and($links['tiktok'])->toBe('https://tiktok.com/@myshop');
});

test('an empty day list round-trips as closed', function () {
    patchProfile(['business_hours' => shopHours(['sun' => []])])->assertOk()
        ->assertJsonPath('data.business_hours.sun', []);
});

test('accepts split shifts', function () {
    patchProfile(['business_hours' => shopHours([
        'tue' => [['open' => '09:00', 'close' => '12:00'], ['open' => '14:00', 'close' => '20:00']],
    ])])->assertOk()
        ->assertJsonPath('data.business_hours.tue.1.open', '14:00');
});

test('rejects invalid business hours', function (array $hours) {
    patchProfile(['business_hours' => $hours])
        ->assertStatus(422)
        ->assertJsonValidationErrors('business_hours');
})->with([
    'unknown day key' => [fn () => shopHours(['funday' => []])],
    'missing a day' => [fn () => collect(shopHours())->except('sun')->all()],
]);

test('rejects three intervals in one day', function () {
    patchProfile(['business_hours' => shopHours(['mon' => [
        ['open' => '08:00', 'close' => '10:00'],
        ['open' => '11:00', 'close' => '13:00'],
        ['open' => '14:00', 'close' => '16:00'],
    ]])])->assertStatus(422)->assertJsonValidationErrors('business_hours.mon');
});

test('rejects a closing time at or before the opening time', function () {
    patchProfile(['business_hours' => shopHours([
        'mon' => [['open' => '18:00', 'close' => '09:00']],
    ])])->assertStatus(422)->assertJsonValidationErrors('business_hours.mon.0.close');
});

test('rejects a malformed time', function () {
    patchProfile(['business_hours' => shopHours([
        'mon' => [['open' => '9am', 'close' => '6pm']],
    ])])->assertStatus(422)->assertJsonValidationErrors('business_hours.mon.0.open');
});

/**
 * A social link is rendered into an <a href> on the public storefront, so a
 * javascript: URL here is stored XSS, not just bad data.
 */
test('rejects a javascript: social link', function () {
    patchProfile(['social_links' => ['facebook' => 'javascript:alert(document.cookie)']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('social_links.facebook');
});

test('rejects a plain http social link', function () {
    patchProfile(['social_links' => ['facebook' => 'http://facebook.com/myshop']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('social_links.facebook');
});

test('rejects an unknown social platform', function () {
    patchProfile(['social_links' => ['myspace' => 'https://myspace.com/myshop']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('social_links');
});

test('accepts a viber phone number but rejects a viber url', function () {
    patchProfile(['social_links' => ['viber_phone' => '09123456789']])->assertOk()
        ->assertJsonPath('data.social_links.viber_phone', '09123456789');

    patchProfile(['social_links' => ['viber_phone' => 'https://evil.test']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('social_links.viber_phone');
});

/**
 * The layered mass-assignment defence (Form Request whitelist + the
 * service's Arr::only + Tenant's trimmed $fillable) is only real if
 * something proves it.
 */
test('ignores attempts to change slug, plan, subscription_status or is_active', function () {
    // Read from the DB, not the in-memory instance: plan and
    // subscription_status come from column defaults, so they're still null
    // on the object Tenant::create() returned.
    $before = $this->tenant->fresh()->only(['slug', 'plan', 'subscription_status', 'is_active']);

    patchProfile([
        'name' => 'Renamed',
        'slug' => 'hijacked-slug',
        'plan' => 'enterprise',
        'subscription_status' => 'active',
        'is_active' => false,
    ])->assertOk();

    expect($this->tenant->fresh()->only(['slug', 'plan', 'subscription_status', 'is_active']))
        ->toBe($before);
});

test('requires authentication', function () {
    $this->patchJson('/api/v1/tenant', ['name' => 'Nope'])->assertUnauthorized();
});

test('tenant A updating its own profile never touches tenant B', function () {
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );
    $before = $tenantB->only(['name', 'address', 'business_phone']);

    patchProfile(['name' => 'Tenant A Renamed', 'address' => 'A street'])->assertOk();

    expect(Tenant::find($tenantB->id)->only(['name', 'address', 'business_phone']))->toBe($before);
});

/**
 * The decisive isolation case for this endpoint. PATCH /api/v1/tenant takes
 * no tenant identifier at all — it writes to app('tenant'), which
 * ResolveTenant derives from the token owner and never from the header on
 * an authenticated request. Sending someone else's slug must therefore be
 * inert, not a redirect of the write.
 */
test('an X-Tenant-Slug header naming another tenant is ignored on an authenticated write', function () {
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->withHeader('X-Tenant-Slug', $tenantB->slug)
        ->patchJson('/api/v1/tenant', ['name' => 'Written By A'])
        ->assertOk();

    expect($this->tenant->fresh()->name)->toBe('Written By A')
        ->and(Tenant::find($tenantB->id)->name)->not->toBe('Written By A');
});
