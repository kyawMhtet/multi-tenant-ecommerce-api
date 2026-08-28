<?php

use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

test('adding a variant with an image stores it scoped to that variant, not the product', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$product->id}/variants", [
            'sku' => 'RED-M',
            'buying_price' => 100,
            'selling_price' => 200,
            'images' => [UploadedFile::fake()->image('red.jpg')],
        ]);

    $response->assertCreated()->assertJsonCount(1, 'data.images');

    $image = ProductImage::firstOrFail();
    Storage::disk('public')->assertExists($image->path);

    expect($image->product_variant_id)->not->toBeNull()
        ->and($image->product_id)->toBe($product->id);
});

test('a variant with no images of its own does not inherit the product\'s general images', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/products', [
            'name' => 'T-Shirt',
            'variant' => ['sku' => 'TSHIRT-M', 'buying_price' => 100, 'selling_price' => 200],
            'images' => [UploadedFile::fake()->image('general.jpg')],
        ]);

    $response->assertCreated()
        ->assertJsonCount(1, 'data.images')
        ->assertJsonCount(0, 'data.variants.0.images');
});

test('a product\'s general images do not leak into a variant\'s own images list', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    // Add a general product image and a variant-specific one in separate
    // requests, then confirm each shows up only where it belongs.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$product->id}", [
            '_method' => 'PATCH',
            'images' => [UploadedFile::fake()->image('general.jpg')],
        ])->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$product->id}/variants/{$variant->id}", [
            '_method' => 'PATCH',
            'images' => [UploadedFile::fake()->image('variant.jpg')],
        ])->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/products/{$product->id}");

    $response->assertOk()
        ->assertJsonCount(1, 'data.images')
        ->assertJsonCount(1, 'data.variants.0.images');

    expect(ProductImage::count())->toBe(2);
});

test('updating a variant with new images appends to, rather than replaces, existing ones', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$product->id}/variants/{$variant->id}", [
            '_method' => 'PATCH',
            'images' => [UploadedFile::fake()->image('one.jpg')],
        ])->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$product->id}/variants/{$variant->id}", [
            '_method' => 'PATCH',
            'images' => [UploadedFile::fake()->image('two.jpg')],
        ]);

    $response->assertOk()->assertJsonCount(2, 'data.images');
    expect(ProductImage::orderBy('sort_order')->pluck('sort_order')->all())->toBe([0, 1]);
});

test('removing a variant image via update removes the db row and the stored file', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$product->id}/variants/{$variant->id}", [
            '_method' => 'PATCH',
            'images' => [UploadedFile::fake()->image('one.jpg')],
        ])->assertOk();

    $image = ProductImage::firstOrFail();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}/variants/{$variant->id}", [
            'remove_image_ids' => [$image->id],
        ])->assertOk()->assertJsonCount(0, 'data.images');

    expect(ProductImage::count())->toBe(0);
    Storage::disk('public')->assertMissing($image->path);
});

test('removing a variant image that belongs to a different variant is rejected', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $variantA = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$product->id}/variants", [
            'sku' => 'VARIANT-B', 'buying_price' => 100, 'selling_price' => 200,
        ])->assertCreated();
    $variantB = $product->variants()->where('sku', 'VARIANT-B')->firstOrFail();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$product->id}/variants/{$variantA->id}", [
            '_method' => 'PATCH',
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertOk();
    $imageOfA = ProductImage::firstOrFail();

    // Naming variant B in the URL but variant A's image id in the body.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}/variants/{$variantB->id}", [
            'remove_image_ids' => [$imageOfA->id],
        ])->assertStatus(422)->assertJsonValidationErrors('remove_image_ids.0');

    Storage::disk('public')->assertExists($imageOfA->path);
});

test('a corrupt variant image is rejected cleanly and rolls back the whole request', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->post("/api/v1/products/{$product->id}/variants/{$variant->id}", [
            '_method' => 'PATCH',
            'variant_name' => 'Should not persist',
            'images' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ]);

    $response->assertStatus(422);
    expect($variant->fresh()->variant_name)->not->toBe('Should not persist')
        ->and(ProductImage::count())->toBe(0);
});

test('a tenant cannot see another tenant variant images', function () {
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
        'variant' => ['sku' => 'IMG-VB-1', 'buying_price' => 100, 'selling_price' => 200],
    ]);
    $variantB = $productB->variants->first();
    app(\App\Services\ProductService::class)->addVariantImages($variantB, [UploadedFile::fake()->image('b.jpg')]);
    app()->forgetInstance('tenant');

    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->getJson("/api/v1/products/{$productB->id}")
        ->assertNotFound();

    expect(ProductImage::withoutGlobalScope(\App\Models\Concerns\TenantScope::class)->count())->toBe(1);
});
