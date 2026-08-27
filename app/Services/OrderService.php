<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Notifications\NewOnlineOrderReceived;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(private readonly StockService $stockService) {}

    /**
     * No manual tenant_id filtering — Order's own BelongsToTenant scope
     * already restricts this to the current tenant, same as
     * ProductService::listProducts(). date_from/date_to filter on the
     * date part of created_at (whereDate), not a precise timestamp range —
     * an admin picking "from Aug 1 to Aug 5" expects both days included in
     * full, not cut off at midnight of date_to.
     */
    public function listOrders(array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Order::with('customer', 'cashier');

        if (array_key_exists('status', $filters)) {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('source', $filters)) {
            $query->where('source', $filters['source']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->latest()->paginate($filters['per_page'] ?? 15);
    }

    /**
     * @param  array{items: array<int, array{product_variant_id: int, quantity: float|int}>, payment_method: string}  $data
     *
     * Everything here — the order, its items, the stock deduction, and the
     * payment — is one transaction. Deducting stock before creating the
     * order would leave inventory gone with no order to explain it if
     * anything after failed; deducting it after would let a "paid" order
     * exist for stock that was never actually removed, i.e. overselling.
     * Only atomicity guarantees "an order exists" and "its stock was
     * deducted" are the same fact, never one without the other.
     */
    public function createPosOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $lines = $this->resolveCartLines($data['items']);
            $subtotal = round($lines->sum('lineTotal'), 2);

            $order = Order::create([
                'order_number' => $this->generateOrderNumber('POS'),
                'source' => 'pos',
                'cashier_id' => auth()->id(),
                'status' => 'paid',
                'payment_status' => 'paid',
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total' => $subtotal,
            ]);

            $this->createOrderItems($order, $lines);

            Payment::create([
                'order_id' => $order->id,
                'gateway' => 'cash',
                'amount' => $subtotal,
                'status' => 'success',
                'paid_at' => now(),
            ]);

            return $order->load('items', 'payments', 'cashier');
        });
    }

    /**
     * @param  array{items: array<int, array{product_variant_slug: string, quantity: float|int}>, customer_name: string, customer_phone: string}  $data
     *
     * Public, unauthenticated storefront checkout. Same atomicity reasoning
     * as createPosOrder(), and the same tenant-isolation mechanism: every
     * variant is re-fetched by slug through the tenant-scoped model query
     * (resolveCartLinesBySlug()), never trusted from the request. Slug, not
     * id, because that's the only identifier StorefrontProductVariantResource
     * ever hands the storefront — see StorePublicOrderRequest's docblock.
     * There's no authenticated user here to cross-check against — unlike
     * the POS flow, this endpoint's entire tenant-isolation guarantee rests
     * on that scope being correctly applied, since there's no second layer
     * behind it. Stock is reserved immediately at order creation, before
     * payment is confirmed (status stays 'pending'/'unpaid') — a
     * deliberate choice to prevent overselling while payment settles,
     * matching common storefront behavior. No Payment row is created
     * here: that's a separate, not-yet-built concern (an online gateway
     * webhook confirming payment), not part of order creation itself.
     */
    public function createOnlineOrder(array $data): Order
    {
        $order = DB::transaction(function () use ($data) {
            $lines = $this->resolveCartLinesBySlug($data['items']);
            $subtotal = round($lines->sum('lineTotal'), 2);

            $customer = $this->findOrCreateCustomer($data['customer_name'], $data['customer_phone']);

            $order = Order::create([
                'order_number' => $this->generateOrderNumber('ONL'),
                'source' => 'online',
                'customer_id' => $customer->id,
                'cashier_id' => null,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total' => $subtotal,
            ]);

            $this->createOrderItems($order, $lines);

            return $order->load('items', 'customer');
        });

        $this->notifyTenantOfNewOnlineOrder($order);

        return $order;
    }

    /**
     * status/payment_status only — see UpdateOrderRequest for why the rest
     * of an order is immutable after creation.
     *
     * Cancelling restores stock: both createPosOrder() and
     * createOnlineOrder() deduct stock immediately at creation, so a
     * cancelled order that never got its stock back would permanently and
     * silently lock away inventory that was never actually sold — an
     * inventory-accuracy bug, not a neutral default. Guarded by
     * $wasAlreadyCancelledOrRefunded so cancelling an already-cancelled
     * order (a duplicate PATCH, or cancelling after a refund) can't
     * double-credit the same stock twice. Only 'cancelled' triggers this,
     * not 'refunded' — a refund is a payment action that may or may not
     * involve the physical item coming back, so it isn't assumed here.
     *
     * The row-lock re-fetch is what makes that guard hold under
     * concurrency, not just within one request: without it, two
     * simultaneous cancel requests could both read a not-yet-cancelled
     * status before either commits, and both credit the same stock back.
     * Same reasoning and shape as StockService::deductForSale()'s lock —
     * the guard is a read-then-write on shared state, so the read has to
     * be serialized too. Re-fetched through the plain tenant-scoped
     * query, so this is a lock, not a scope bypass.
     */
    public function updateOrderStatus(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            $wasAlreadyCancelledOrRefunded = in_array($locked->status, ['cancelled', 'refunded'], true);

            $locked->update($data);

            if (! $wasAlreadyCancelledOrRefunded && $locked->status === 'cancelled') {
                $this->restoreStockForCancelledOrder($locked);
            }

            return $locked->fresh(['items', 'customer', 'cashier']);
        });
    }

    private function restoreStockForCancelledOrder(Order $order): void
    {
        foreach ($order->items()->with('productVariant')->get() as $item) {
            if ($item->productVariant) {
                $this->stockService->returnStock($item->productVariant, (float) $item->quantity, $order);
            }
        }
    }

    /**
     * Called after DB::transaction() has already returned, not from inside
     * it and not via DB::afterCommit() — by this point the transaction is
     * already committed (or the method never reached here at all, since a
     * thrown exception skips straight past this line). afterCommit() solves
     * "defer work from a point still logically inside an open transaction";
     * there's no open transaction left here to defer out of.
     *
     * Notifies every user of the order's own tenant (via $order->tenant,
     * not the ambient app('tenant') binding — the order already carries
     * its tenant, no reason to depend on container state). Sent
     * synchronously (see NewOnlineOrderReceived's docblock for why).
     */
    private function notifyTenantOfNewOnlineOrder(Order $order): void
    {
        Notification::send($order->tenant->users, new NewOnlineOrderReceived($order));
    }

    /**
     * Re-fetches each cart line by id through the tenant-scoped model
     * query — never trusts price/cost or even existence from the request.
     * A variant id belonging to another tenant simply isn't found (the
     * model's tenant global scope applies here same as anywhere else),
     * which is exactly the defense-in-depth this needs: the Form Request
     * already rejects a cross-tenant id with a clean validation error, and
     * this is the second, independent check behind it.
     */
    private function resolveCartLines(array $items): Collection
    {
        return collect($items)->map(function (array $line) {
            $variant = ProductVariant::with('product')->findOrFail($line['product_variant_id']);
            $quantity = (float) $line['quantity'];
            $lineTotal = round((float) $variant->selling_price * $quantity, 2);

            return compact('variant', 'quantity', 'lineTotal');
        });
    }

    /**
     * Same purpose as resolveCartLines(), keyed by slug instead of id —
     * the public checkout's only valid identifier (see createOnlineOrder()'s
     * docblock). Kept as a separate method rather than a lookup-column
     * parameter on resolveCartLines(): the two callers have different
     * trust boundaries (authenticated POS vs. unauthenticated public), and
     * collapsing them into one method with a mode flag would make it
     * easier to accidentally wire the wrong lookup to the wrong route.
     */
    private function resolveCartLinesBySlug(array $items): Collection
    {
        return collect($items)->map(function (array $line) {
            $variant = ProductVariant::with('product')->where('slug', $line['product_variant_slug'])->firstOrFail();
            $quantity = (float) $line['quantity'];
            $lineTotal = round((float) $variant->selling_price * $quantity, 2);

            return compact('variant', 'quantity', 'lineTotal');
        });
    }

    private function createOrderItems(Order $order, Collection $lines): void
    {
        foreach ($lines as $line) {
            $variant = $line['variant'];

            $this->stockService->deductForSale($variant, $line['quantity'], $order);

            OrderItem::create([
                'order_id' => $order->id,
                'product_variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->variant_name,
                'quantity' => $line['quantity'],
                'unit_price' => $variant->selling_price,
                'unit_cost' => $variant->buying_price,
                'line_total' => $line['lineTotal'],
            ]);
        }
    }

    /**
     * Matched by phone within the current tenant (Customer uses
     * BelongsToTenant, so this can't match another tenant's customer even
     * by coincidence), falling back to creating a new one. Not updating an
     * existing customer's name on a mismatch — that's a deliberate
     * non-goal here, not an oversight; a returning customer using a
     * different name at checkout shouldn't silently overwrite their
     * stored name.
     */
    private function findOrCreateCustomer(string $name, string $phone): Customer
    {
        return Customer::where('phone', $phone)->first()
            ?? Customer::create(['name' => $name, 'phone' => $phone]);
    }

    /**
     * Date-prefixed random code (POS-20260821-A1B2C3 / ONL-20260821-A1B2C3)
     * rather than a strictly sequential per-tenant counter
     * (ORD-000001, 000002, ...). Sequential numbering is nicer for
     * accounting — no gaps, easy to scan — but generating "the next
     * number" safely under concurrent requests needs its own locked
     * counter (a row to lock and increment per tenant), which doesn't
     * exist in the current schema. A random suffix needs no shared
     * counter and can't collide under concurrency, at the cost of not
     * being gapless. If gapless invoice numbers become a real requirement
     * (e.g. for tax compliance), that's a deliberate follow-up: add a
     * per-tenant counter with row-level locking, not a quick change here.
     */
    private function generateOrderNumber(string $sourcePrefix): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = $sourcePrefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));

            if (! Order::where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not generate a unique order number.');
    }
}
