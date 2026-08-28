<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\TenantPaymentMethod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    [$this->tenant, $this->user] = makeTenantUser();
    $this->token = $this->user->createToken('t')->plainTextToken;
});

function enableQrForShop(): TenantPaymentMethod
{
    return enablePaymentMethodForTenant(test()->tenant, [
        'method' => 'qr_transfer',
        'gateway' => null,
        'instructions' => 'KBZPay 09123456789, name U Aung',
    ]);
}

test('a shop can upload its own payment QR', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/payments/methods', [
            'method' => 'qr_transfer',
            'is_enabled' => 'true',
            'instructions' => 'KBZPay 09123456789',
            'qr' => UploadedFile::fake()->image('kbzpay.jpg'),
        ]);

    // 201 on first create, 200 on later updates — Laravel's JsonResource
    // sets that from wasRecentlyCreated, which is correct for an upsert.
    $response->assertSuccessful()
        ->assertJsonPath('data.method', 'qr_transfer')
        ->assertJsonPath('data.is_manual', true)
        ->assertJsonPath('data.gateway', null)
        ->assertJsonPath('data.instructions', 'KBZPay 09123456789');

    $method = TenantPaymentMethod::withoutGlobalScopes()->firstOrFail();
    expect($method->qr_path)->not->toBeNull();
    Storage::disk('public')->assertExists($method->qr_path);
});

test('the gateway is resolved from the catalogue, never taken from input', function () {
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/v1/payments/methods', [
            'method' => 'qr_transfer',
            'gateway' => 'stripe',
            'is_enabled' => true,
        ])->assertSuccessful()
        ->assertJsonPath('data.gateway', null);

    expect(TenantPaymentMethod::withoutGlobalScopes()->firstOrFail()->gateway)->toBeNull();
});

test('an unknown method code is rejected', function () {
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/v1/payments/methods', ['method' => 'crypto_wallet', 'is_enabled' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('method');
});

test('re-saving a method keeps its qr rather than duplicating the row', function () {
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/payments/methods', [
            'method' => 'qr_transfer',
            'is_enabled' => 'true',
            'qr' => UploadedFile::fake()->image('first.jpg'),
        ])->assertSuccessful();

    $first = TenantPaymentMethod::withoutGlobalScopes()->firstOrFail()->qr_path;

    // Toggling it off must not lose the QR the shop already uploaded.
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/v1/payments/methods', ['method' => 'qr_transfer', 'is_enabled' => false])
        ->assertSuccessful()
        ->assertJsonPath('data.is_enabled', false);

    expect(TenantPaymentMethod::withoutGlobalScopes()->count())->toBe(1)
        ->and(TenantPaymentMethod::withoutGlobalScopes()->firstOrFail()->qr_path)->toBe($first);
    Storage::disk('public')->assertExists($first);
});

test('replacing the qr deletes the previous file', function () {
    $post = fn (string $name) => $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/payments/methods', [
            'method' => 'qr_transfer',
            'is_enabled' => 'true',
            'qr' => UploadedFile::fake()->image($name),
        ])->assertSuccessful();

    $post('first.jpg');
    $first = TenantPaymentMethod::withoutGlobalScopes()->firstOrFail()->qr_path;

    $post('second.jpg');
    $second = TenantPaymentMethod::withoutGlobalScopes()->firstOrFail()->qr_path;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

test('the storefront receives the qr and instructions so the customer can pay', function () {
    enableQrForShop();
    $method = TenantPaymentMethod::withoutGlobalScopes()->firstOrFail();
    $method->update(['qr_path' => 'payment-methods/1/fake.jpg']);

    $this->withHeader('X-Tenant-Slug', $this->tenant->slug)
        ->getJson('/api/v1/public/payment-methods')
        ->assertOk()
        ->assertJsonPath('data.0.method', 'qr_transfer')
        ->assertJsonPath('data.0.instructions', 'KBZPay 09123456789, name U Aung')
        ->assertJsonPath('data.0.requires_proof', true)
        ->assertJsonPath('data.0.qr_url', fn ($url) => str_contains((string) $url, 'payment-methods/1/fake.jpg'));
});

test('a customer can attach a transfer screenshot at checkout', function () {
    enableQrForShop();
    $variant = createProductForTenant($this->tenant, variantOverrides: ['current_stock' => 10])->variants->first();

    $this->withHeader('X-Tenant-Slug', $this->tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'qr_transfer',
            'payment_proof' => UploadedFile::fake()->image('transfer.jpg'),
        ])
        ->assertCreated()
        ->assertJsonPath('data.payment_method', 'qr_transfer')
        // Crucially still unpaid: a screenshot is a claim, not money.
        ->assertJsonPath('data.payment_status', 'unpaid');

    $payment = Payment::withoutGlobalScopes()->firstOrFail();

    expect($payment->status)->toBe('pending')
        ->and($payment->gateway)->toBe('manual')
        ->and($payment->proof_path)->not->toBeNull();
    Storage::disk('public')->assertExists($payment->proof_path);
});

test('an order without a screenshot is still accepted', function () {
    enableQrForShop();
    $variant = createProductForTenant($this->tenant, variantOverrides: ['current_stock' => 10])->variants->first();

    // A customer may legitimately order first and pay afterwards; refusing
    // would just lose the sale.
    $this->withHeader('X-Tenant-Slug', $this->tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'qr_transfer',
        ])->assertCreated();

    expect(Order::withoutGlobalScopes()->count())->toBe(1)
        ->and(Payment::withoutGlobalScopes()->count())->toBe(0);
});

test('the shop sees the screenshot on the order and confirming settles the payment', function () {
    enableQrForShop();
    $variant = createProductForTenant($this->tenant, variantOverrides: ['current_stock' => 10])->variants->first();

    $this->withHeader('X-Tenant-Slug', $this->tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'qr_transfer',
            'payment_proof' => UploadedFile::fake()->image('transfer.jpg'),
        ])->assertCreated();

    $order = Order::withoutGlobalScopes()->firstOrFail();

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson("/api/v1/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.payments.0.status', 'pending')
        ->assertJsonPath('data.payments.0.proof_url', fn ($url) => is_string($url) && $url !== '');

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson("/api/v1/orders/{$order->id}", ['payment_status' => 'paid', 'status' => 'paid'])
        ->assertOk()
        ->assertJsonPath('data.payment_status', 'paid');

    $payment = Payment::withoutGlobalScopes()->firstOrFail();
    expect($payment->status)->toBe('success')
        ->and($payment->paid_at)->not->toBeNull();
});

test('a customer never sees another customer transfer screenshot', function () {
    enableQrForShop();
    $variant = createProductForTenant($this->tenant, variantOverrides: ['current_stock' => 10])->variants->first();

    $response = $this->withHeader('X-Tenant-Slug', $this->tenant->slug)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'qr_transfer',
            'payment_proof' => UploadedFile::fake()->image('transfer.jpg'),
        ])->assertCreated();

    // A screenshot carries the payer's bank details and name. The public
    // checkout response must never echo it back.
    expect($response->json('data'))->not->toHaveKey('payments')
        ->and(json_encode($response->json()))->not->toContain('proof');
});

test('tenant A cannot configure or see tenant B payment methods', function () {
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );
    enablePaymentMethodForTenant($tenantB, ['method' => 'qr_transfer', 'instructions' => 'B private note']);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/v1/payments/methods')
        ->assertOk();

    // The catalogue is always returned in full, but tenant B's CONFIGURED
    // values must not appear in it.
    expect(json_encode($response->json()))->not->toContain('B private note');

    foreach ($response->json('data') as $row) {
        expect($row['is_enabled'])->toBeFalse();
    }
});

/**
 * The gateway='manual' filter in settleManualPayments() is what stops a
 * shop marking a card payment settled by hand. Only Stripe's webhook may
 * do that, against the amount Stripe actually reports — otherwise an order
 * could read "paid" for money that never arrived.
 */
test('confirming an order by hand cannot settle a gateway-backed payment', function () {
    enablePaymentMethodForTenant($this->tenant, ['method' => 'card', 'gateway' => 'stripe']);
    $variant = createProductForTenant($this->tenant, variantOverrides: ['current_stock' => 10])->variants->first();

    $order = createOnlineOrderForTenant($this->tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ], ['payment_method' => 'card']);

    app()->instance('tenant', $this->tenant);
    $stripePayment = Payment::create([
        'order_id' => $order->id,
        'gateway' => 'stripe',
        'amount' => $order->total,
        'status' => 'pending',
        'transaction_ref' => 'cs_manual_settle_guard',
    ]);
    app()->forgetInstance('tenant');

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson("/api/v1/orders/{$order->id}", ['payment_status' => 'paid', 'status' => 'paid'])
        ->assertOk();

    // The order reflects the shop's decision, but the Stripe payment record
    // stays pending — it is settled only by its own webhook.
    expect($stripePayment->fresh()->status)->toBe('pending')
        ->and($stripePayment->fresh()->paid_at)->toBeNull();
});

/**
 * QR images are per-shop and must never collide. Two shops uploading at the
 * same method code is the exact case where a naive path (or a
 * tenant-unaware upsert) would let one overwrite the other's QR — and a
 * customer would then be shown the wrong shop's payment details.
 */
test('two shops QR uploads are stored separately and never overwrite each other', function () {
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    // Tenant B's upload goes through the service under a bound tenant
    // context rather than a second authenticated HTTP call: Sanctum's guard
    // caches the resolved user for the whole test process, so a second call
    // with a different token would silently still act as tenant A — see the
    // createPosOrderForTenant() docblock in tests/Pest.php. The one real
    // HTTP call is reserved for the tenant actually under test.
    app()->instance('tenant', $tenantB);
    app(\App\Services\Payments\PaymentMethodService::class)->upsert([
        'method' => 'qr_transfer',
        'is_enabled' => true,
        'qr' => UploadedFile::fake()->image('b-qr.jpg'),
    ]);
    app()->forgetInstance('tenant');

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/payments/methods', [
            'method' => 'qr_transfer',
            'is_enabled' => 'true',
            'qr' => UploadedFile::fake()->image('a-qr.jpg'),
        ])->assertSuccessful();

    $rows = TenantPaymentMethod::withoutGlobalScopes()->where('method', 'qr_transfer')->get();

    expect($rows)->toHaveCount(2);

    $a = $rows->firstWhere('tenant_id', $this->tenant->id);
    $b = $rows->firstWhere('tenant_id', $tenantB->id);

    expect($a->qr_path)->toStartWith('payment-methods/'.$this->tenant->id.'/')
        ->and($b->qr_path)->toStartWith('payment-methods/'.$tenantB->id.'/')
        ->and($a->qr_path)->not->toBe($b->qr_path);

    // Both files must still exist — neither upload clobbered the other.
    Storage::disk('public')->assertExists($a->qr_path);
    Storage::disk('public')->assertExists($b->qr_path);
});
