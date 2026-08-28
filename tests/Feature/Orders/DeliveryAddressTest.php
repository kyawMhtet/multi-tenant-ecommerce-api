<?php

use App\Models\Customer;
use App\Models\Order;

beforeEach(function () {
    [$this->tenant, $this->user] = makeTenantUser();
    enablePaymentMethodForTenant($this->tenant);
    $this->variant = createProductForTenant($this->tenant, variantOverrides: ['current_stock' => 20])->variants->first();
});

function checkout(array $overrides = [])
{
    return test()->withHeader('X-Tenant-Slug', test()->tenant->slug)
        ->postJson('/api/v1/public/orders', array_merge([
            'items' => [['product_variant_slug' => test()->variant->slug, 'quantity' => 1]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 12, near the pagoda, Hlaing Township'],
        ], $overrides));
}

test('a delivery order stores the full address and optional structured parts', function () {
    checkout(['delivery_address' => [
        'full_address' => 'No. 12, 3rd floor, near the pagoda',
        'house_number' => '12',
        'street' => 'Bogyoke Road',
        'township' => 'Hlaing',
        'city' => 'Yangon',
        'note' => 'Call on arrival, gate is round the back',
    ]])->assertCreated()
        ->assertJsonPath('data.fulfillment_type', 'delivery')
        ->assertJsonPath('data.delivery_address.city', 'Yangon')
        ->assertJsonPath('data.delivery_address.note', 'Call on arrival, gate is round the back');

    expect(Order::firstOrFail()->delivery_address['street'])->toBe('Bogyoke Road');
});

test('only the full address is required — structured parts are optional', function () {
    checkout(['delivery_address' => ['full_address' => 'Behind the market, Insein']])
        ->assertCreated()
        ->assertJsonPath('data.delivery_address.full_address', 'Behind the market, Insein');
});

test('a delivery order without an address is rejected', function () {
    checkout(['delivery_address' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors('delivery_address');

    expect(Order::count())->toBe(0);
});

test('a delivery order with an empty full address is rejected', function () {
    checkout(['delivery_address' => ['city' => 'Yangon']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('delivery_address.full_address');
});

test('a pickup order needs no address', function () {
    checkout(['fulfillment_type' => 'pickup', 'delivery_address' => null])
        ->assertCreated()
        ->assertJsonPath('data.fulfillment_type', 'pickup')
        ->assertJsonPath('data.delivery_address', null);
});

/**
 * An address sent alongside pickup is discarded rather than stored — a
 * stale address lingering on an order nobody is delivering is worse than
 * no address, because it looks actionable.
 */
test('an address sent with a pickup order is discarded', function () {
    checkout([
        'fulfillment_type' => 'pickup',
        'delivery_address' => ['full_address' => 'Should not be kept'],
    ])->assertCreated()
        ->assertJsonPath('data.delivery_address', null);

    expect(Order::firstOrFail()->delivery_address)->toBeNull();
});

test('fulfillment_type must be delivery or pickup', function () {
    checkout(['fulfillment_type' => 'teleport'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('fulfillment_type');
});

test('the customer record remembers the address for next time', function () {
    checkout()->assertCreated();

    expect(Customer::firstOrFail()->address)->toBe('No. 12, near the pagoda, Hlaing Township');
});

test('a returning customer address is updated when they move, not duplicated', function () {
    checkout()->assertCreated();
    checkout(['delivery_address' => ['full_address' => 'New place, Bahan Township']])->assertCreated();

    expect(Customer::count())->toBe(1)
        ->and(Customer::firstOrFail()->address)->toBe('New place, Bahan Township');
});

/**
 * A later pickup order carries no address; that must not wipe the one on
 * file, or the customer's next delivery would have nowhere to go.
 */
test('a pickup order does not erase the customer stored address', function () {
    checkout()->assertCreated();
    checkout(['fulfillment_type' => 'pickup', 'delivery_address' => null])->assertCreated();

    expect(Customer::firstOrFail()->address)->toBe('No. 12, near the pagoda, Hlaing Township');
});

/**
 * The order's address is a snapshot. If a customer moves, past orders must
 * still record where they were actually delivered — same principle as
 * order_items.unit_price.
 */
test('moving house does not rewrite where past orders were delivered', function () {
    checkout()->assertCreated();
    $firstOrderId = Order::firstOrFail()->id;

    checkout(['delivery_address' => ['full_address' => 'New place, Bahan Township']])->assertCreated();

    expect(Order::findOrFail($firstOrderId)->delivery_address['full_address'])
        ->toBe('No. 12, near the pagoda, Hlaing Township')
        ->and(Customer::firstOrFail()->address)->toBe('New place, Bahan Township');
});
