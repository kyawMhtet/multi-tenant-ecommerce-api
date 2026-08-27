<?php

use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

test('creating a product with one image stores an optimized file and returns its url', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/products', [
            'name' => 'Product With Image',
            'variant' => ['sku' => 'IMG-1', 'buying_price' => 100, 'selling_price' => 200],
            'images' => [UploadedFile::fake()->image('photo.jpg', 2000, 2000)],
        ]);

    $response->assertCreated()->assertJsonCount(1, 'data.images');

    $image = ProductImage::firstOrFail();
    Storage::disk('public')->assertExists($image->path);

    expect($response->json('data.images.0.url'))->toContain($image->path)
        ->and($image->sort_order)->toBe(0)
        ->and($image->tenant_id)->toBe($tenant->id);
});

test('creating a product accepts multiple images in one request, in order', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/products', [
            'name' => 'Product With Gallery',
            'variant' => ['sku' => 'IMG-2', 'buying_price' => 100, 'selling_price' => 200],
            'images' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
                UploadedFile::fake()->image('three.jpg'),
            ],
        ]);

    $response->assertCreated()->assertJsonCount(3, 'data.images');

    expect(ProductImage::orderBy('sort_order')->pluck('sort_order')->all())->toBe([0, 1, 2]);
});

test('an image over 2048kb is rejected', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/products', [
            'name' => 'Product With Big Image',
            'variant' => ['sku' => 'IMG-3', 'buying_price' => 100, 'selling_price' => 200],
            // ->size() only overrides the reported size for validation —
            // it doesn't inflate the real file, so this stays a fast,
            // genuinely tiny fake image on disk.
            'images' => [UploadedFile::fake()->image('big.jpg')->size(3000)],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('images.0');

    expect(ProductImage::count())->toBe(0);
});

test('a non-image file is rejected', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/products', [
            'name' => 'Product With Bad File',
            'variant' => ['sku' => 'IMG-4', 'buying_price' => 100, 'selling_price' => 200],
            'images' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('images.0');
});

/**
 * The `image` validation rule only sniffs MIME type/extension via the
 * file's header bytes — it doesn't guarantee the rest of the file is a
 * real, intact image. This uses UploadedFile::fake()->create() with a
 * genuine JPEG magic-byte header (so the `image` rule passes) followed
 * by garbage, to prove the later real-decode failure is caught cleanly
 * rather than crashing.
 */
test('a file with a valid image header but corrupt body is rejected cleanly, not a 500', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $corrupt = UploadedFile::fake()->createWithContent(
        'corrupt.jpg',
        "\xFF\xD8\xFF\xE0\x00\x10JFIF".str_repeat('garbage', 20),
    );

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/products', [
            'name' => 'Product With Corrupt Image',
            'variant' => ['sku' => 'IMG-CORRUPT-1', 'buying_price' => 100, 'selling_price' => 200],
            'images' => [$corrupt],
        ]);

    $response->assertStatus(422);

    // createProduct() wraps product + variant + images in one
    // transaction — a failure partway through addImages() must roll
    // back the whole thing, not leave an orphaned product with no
    // images.
    expect(\App\Models\Product::where('name', 'Product With Corrupt Image')->count())->toBe(0)
        ->and(ProductImage::count())->toBe(0);
});

test('updating a product with new images appends to, rather than replaces, existing ones', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$product->id}", [
            '_method' => 'PUT',
            'images' => [UploadedFile::fake()->image('first.jpg')],
        ])->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$product->id}", [
            '_method' => 'PUT',
            'images' => [UploadedFile::fake()->image('second.jpg')],
        ])->assertOk();

    $images = ProductImage::where('product_id', $product->id)->orderBy('sort_order')->get();

    expect($images)->toHaveCount(2)
        ->and($images->pluck('sort_order')->all())->toBe([0, 1]);
});

test('a large image is resized down to the maximum dimension, never upscaled', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/products', [
            'name' => 'Product With Huge Image',
            'variant' => ['sku' => 'IMG-5', 'buying_price' => 100, 'selling_price' => 200],
            'images' => [UploadedFile::fake()->image('huge.jpg', 3000, 2000)],
        ])->assertCreated();

    $image = ProductImage::firstOrFail();
    [$width, $height] = getimagesize(Storage::disk('public')->path($image->path));

    expect(max($width, $height))->toBeLessThanOrEqual(1600)
        ->and($width / $height)->toEqualWithDelta(3000 / 2000, 0.01);
});

/**
 * Tenant B's product+image is created directly through ProductService
 * under a bound tenant context, not an authenticated HTTP call — a
 * second authenticated call with a different user's Bearer token in the
 * same test would silently still resolve as the first (Sanctum's guard
 * caches the resolved user for the rest of the test process; see the
 * createPosOrderForTenant() helper docblock in tests/Pest.php for the
 * full explanation). Only the one HTTP call this test needs to prove —
 * fetching tenant B's product as tenant A — goes through the real
 * request/auth stack.
 */
test('a tenant cannot see another tenant product images', function () {
    [$tenantA, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    app()->instance('tenant', $tenantB);
    $productB = app(\App\Services\ProductService::class)->createProduct([
        'name' => 'Tenant B Product',
        'variant' => ['sku' => 'IMG-B-1', 'buying_price' => 100, 'selling_price' => 200],
        'images' => [UploadedFile::fake()->image('b.jpg')],
    ]);
    app()->forgetInstance('tenant');

    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->getJson("/api/v1/products/{$productB->id}")
        ->assertNotFound();

    expect(ProductImage::withoutGlobalScope(\App\Models\Concerns\TenantScope::class)->count())->toBe(1);
});

test('removing an image via update removes the db row and the stored file', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $productId = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/products', [
            'name' => 'Product To Trim',
            'variant' => ['sku' => 'DEL-1', 'buying_price' => 100, 'selling_price' => 200],
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ])->assertCreated()
        ->json('data.id');

    $images = ProductImage::where('product_id', $productId)->get();
    expect($images)->toHaveCount(2);
    $toRemove = $images->first();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$productId}", [
            '_method' => 'PUT',
            'remove_image_ids' => [$toRemove->id],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'data.images');

    expect(ProductImage::where('product_id', $productId)->count())->toBe(1)
        ->and(ProductImage::find($toRemove->id))->toBeNull();
    Storage::disk('public')->assertMissing($toRemove->path);
});

test('a failed update rolls back both the field change and any removal in the same request', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $productId = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/products', [
            'name' => 'Product Before Failed Update',
            'variant' => ['sku' => 'ATOMIC-1', 'buying_price' => 100, 'selling_price' => 200],
            'images' => [UploadedFile::fake()->image('keep.jpg')],
        ])->assertCreated()->json('data.id');

    $existingImage = ProductImage::where('product_id', $productId)->firstOrFail();

    $corrupt = UploadedFile::fake()->createWithContent(
        'corrupt.jpg',
        "\xFF\xD8\xFF\xE0\x00\x10JFIF".str_repeat('garbage', 20),
    );

    // Renames the product, removes the existing image, AND adds a
    // corrupt one that fails to decode — the failure happens last, so
    // this proves the earlier two steps (already applied autocommit-free
    // inside the same DB transaction) get rolled back too, not just the
    // failing step.
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$productId}", [
            '_method' => 'PUT',
            'name' => 'Renamed During Failed Update',
            'remove_image_ids' => [$existingImage->id],
            'images' => [$corrupt],
        ]);

    $response->assertStatus(422);

    $product = \App\Models\Product::findOrFail($productId);
    expect($product->name)->toBe('Product Before Failed Update')
        ->and(ProductImage::find($existingImage->id))->not->toBeNull();
    Storage::disk('public')->assertExists($existingImage->path);
});

test('an update can add and remove images in the same request', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $productId = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/products', [
            'name' => 'Product To Swap',
            'variant' => ['sku' => 'SWAP-1', 'buying_price' => 100, 'selling_price' => 200],
            'images' => [UploadedFile::fake()->image('old.jpg')],
        ])->assertCreated()->json('data.id');

    $oldImage = ProductImage::where('product_id', $productId)->firstOrFail();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$productId}", [
            '_method' => 'PUT',
            'remove_image_ids' => [$oldImage->id],
            'images' => [UploadedFile::fake()->image('new.jpg')],
        ]);

    $response->assertOk()->assertJsonCount(1, 'data.images');

    $remaining = ProductImage::where('product_id', $productId)->get();
    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->id)->not->toBe($oldImage->id);
    Storage::disk('public')->assertMissing($oldImage->path);
});

test('removing an image that belongs to a different product is rejected', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $productAId = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/products', [
            'name' => 'Product A',
            'variant' => ['sku' => 'DEL-A', 'buying_price' => 100, 'selling_price' => 200],
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertCreated()->json('data.id');

    $productBResponse = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/products', [
            'name' => 'Product B',
            'variant' => ['sku' => 'DEL-B', 'buying_price' => 100, 'selling_price' => 200],
            'images' => [UploadedFile::fake()->image('b.jpg')],
        ])->assertCreated();

    $imageBId = $productBResponse->json('data.images.0.id');
    $imagePath = ProductImage::findOrFail($imageBId)->path;

    // Naming product A's id but image B's id — a mismatched pair. This
    // is a Form Request validation failure (422), not a 404: the
    // mismatch is caught before the service ever runs.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$productAId}", [
            '_method' => 'PUT',
            'remove_image_ids' => [$imageBId],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('remove_image_ids.0');

    expect(ProductImage::find($imageBId))->not->toBeNull();
    Storage::disk('public')->assertExists($imagePath);
});

/**
 * Tenant B's product+image is created directly through ProductService
 * under a bound tenant context, not an authenticated HTTP call — see
 * the createPosOrderForTenant() helper docblock in tests/Pest.php for
 * why (Sanctum guard caching across sequential authenticated calls with
 * different users' tokens in one test).
 */
test('a tenant cannot remove another tenant image via update, even naming their own product', function () {
    [$tenantA, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $productA = createProductForTenant($tenantA);

    app()->instance('tenant', $tenantB);
    $productB = app(\App\Services\ProductService::class)->createProduct([
        'name' => 'Tenant B Product',
        'variant' => ['sku' => 'DEL-TB-1', 'buying_price' => 100, 'selling_price' => 200],
        'images' => [UploadedFile::fake()->image('b.jpg')],
    ]);
    app()->forgetInstance('tenant');

    $imageB = $productB->images->first();
    $tokenA = $userA->createToken('t')->plainTextToken;

    // Tenant A names their OWN product, but tenant B's real image id.
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$productA->id}", [
            '_method' => 'PUT',
            'remove_image_ids' => [$imageB->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('remove_image_ids.0');

    expect(ProductImage::withoutGlobalScope(\App\Models\Concerns\TenantScope::class)->find($imageB->id))
        ->not->toBeNull();
    Storage::disk('public')->assertExists($imageB->path);
});
