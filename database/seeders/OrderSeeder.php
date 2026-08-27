<?php

namespace Database\Seeders;

use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Services\OrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class OrderSeeder extends Seeder
{
    /**
     * Attaches order history to the tenant/products the earlier seeders
     * already created — looked up, never created here, same reasoning as
     * ProductSeeder.
     *
     * Routed through OrderService (never a raw Order::create()) so
     * StockService actually deducts current_stock and writes a real
     * stock_movements row per line — a "sales history" that didn't
     * actually move stock wouldn't exercise anything a dashboard built on
     * top of it needs to prove out.
     *
     * Dates are simulated with Carbon::setTestNow(), which is what makes
     * order_number's embedded date, and every row's created_at, reflect
     * the intended day rather than the moment the seeder actually ran.
     *
     * The bound 'tenant', Auth::login() state, and frozen time are all
     * cleaned up in a finally block, not just "at the end" — if any
     * iteration throws (e.g. a bad quantity tripping InsufficientStockException),
     * an unguarded cleanup line after the loop would simply never run,
     * leaving 'tenant' dangling in the container. TenantScope checks
     * app()->bound('tenant') before falling back to
     * auth()->user()?->tenant_id, so a leaked binding would silently
     * redirect every tenant-scoped query in the rest of that process to
     * "test-shop" — harmless only by coincidence of DatabaseSeeder
     * currently calling this last.
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'test-shop')->firstOrFail();
        $owner = $tenant->users()->firstOrFail();

        app()->instance('tenant', $tenant);

        try {
            $orders = $this->orders();

            $variants = ProductVariant::whereIn('sku', collect($orders)
                ->flatMap(fn (array $order) => collect($order['items'])->pluck('sku'))
                ->unique())
                ->get()
                ->keyBy('sku');

            $orderService = app(OrderService::class);
            $created = [];

            // Captured once, before any setTestNow() call: computing each
            // order's date from Carbon::now() inside the loop would
            // compound on the PREVIOUS iteration's already-frozen time
            // (setTestNow makes now() keep returning that frozen
            // instant), so daysAgo offsets would stack instead of each
            // being relative to the real current date — nothing would
            // ever land on actual "today".
            $realNow = Carbon::now();

            foreach ($orders as $definition) {
                Carbon::setTestNow($realNow->copy()->subDays($definition['daysAgo'])->setTime($definition['hour'], $definition['minute'] ?? 0));

                $items = collect($definition['items'])->map(fn (array $item) => [
                    'product_variant_id' => $variants[$item['sku']]->id,
                    'quantity' => $item['quantity'],
                ])->all();

                if ($definition['source'] === 'pos') {
                    // A real POS sale always has a cashier; log in for
                    // the duration of this one call so cashier_id and
                    // the resulting stock_movements.created_by are
                    // populated realistically, then log back out
                    // immediately — online orders below must not
                    // inherit this.
                    Auth::login($owner);
                    $order = $orderService->createPosOrder(['items' => $items]);
                    Auth::logout();
                } else {
                    $order = $orderService->createOnlineOrder([
                        'items' => $items,
                        'customer_name' => $definition['customerName'],
                        'customer_phone' => $definition['customerPhone'],
                    ]);
                }

                // Simulates order lifecycle progression after creation —
                // not a raw stock/ledger shortcut, just the status
                // labels a real fulfillment process would have applied
                // over the following days. A "cancelled" order here
                // deliberately does NOT restore stock: this app has no
                // restock/return flow built yet (see CLAUDE.md —
                // StockService only has deductForSale so far), so a
                // cancelled seeded order is stock that left the shelf
                // for a sale that fell through, exactly like an
                // un-restocked real cancellation would be until that
                // feature exists.
                if (($definition['markPaid'] ?? false)) {
                    $order->update(['status' => 'paid', 'payment_status' => 'paid']);
                } elseif (($definition['markCancelled'] ?? false)) {
                    $order->update(['status' => 'cancelled']);
                }

                $created[] = $order->fresh();
            }

            $this->command->info('Seeded '.count($created).' orders for tenant "test-shop".');
        } finally {
            Auth::logout();
            Carbon::setTestNow();
            app()->forgetInstance('tenant');
        }
    }

    /**
     * @return array<int, array{daysAgo: int, hour: int, source: string, items: array<int, array{sku: string, quantity: int}>, customerName?: string, customerPhone?: string, markPaid?: bool, markCancelled?: bool}>
     */
    private function orders(): array
    {
        return [
            // Day -6
            ['daysAgo' => 6, 'hour' => 10, 'source' => 'pos', 'items' => [
                ['sku' => 'LAYS-CLS60', 'quantity' => 3], ['sku' => 'MILO-400', 'quantity' => 2],
            ]],
            ['daysAgo' => 6, 'hour' => 15, 'source' => 'online', 'customerName' => 'Su Su', 'customerPhone' => '09111111111', 'markPaid' => true, 'items' => [
                ['sku' => 'AHONE-PNT100', 'quantity' => 2], ['sku' => 'COLGATE-150', 'quantity' => 1],
            ]],

            // Day -5
            ['daysAgo' => 5, 'hour' => 11, 'source' => 'pos', 'items' => [
                ['sku' => 'MAMA-CHK5', 'quantity' => 4], ['sku' => 'DETTOL-SOAP100', 'quantity' => 3],
            ]],
            ['daysAgo' => 5, 'hour' => 18, 'source' => 'online', 'customerName' => 'Zaw Zaw', 'customerPhone' => '09222222222', 'markCancelled' => true, 'items' => [
                ['sku' => 'TSHIRT-BLK-L', 'quantity' => 2],
            ]],

            // Day -4
            ['daysAgo' => 4, 'hour' => 9, 'source' => 'pos', 'items' => [
                ['sku' => 'KYONEYEOM-DW750', 'quantity' => 2], ['sku' => 'MOSQCOIL-10', 'quantity' => 3],
            ]],
            ['daysAgo' => 4, 'hour' => 14, 'source' => 'pos', 'items' => [
                ['sku' => 'DURACELL-AA4', 'quantity' => 5], ['sku' => 'SUNSILK-SCH12', 'quantity' => 2],
            ]],
            ['daysAgo' => 4, 'hour' => 19, 'source' => 'online', 'customerName' => 'Su Su', 'customerPhone' => '09111111111', 'markPaid' => true, 'items' => [
                ['sku' => 'TSHIRT-WHT-S', 'quantity' => 1], ['sku' => 'BLOUSE-M', 'quantity' => 1],
            ]],

            // Day -3
            ['daysAgo' => 3, 'hour' => 12, 'source' => 'pos', 'items' => [
                ['sku' => 'LAYS-CLS60', 'quantity' => 4], ['sku' => 'MAMA-CHK5', 'quantity' => 3],
            ]],
            ['daysAgo' => 3, 'hour' => 20, 'source' => 'online', 'customerName' => 'Thiri', 'customerPhone' => '09333333333', 'markPaid' => true, 'items' => [
                ['sku' => 'DURACELL-AA4', 'quantity' => 9], ['sku' => 'MOSQCOIL-10', 'quantity' => 2],
            ]],

            // Day -2
            ['daysAgo' => 2, 'hour' => 10, 'source' => 'pos', 'items' => [
                ['sku' => 'COLGATE-150', 'quantity' => 2], ['sku' => 'DETTOL-SOAP100', 'quantity' => 2],
            ]],
            ['daysAgo' => 2, 'hour' => 16, 'source' => 'pos', 'items' => [
                ['sku' => 'TSHIRT-BLK-L', 'quantity' => 3], ['sku' => 'BLOUSE-M', 'quantity' => 1],
            ]],
            ['daysAgo' => 2, 'hour' => 21, 'source' => 'online', 'customerName' => 'Zaw Zaw', 'customerPhone' => '09222222222', 'items' => [
                // Left pending on purpose — a recent, still-unprocessed order.
                ['sku' => 'DURACELL-AA4', 'quantity' => 4],
            ]],

            // Day -1
            ['daysAgo' => 1, 'hour' => 11, 'source' => 'pos', 'items' => [
                ['sku' => 'AHONE-PNT100', 'quantity' => 3], ['sku' => 'KYONEYEOM-DW750', 'quantity' => 2],
            ]],
            ['daysAgo' => 1, 'hour' => 17, 'source' => 'online', 'customerName' => 'Nilar', 'customerPhone' => '09444444444', 'markPaid' => true, 'items' => [
                ['sku' => 'TSHIRT-BLK-L', 'quantity' => 1], ['sku' => 'BLOUSE-M', 'quantity' => 1],
            ]],

            // Day 0 — today
            ['daysAgo' => 0, 'hour' => 9, 'source' => 'pos', 'items' => [
                ['sku' => 'LAYS-CLS60', 'quantity' => 2], ['sku' => 'MILO-400', 'quantity' => 1],
            ]],
            ['daysAgo' => 0, 'hour' => 12, 'source' => 'pos', 'items' => [
                ['sku' => 'MAMA-CHK5', 'quantity' => 2], ['sku' => 'DETTOL-SOAP100', 'quantity' => 1],
            ]],
            ['daysAgo' => 0, 'hour' => 13, 'source' => 'online', 'customerName' => 'Su Su', 'customerPhone' => '09111111111', 'markPaid' => true, 'items' => [
                ['sku' => 'COLGATE-150', 'quantity' => 1],
            ]],
            ['daysAgo' => 0, 'hour' => 15, 'source' => 'online', 'customerName' => 'Thiri', 'customerPhone' => '09333333333', 'items' => [
                // Left pending — placed just now, not yet fulfilled.
                ['sku' => 'AHONE-PNT100', 'quantity' => 1],
            ]],
        ];
    }
}
