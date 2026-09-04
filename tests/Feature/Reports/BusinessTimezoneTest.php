<?php

use App\Services\DashboardService;
use App\Services\ReportService;
use Illuminate\Support\Carbon;

/**
 * Which day a sale belongs to.
 *
 * Timestamps are stored UTC, but a shop's day runs on its own clock.
 * tenants.timezone was added for exactly this — Yangon is UTC+06:30, Bangkok
 * UTC+07:00 — and nothing read it: every filter compared against the server's
 * date.
 *
 * The scenario below is the one that made it concrete. It is 03:00 on the 5th
 * in Yangon, which is still 20:30 on the 4th in UTC. Under the old code the
 * "today" card answered with the UTC day, so at 3am it showed the whole of the
 * previous afternoon's trade as today's takings — and the shop's own early
 * hours were filed under yesterday.
 */

/** UTC 20:30 on the 4th === 03:00 on the 5th in Yangon. */
function atYangonEarlyMorning(): Carbon
{
    return Carbon::parse('2026-09-04 20:30:00', 'UTC');
}

function yangonShop(): array
{
    return makeTenantUser(tenantOverrides: [
        'timezone' => 'Asia/Yangon',
        'currency' => 'MMK',
    ]);
}

test('the day starts at local midnight, not at UTC midnight', function () {
    [$tenant, $user] = yangonShop();
    $variant = createProductForTenant($tenant, variantOverrides: [
        'selling_price' => 1000, 'buying_price' => 400, 'current_stock' => 100,
    ])->variants->first();

    // Yesterday afternoon in the shop (14:00 on the 4th) — but the SAME UTC
    // calendar day as the sale below, which is exactly what the old
    // whereDate() comparison could not tell apart.
    $this->travelTo(Carbon::parse('2026-09-04 07:30:00', 'UTC'));
    createPosOrderForTenant($tenant, $user, [['product_variant_id' => $variant->id, 'quantity' => 1]]);

    // This morning in the shop (03:00 on the 5th).
    $this->travelTo(atYangonEarlyMorning());
    createPosOrderForTenant($tenant, $user, [['product_variant_id' => $variant->id, 'quantity' => 1]]);

    app()->instance('tenant', $tenant);
    $summary = app(DashboardService::class)->getSummary();

    // One sale, not two: yesterday's afternoon trade is yesterday's, however
    // the server's clock happens to have rolled over.
    expect($summary['today_order_count'])->toBe(1)
        ->and($summary['today_sales_total'])->toBe(1000.0);
});

test('a Bangkok shop and a Yangon shop disagree about the same instant, correctly', function () {
    // 17:15 UTC: already the 5th in Bangkok (00:15) and in Yangon (23:45 on
    // the 4th). The half-hour between the two zones is the whole point of the
    // column, and a shared UTC date would give both shops the same answer.
    $instant = Carbon::parse('2026-09-04 17:15:00', 'UTC');

    [$bangkok, $bangkokUser] = makeTenantUser(
        userOverrides: ['email' => 'bkk@shop.test'],
        tenantOverrides: ['slug' => 'bkk', 'owner_email' => 'bkk@shop.test', 'timezone' => 'Asia/Bangkok'],
    );
    [$yangon, $yangonUser] = makeTenantUser(
        userOverrides: ['email' => 'rgn@shop.test'],
        tenantOverrides: ['slug' => 'rgn', 'owner_email' => 'rgn@shop.test', 'timezone' => 'Asia/Yangon'],
    );

    $bangkokVariant = createProductForTenant($bangkok, variantOverrides: ['selling_price' => 500, 'current_stock' => 10])->variants->first();
    $yangonVariant = createProductForTenant($yangon, variantOverrides: ['selling_price' => 500, 'current_stock' => 10])->variants->first();

    $this->travelTo($instant);
    createPosOrderForTenant($bangkok, $bangkokUser, [['product_variant_id' => $bangkokVariant->id, 'quantity' => 1]]);
    createPosOrderForTenant($yangon, $yangonUser, [['product_variant_id' => $yangonVariant->id, 'quantity' => 1]]);

    // Same instant, two different local dates — and each shop's sale falls on
    // its own "today" either way, which is the property that matters.
    app()->instance('tenant', $bangkok);
    expect(app(DashboardService::class)->getSummary()['today_order_count'])->toBe(1);

    app()->instance('tenant', $yangon);
    expect(app(DashboardService::class)->getSummary()['today_order_count'])->toBe(1);

    app()->forgetInstance('tenant');
});

test('a report date range means the shop calendar days, both in full', function () {
    [$tenant, $user] = yangonShop();
    $variant = createProductForTenant($tenant, variantOverrides: [
        'selling_price' => 1000, 'buying_price' => 400, 'current_stock' => 100,
    ])->variants->first();

    // Local the 4th, afternoon.
    $this->travelTo(Carbon::parse('2026-09-04 07:30:00', 'UTC'));
    createPosOrderForTenant($tenant, $user, [['product_variant_id' => $variant->id, 'quantity' => 1]]);

    // Local the 5th, 03:00 — same UTC day as the one above.
    $this->travelTo(atYangonEarlyMorning());
    createPosOrderForTenant($tenant, $user, [['product_variant_id' => $variant->id, 'quantity' => 2]]);

    app()->instance('tenant', $tenant);

    $fourth = app(ReportService::class)->getSalesProfitReport([
        'date_from' => '2026-09-04', 'date_to' => '2026-09-04',
    ]);
    $fifth = app(ReportService::class)->getSalesProfitReport([
        'date_from' => '2026-09-05', 'date_to' => '2026-09-05',
    ]);

    expect($fourth['order_count'])->toBe(1)
        ->and($fourth['revenue'])->toBe('1000.00')
        ->and($fifth['order_count'])->toBe(1)
        ->and($fifth['revenue'])->toBe('2000.00');
});

test('the daily breakdown buckets each sale on the shop local date', function () {
    [$tenant, $user] = yangonShop();
    $variant = createProductForTenant($tenant, variantOverrides: [
        'selling_price' => 1000, 'buying_price' => 400, 'current_stock' => 100,
    ])->variants->first();

    $this->travelTo(Carbon::parse('2026-09-04 07:30:00', 'UTC'));
    createPosOrderForTenant($tenant, $user, [['product_variant_id' => $variant->id, 'quantity' => 1]]);

    $this->travelTo(atYangonEarlyMorning());
    createPosOrderForTenant($tenant, $user, [['product_variant_id' => $variant->id, 'quantity' => 3]]);

    app()->instance('tenant', $tenant);
    $daily = collect(app(ReportService::class)->getSalesProfitReport([
        'date_from' => '2026-09-04', 'date_to' => '2026-09-05',
    ])['daily'])->keyBy('date');

    // Grouping on the raw UTC DATE() put BOTH rows on the 4th — and the 5th's
    // key then matched nothing, so the chart showed a zero day the shop knew
    // it had traded on.
    expect($daily['2026-09-04']['revenue'])->toBe('1000.00')
        ->and($daily['2026-09-04']['order_count'])->toBe(1)
        ->and($daily['2026-09-05']['revenue'])->toBe('3000.00')
        ->and($daily['2026-09-05']['order_count'])->toBe(1);
});

test('the order list date filter follows the shop calendar too', function () {
    [$tenant, $user] = yangonShop();
    $variant = createProductForTenant($tenant, variantOverrides: [
        'selling_price' => 1000, 'current_stock' => 100,
    ])->variants->first();

    $this->travelTo(Carbon::parse('2026-09-04 07:30:00', 'UTC'));
    createPosOrderForTenant($tenant, $user, [['product_variant_id' => $variant->id, 'quantity' => 1]]);

    $this->travelTo(atYangonEarlyMorning());
    $todaysOrder = createPosOrderForTenant($tenant, $user, [['product_variant_id' => $variant->id, 'quantity' => 1]]);

    $this->actingAs($user)
        ->getJson('/api/v1/orders?date_from=2026-09-05&date_to=2026-09-05')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $todaysOrder->id);
});

test('a UTC shop is unaffected — the change is a generalisation, not a shift', function () {
    [$tenant, $user] = makeTenantUser(tenantOverrides: ['timezone' => 'UTC']);
    $variant = createProductForTenant($tenant, variantOverrides: [
        'selling_price' => 1000, 'current_stock' => 100,
    ])->variants->first();

    $this->travelTo(Carbon::parse('2026-09-04 20:30:00', 'UTC'));
    createPosOrderForTenant($tenant, $user, [['product_variant_id' => $variant->id, 'quantity' => 1]]);

    app()->instance('tenant', $tenant);

    expect(app(DashboardService::class)->getSummary()['today_order_count'])->toBe(1);
});
