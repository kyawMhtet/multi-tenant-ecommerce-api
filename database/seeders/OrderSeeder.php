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
     * Routed through OrderService, never a raw Order::create(), so stock is
     * actually deducted and a stock_movements row written per line — a sales
     * history that didn't move stock wouldn't exercise the dashboard at all.
     *
     * Carbon::setTestNow() is what makes created_at and order_number's embedded
     * date reflect the intended day rather than when the seeder ran.
     *
     * Cleanup is in a finally block, not just after the loop: a throw would
     * leave 'tenant' dangling in the container, silently redirecting every
     * tenant-scoped query in the rest of the process to "test-shop".
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

            // Captured before any setTestNow(): computing dates from now()
            // inside the loop would compound on the previous iteration's frozen
            // time, so daysAgo offsets would stack and nothing land on today.
            $realNow = Carbon::now();

            foreach ($orders as $definition) {
                Carbon::setTestNow($realNow->copy()->subDays($definition['daysAgo'])->setTime($definition['hour'], $definition['minute'] ?? 0));

                // The two paths key cart lines differently: POS by id, the
                // storefront by slug, since a variant's id is never public.
                // One shape for both silently produced empty lines.
                $posItems = collect($definition['items'])->map(fn (array $item) => [
                    'product_variant_id' => $variants[$item['sku']]->id,
                    'quantity' => $item['quantity'],
                ])->all();

                $onlineItems = collect($definition['items'])->map(fn (array $item) => [
                    'product_variant_slug' => $variants[$item['sku']]->slug,
                    'quantity' => $item['quantity'],
                ])->all();

                if ($definition['source'] === 'pos') {
                    // A real POS sale has a cashier. Logged out immediately
                    // after so online orders don't inherit it.
                    Auth::login($owner);
                    $order = $orderService->createPosOrder(['items' => $posItems]);
                    Auth::logout();
                } else {
                    $order = $orderService->createOnlineOrder([
                        'items' => $onlineItems,
                        'customer_name' => $definition['customerName'],
                        'customer_phone' => $definition['customerPhone'],
                        // COD needs no gateway and carries most of the real
                        // volume, so seeded data reflects the common case.
                        'payment_method' => 'cod',
                        'fulfillment_type' => 'delivery',
                        'delivery_address' => ['full_address' => $definition['address'] ?? 'No. 12, Insein Road, Yangon'],
                    ]);
                }

                // Cancellation goes through the real cancelOrder(), not a raw
                // status write, so seeded cancellations carry a reason, an audit
                // trail and a return_in row like real ones — otherwise the
                // dashboard's refund and backlog cards would read wrong.
                if (($definition['markPaid'] ?? false)) {
                    $order->update(['status' => 'paid', 'payment_status' => 'paid']);
                } elseif (($definition['markCancelled'] ?? false)) {
                    $orderService->cancelOrder(
                        $order,
                        ['cancellation_reason' => $definition['cancellationReason'] ?? 'customer_cancelled'],
                        $owner->id,
                    );
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
