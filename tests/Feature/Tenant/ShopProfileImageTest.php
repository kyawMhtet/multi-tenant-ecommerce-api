<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    [$this->tenant, $this->user] = makeTenantUser();
    $this->token = $this->user->createToken('t')->plainTextToken;
});

/**
 * Multipart can't be sent over a real PUT/PATCH, so every image request
 * spoofs the method the same way ProductImageUploadTest does.
 */
function postProfile(array $payload)
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/tenant', array_merge(['_method' => 'PATCH'], $payload));
}

test('uploading a logo stores an optimized file and returns its url', function () {
    postProfile(['logo' => UploadedFile::fake()->image('logo.jpg')])
        ->assertOk()
        ->assertJsonPath('data.logo_url', fn ($url) => is_string($url) && str_contains($url, '/storage/tenants/'));

    $path = $this->tenant->fresh()->logo_path;

    expect($path)->toStartWith('tenants/'.$this->tenant->id.'/');
    Storage::disk('public')->assertExists($path);
});

test('a large logo is resized down to the maximum dimension, never upscaled', function () {
    postProfile(['logo' => UploadedFile::fake()->image('huge.jpg', 3000, 2000)])->assertOk();

    $path = $this->tenant->fresh()->logo_path;
    [$width, $height] = getimagesize(Storage::disk('public')->path($path));

    expect(max($width, $height))->toBeLessThanOrEqual(1600)
        ->and($width / $height)->toEqualWithDelta(3000 / 2000, 0.01);
});

test('a logo over 2048kb is rejected', function () {
    postProfile(['logo' => UploadedFile::fake()->image('big.jpg')->size(3000)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('logo');

    expect($this->tenant->fresh()->logo_path)->toBeNull();
});

test('a non-image file is rejected as a logo', function () {
    postProfile(['logo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')])
        ->assertStatus(422)
        ->assertJsonValidationErrors('logo');

    expect($this->tenant->fresh()->logo_path)->toBeNull();
});

/**
 * A file with a valid image header but a corrupt body passes Laravel's
 * `image` rule (it only sniffs MIME/extension) and fails at decode. The
 * disk must be left completely empty — that's what proves the service's
 * catch block cleaned up the file it had already written before the
 * transaction unwound.
 */
test('a corrupt image is rejected cleanly and leaves no orphaned file', function () {
    $corrupt = UploadedFile::fake()->createWithContent(
        'corrupt.jpg',
        "\xFF\xD8\xFF\xE0".str_repeat('not really an image', 100),
    );

    postProfile(['logo' => $corrupt])->assertStatus(422);

    expect($this->tenant->fresh()->logo_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('replacing a logo deletes the previous file', function () {
    postProfile(['logo' => UploadedFile::fake()->image('first.jpg')])->assertOk();
    $first = $this->tenant->fresh()->logo_path;

    postProfile(['logo' => UploadedFile::fake()->image('second.jpg')])->assertOk();
    $second = $this->tenant->fresh()->logo_path;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

/**
 * This test IS the encoding of the boolean gotcha: multipart sends every
 * field as a string, and Laravel's `boolean` rule accepts only
 * [true, false, 0, 1, '0', '1'] — the literal "true" is not in that list.
 * It passes only because UpdateTenantRequest normalises the flag in
 * prepareForValidation(). If that normalisation is ever removed, this
 * fails rather than silently treating the removal as "no".
 */
test('remove_logo sent as a multipart string clears the logo and deletes the file', function () {
    postProfile(['logo' => UploadedFile::fake()->image('logo.jpg')])->assertOk();
    $path = $this->tenant->fresh()->logo_path;

    postProfile(['remove_logo' => 'true'])->assertOk()
        ->assertJsonPath('data.logo_url', null);

    expect($this->tenant->fresh()->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('remove_logo false is a no-op, not an error', function () {
    postProfile(['logo' => UploadedFile::fake()->image('logo.jpg')])->assertOk();
    $path = $this->tenant->fresh()->logo_path;

    postProfile(['remove_logo' => 'false'])->assertOk();

    expect($this->tenant->fresh()->logo_path)->toBe($path);
    Storage::disk('public')->assertExists($path);
});

test('sending a logo and remove_logo together is rejected', function () {
    postProfile([
        'logo' => UploadedFile::fake()->image('logo.jpg'),
        'remove_logo' => 'true',
    ])->assertStatus(422)->assertJsonValidationErrors('remove_logo');

    expect($this->tenant->fresh()->logo_path)->toBeNull();
});

/**
 * Multipart can't encode an empty array, so a closed day arrives as "" and
 * is nulled by ConvertEmptyStringsToNull before validation ever sees it.
 * Without the per-day coercion in prepareForValidation(), a save that also
 * uploads a logo could not express "closed on Sunday" at all — which is
 * every real settings-form submission.
 */
test('a closed day can be submitted alongside a logo over multipart', function () {
    $hours = [];
    foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $day) {
        $hours[$day] = [['open' => '09:00', 'close' => '18:00']];
    }
    $hours['sun'] = '';

    postProfile([
        'logo' => UploadedFile::fake()->image('logo.jpg'),
        'business_hours' => $hours,
    ])->assertOk()->assertJsonPath('data.business_hours.sun', []);

    expect($this->tenant->fresh()->logo_path)->not->toBeNull();
});

test('logo and cover are independent', function () {
    postProfile(['logo' => UploadedFile::fake()->image('logo.jpg')])->assertOk();
    $logo = $this->tenant->fresh()->logo_path;

    postProfile(['cover' => UploadedFile::fake()->image('cover.jpg')])->assertOk();

    $tenant = $this->tenant->fresh();

    expect($tenant->logo_path)->toBe($logo)
        ->and($tenant->cover_path)->not->toBeNull();
    Storage::disk('public')->assertExists($logo);
    Storage::disk('public')->assertExists($tenant->cover_path);
});

/**
 * Field updates and file writes are one transaction: a corrupt cover must
 * roll back the address change too, and must not disturb an existing logo.
 */
test('a failed image rolls back the field changes in the same request', function () {
    postProfile(['logo' => UploadedFile::fake()->image('logo.jpg')])->assertOk();
    $logo = $this->tenant->fresh()->logo_path;

    postProfile(['address' => 'Original address'])->assertOk();

    postProfile([
        'address' => 'Should not persist',
        'cover' => UploadedFile::fake()->createWithContent(
            'corrupt.jpg',
            "\xFF\xD8\xFF\xE0".str_repeat('not really an image', 100),
        ),
    ])->assertStatus(422);

    $tenant = $this->tenant->fresh();

    expect($tenant->address)->toBe('Original address')
        ->and($tenant->cover_path)->toBeNull()
        ->and($tenant->logo_path)->toBe($logo);
    Storage::disk('public')->assertExists($logo);
});
