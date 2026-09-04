<?php

use App\Models\ProductVariant;
use App\Services\Pricing\DiscountType;
use Laravel\Sanctum\Sanctum;

/**
 * Per-variant discounts.
 *
 * The two things worth proving here are that the server, not the client,
 * decides what a thing costs, and that what a customer was charged stays
 * readable after the promotion is gone. Everything else follows.
 */
function discountShop(array $variantOverrides = []): array
{
    $unique = Illuminate\Support\Str::random(6);

    [$tenant, $user] = makeTenantUser(
        userOverrides: ['email' => "owner-{$unique}@shop.test"],
        tenantOverrides: ['slug' => "shop-{$unique}", 'owner_email' => "owner-{$unique}@shop.test"],
    );

    $variant = createProductForTenant($tenant, ['name' => 'Discounted Thing'], array_merge([
        'selling_price' => 1000,
        'buying_price' => 400,
        'current_stock' => 20,
    ], $variantOverrides))->variants->first();

    return [$tenant, $user, $variant];
}

// ---------------------------------------------------------------------------
// What the customer pays
// ---------------------------------------------------------------------------

test('a percentage discount is applied at the counter and snapshotted on the line', function () {
    [$tenant, $user, $variant] = discountShop([
        'discount_type' => DiscountType::Percent,
        'discount_value' => 20,
    ]);

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 3],
    ]);

    $item = $order->items()->first();

    // unit_price stays the LIST price: the reduction sits beside it, so the
    // receipt can show the saving rather than only a smaller number.
    expect((float) $item->unit_price)->toBe(1000.0)
        ->and((float) $item->discount_amount)->toBe(600.0)
        ->and((float) $item->line_total)->toBe(2400.0)
        // subtotal is gross, discount_amount subtracts once.
        ->and((float) $order->subtotal)->toBe(3000.0)
        ->and((float) $order->discount_amount)->toBe(600.0)
        ->and((float) $order->total)->toBe(2400.0);
});

test('the cash payment records the discounted total, not the gross subtotal', function () {
    [$tenant, $user, $variant] = discountShop([
        'discount_type' => DiscountType::Percent,
        'discount_value' => 25,
    ]);

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 2],
    ]);

    // What actually went in the till. Recording the subtotal would overstate
    // takings by the discount on every promoted sale.
    expect((float) $order->payments()->first()->amount)->toBe(1500.0)
        ->and($order->amountPaid())->toBe(1500.0);
});

test('a fixed discount comes off each unit', function () {
    [$tenant, $user, $variant] = discountShop([
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 150,
    ]);

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 4],
    ]);

    expect((float) $order->items()->first()->discount_amount)->toBe(600.0)
        ->and((float) $order->total)->toBe(3400.0);
});

test('a fixed discount larger than the price makes the item free, never negative', function () {
    // The realistic route here isn't a typo — it's a shop repricing an item
    // downwards months after setting a fixed discount it never revisited.
    [$tenant, $user, $variant] = discountShop([
        'selling_price' => 300,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 5000,
    ]);

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);

    expect($variant->effectivePrice())->toBe(0.0)
        ->and((float) $order->total)->toBe(0.0)
        ->and((float) $order->discount_amount)->toBe(300.0);
});

test('the storefront checkout prices from the variant, not from the cart the client sends', function () {
    [$tenant, , $variant] = discountShop([
        'discount_type' => DiscountType::Percent,
        'discount_value' => 10,
    ]);

    $order = createOnlineOrderForTenant($tenant, [
        // A price or a discount in this payload would be one a customer can
        // set. Only the slug and the quantity are theirs to choose.
        ['product_variant_slug' => $variant->slug, 'quantity' => 2, 'unit_price' => 1, 'discount_amount' => 9999],
    ], ['fulfillment_type' => 'pickup']);

    expect((float) $order->subtotal)->toBe(2000.0)
        ->and((float) $order->discount_amount)->toBe(200.0)
        ->and((float) $order->total)->toBe(1800.0);
});

// ---------------------------------------------------------------------------
// The window
// ---------------------------------------------------------------------------

test('a discount that has not started yet is not charged', function () {
    [$tenant, $user, $variant] = discountShop([
        'discount_type' => DiscountType::Percent,
        'discount_value' => 50,
        'discount_starts_at' => now()->addDay(),
    ]);

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);

    expect($variant->discountActive())->toBeFalse()
        ->and((float) $order->total)->toBe(1000.0)
        ->and((float) $order->discount_amount)->toBe(0.0);
});

test('a discount that has ended is not charged', function () {
    // Derived from the dates rather than a flag someone has to untick, so a
    // shop that forgets about a promotion stops giving it away by itself.
    [$tenant, $user, $variant] = discountShop([
        'discount_type' => DiscountType::Percent,
        'discount_value' => 50,
        'discount_starts_at' => now()->subDays(7),
        'discount_ends_at' => now()->subMinute(),
    ]);

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);

    expect($variant->discountActive())->toBeFalse()
        ->and((float) $order->total)->toBe(1000.0);
});

test('a zero value is not a discount whatever the type says', function () {
    [, , $variant] = discountShop([
        'discount_type' => DiscountType::Percent,
        'discount_value' => 0,
    ]);

    expect($variant->discountActive())->toBeFalse()
        ->and($variant->discountPercent())->toBeNull()
        ->and($variant->effectivePrice())->toBe(1000.0);
});

// ---------------------------------------------------------------------------
// The snapshot outliving the promotion
// ---------------------------------------------------------------------------

test('withdrawing a promotion does not rewrite what past customers were charged', function () {
    [$tenant, $user, $variant] = discountShop([
        'discount_type' => DiscountType::Percent,
        'discount_value' => 30,
    ]);

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);

    $variant->update(['discount_type' => null, 'discount_value' => 0]);

    $item = $order->fresh('items')->items->first();

    expect((float) $item->discount_amount)->toBe(300.0)
        ->and((float) $item->line_total)->toBe(700.0)
        ->and((float) $order->fresh()->total)->toBe(700.0);
});

// ---------------------------------------------------------------------------
// The API surface
// ---------------------------------------------------------------------------

test('an owner can put a variant on sale and clear it again', function () {
    [$tenant, $user, $variant] = discountShop();
    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/products/{$variant->product_id}/variants/{$variant->id}", [
        'discount_type' => 'percent',
        'discount_value' => 15,
        'discount_ends_at' => now()->addWeek()->toIso8601String(),
    ], ['X-Tenant-Slug' => $tenant->slug])
        ->assertOk()
        ->assertJsonPath('data.discount_type', 'percent')
        ->assertJsonPath('data.discount_active', true)
        ->assertJsonPath('data.effective_price', '850.00');

    // Sending a null type withdraws it, and takes the stale value and window
    // with it rather than leaving them describing a promotion that is over.
    $this->patchJson("/api/v1/products/{$variant->product_id}/variants/{$variant->id}", [
        'discount_type' => null,
    ], ['X-Tenant-Slug' => $tenant->slug])
        ->assertOk()
        ->assertJsonPath('data.discount_active', false)
        ->assertJsonPath('data.effective_price', '1000.00');

    $variant->refresh();

    expect($variant->discount_type)->toBeNull()
        ->and((float) $variant->discount_value)->toBe(0.0)
        ->and($variant->discount_ends_at)->toBeNull();
});

test('a percentage over 100 is refused', function () {
    [$tenant, $user, $variant] = discountShop();
    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/products/{$variant->product_id}/variants/{$variant->id}", [
        'discount_type' => 'percent',
        'discount_value' => 120,
    ], ['X-Tenant-Slug' => $tenant->slug])
        ->assertStatus(422)
        ->assertJsonValidationErrors('discount_value');
});

test('a discount window that ends before it starts is refused', function () {
    [$tenant, $user, $variant] = discountShop();
    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/products/{$variant->product_id}/variants/{$variant->id}", [
        'discount_type' => 'fixed',
        'discount_value' => 100,
        'discount_starts_at' => now()->addWeek()->toIso8601String(),
        'discount_ends_at' => now()->addDay()->toIso8601String(),
    ], ['X-Tenant-Slug' => $tenant->slug])
        ->assertStatus(422)
        ->assertJsonValidationErrors('discount_ends_at');
});

test('the storefront shows the sale price beside the list price, and only while it runs', function () {
    [, , $variant] = discountShop([
        'discount_type' => DiscountType::Percent,
        'discount_value' => 20,
    ]);

    $live = $this->getJson("/api/v1/public/products/{$variant->slug}")->assertOk();

    // selling_price stays the "was" figure a sale price is struck through
    // against, so a client that knows nothing about sale_price shows the
    // higher number — the safe direction to be wrong in.
    $live->assertJsonPath('data.variants.0.selling_price', '1000.00')
        ->assertJsonPath('data.variants.0.sale_price', '800.00')
        ->assertJsonPath('data.variants.0.discount_percent', 20);

    $variant->update(['discount_ends_at' => now()->subMinute()]);

    $this->getJson("/api/v1/public/products/{$variant->slug}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.selling_price', '1000.00')
        ->assertJsonPath('data.variants.0.sale_price', null)
        ->assertJsonPath('data.variants.0.discount_percent', null);
});

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

test('a shop cannot put another shop\'s variant on sale', function () {
    [, , $theirVariant] = discountShop();
    [$myTenant, $myUser] = makeTenantUser(
        userOverrides: ['email' => 'intruder@shop.test'],
        tenantOverrides: ['slug' => 'intruder-shop', 'owner_email' => 'intruder@shop.test'],
    );

    Sanctum::actingAs($myUser);

    $this->patchJson("/api/v1/products/{$theirVariant->product_id}/variants/{$theirVariant->id}", [
        'discount_type' => 'percent',
        'discount_value' => 90,
    ], ['X-Tenant-Slug' => $myTenant->slug])->assertNotFound();

    expect(ProductVariant::withoutGlobalScope(App\Models\Concerns\TenantScope::class)
        ->find($theirVariant->id)->discount_type)->toBeNull();
});
