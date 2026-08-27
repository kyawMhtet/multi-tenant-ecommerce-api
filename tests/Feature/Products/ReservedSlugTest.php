<?php

use App\Rules\NotReservedSlug;
use Illuminate\Support\Str;

afterEach(function () {
    // Str::random() is globally overridden below to make generation
    // deterministic — must not leak into any other test in the run.
    Str::createRandomStringsNormally();
});

test('reserved words are detected case-insensitively', function () {
    expect(NotReservedSlug::isReserved('checkout'))->toBeTrue()
        ->and(NotReservedSlug::isReserved('CHECKOUT'))->toBeTrue()
        ->and(NotReservedSlug::isReserved('CheckOut'))->toBeTrue();
});

test('an ordinary generated slug is not reserved', function () {
    expect(NotReservedSlug::isReserved('a1b2c3d4'))->toBeFalse();
});

test('the validation rule fails only for a reserved value', function () {
    $failed = null;
    $rule = new NotReservedSlug;

    $rule->validate('slug', 'admin', function (string $message) use (&$failed) {
        $failed = $message;
    });
    expect($failed)->not->toBeNull();

    $failed = null;
    $rule->validate('slug', 'a1b2c3d4', function (string $message) use (&$failed) {
        $failed = $message;
    });
    expect($failed)->toBeNull();
});

test('generating a product variant slug retries past a reserved candidate', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    // Length-targeted, not a fixed positional sequence: Laravel itself
    // calls Str::random() elsewhere during a validated request (the test
    // session store's id, the base Validator's own constructor) with
    // other lengths, which would otherwise silently consume the early
    // slots of a plain sequence before generateVariantSlug() ever runs.
    // Only the first length-8 call (the slug generator's own signature —
    // confirmed via source tracing, nothing else in this codebase asks
    // for exactly 8) gets forced to a reserved word; everything else gets
    // real randomness so this doesn't depend on how many unrelated calls
    // happen elsewhere.
    $reservedAttemptUsed = false;

    Str::createRandomStringsUsing(function (int $length) use (&$reservedAttemptUsed) {
        if ($length === 8 && ! $reservedAttemptUsed) {
            $reservedAttemptUsed = true;

            return 'checkout';
        }

        return substr(bin2hex(random_bytes($length)), 0, $length);
    });

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/products', [
            'name' => 'Reserved Slug Test Product',
            'variant' => [
                'sku' => 'RESERVED-TEST-1',
                'buying_price' => 100,
                'selling_price' => 200,
            ],
        ]);

    $response->assertCreated();

    $slug = $response->json('data.variants.0.slug');

    expect($reservedAttemptUsed)->toBeTrue()
        ->and($slug)->not->toBe('checkout')
        ->and(NotReservedSlug::isReserved($slug))->toBeFalse();
});
