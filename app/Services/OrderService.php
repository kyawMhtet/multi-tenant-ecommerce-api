<?php

namespace App\Services;

use App\Exceptions\PreorderRequiresPrepaymentException;
use App\Models\Customer;
use App\Models\DeliveryProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Notifications\NewOnlineOrderReceived;
use App\Services\Delivery\DeliveryFeeCalculator;
use App\Services\Payments\PaymentMethodCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly DeliveryFeeCalculator $deliveryFees,
    ) {}

    /**
     * whereDate, not a timestamp range: "Aug 1 to Aug 5" means both days in full.
     */
    public function listOrders(array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // withCount, not eager-loading: the list only needs to know WHETHER an
        // order is waiting. Order::hasPreorderItems() picks the alias up.
        $query = Order::with('customer', 'cashier')
            ->withCount(['items as preorder_item_count' => fn ($items) => $items->where('is_preorder', true)]);

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
     * One transaction so "an order exists" and "its stock was deducted" are
     * the same fact — never one without the other.
     */
    public function createPosOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $lines = $this->resolveCartLines($data['items']);
            $subtotal = round($lines->sum('lineTotal'), 2);

            // No fulfillment_type on a counter sale, so this is zero. Routed
            // through the calculator anyway to keep one rule, not two.
            $deliveryFee = $this->deliveryFees->for(app('tenant'), $data['fulfillment_type'] ?? null);

            $order = Order::create([
                'order_number' => $this->generateOrderNumber('POS'),
                'source' => 'pos',
                'cashier_id' => auth()->id(),
                'status' => 'paid',
                'payment_status' => 'paid',
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'delivery_fee' => $deliveryFee,
                'total' => $this->calculateTotal($subtotal, 0, 0, $deliveryFee),
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
     * Public and unauthenticated. There is no authenticated user to
     * cross-check against, so this route's ENTIRE tenant-isolation guarantee
     * is the ambient scope applied in resolveCartLinesBySlug() — unlike the
     * POS path, nothing sits behind it.
     *
     * Stock is reserved at creation, before payment confirms, to prevent
     * overselling while payment settles.
     */
    public function createOnlineOrder(array $data): Order
    {
        $order = DB::transaction(function () use ($data) {
            $lines = $this->resolveCartLinesBySlug($data['items']);
            $subtotal = round($lines->sum('lineTotal'), 2);

            $customer = $this->findOrCreateCustomer(
                $data['customer_name'],
                $data['customer_phone'],
                $data['delivery_address']['full_address'] ?? null,
            );

            // Server-side only: a fee read from the request body is a fee the
            // customer can set to zero.
            $deliveryFee = $this->deliveryFees->for(app('tenant'), $data['fulfillment_type'] ?? 'delivery');

            $order = Order::create([
                'order_number' => $this->generateOrderNumber('ONL'),
                'source' => 'online',
                'customer_id' => $customer->id,
                'cashier_id' => null,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                // Recorded before any Payment row exists: otherwise a COD order
                // and a card order are indistinguishable while both await payment.
                'payment_method' => $data['payment_method'] ?? null,
                'fulfillment_type' => $data['fulfillment_type'] ?? 'delivery',
                // Snapshotted: if the customer moves, this order must still say
                // where it actually went. Dropped for pickup.
                'delivery_address' => ($data['fulfillment_type'] ?? 'delivery') === 'delivery'
                    ? ($data['delivery_address'] ?? null)
                    : null,
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'tax_amount' => 0,
                // Snapshotted: raising the shop's fee must not change what this
                // customer was charged.
                'delivery_fee' => $deliveryFee,
                'total' => $this->calculateTotal($subtotal, 0, 0, $deliveryFee),
            ]);

            $this->createOrderItems($order, $lines);

            return $order->load('items', 'customer');
        });

        $this->notifyTenantOfNewOnlineOrder($order);

        return $order;
    }

    /**
     * Cancelling restores stock, guarded so a repeat cancel can't credit it
     * twice. The row lock makes that guard hold under concurrency — it's a
     * read-then-write on shared state. 'refunded' deliberately doesn't
     * trigger it: a refund may not mean the item came back.
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

            if (($data['payment_status'] ?? null) === 'paid') {
                $this->settleManualPayments($locked);
            }

            return $locked->fresh(['items', 'customer', 'cashier', 'payments']);
        });
    }

    /**
     * Cancels an order, restoring its stock and recording why.
     *
     * Idempotent: a repeat cancel returns untouched rather than restoring
     * stock again or overwriting the original reason.
     *
     * Deliberately does NOT touch payment_status. Money that arrived is still
     * arrived; what changes is that the shop now owes it back, which
     * Order::refundRequired() derives. Marking it refunded here would claim
     * the money went back at the moment of cancellation, which for a manual
     * method is never true.
     */
    public function cancelOrder(Order $order, array $data, ?int $cancelledBy = null): Order
    {
        return DB::transaction(function () use ($order, $data, $cancelledBy) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['cancelled', 'refunded'], true)) {
                return $locked->fresh(['items', 'customer', 'cashier', 'payments']);
            }

            $locked->update([
                'status' => 'cancelled',
                'cancellation_reason' => $data['cancellation_reason'],
                'cancellation_note' => $data['cancellation_note'] ?? null,
                'cancelled_at' => now(),
                'cancelled_by' => $cancelledBy,
            ]);

            $this->restoreStockForCancelledOrder($locked);

            return $locked->fresh(['items', 'customer', 'cashier', 'payments']);
        });
    }

    /**
     * Records which courier took the order out.
     *
     * Deliberately does NOT touch status: dispatch and the commercial status
     * are different axes — a COD order goes out while still unpaid, and
     * nudging status would assert something untrue about the money.
     *
     * Re-dispatching overwrites, dispatched_at included: when a courier loses
     * a parcel and the shop re-sends, the useful date is the real one.
     */
    public function dispatchOrder(Order $order, array $data, ?int $dispatchedBy = null): Order
    {
        return DB::transaction(function () use ($data, $order, $dispatchedBy) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            abort_if(
                $locked->fulfillment_type === 'pickup',
                422,
                'This order is for pickup and is not delivered by a courier.'
            );

            abort_if(
                in_array($locked->status, ['cancelled', 'refunded'], true),
                422,
                'This order has been cancelled and cannot be dispatched.'
            );

            // Re-resolved through the tenant scope rather than trusting the
            // validated id — the same defence-in-depth as resolveCartLines().
            $provider = DeliveryProvider::whereKey($data['delivery_provider_id'])->firstOrFail();

            $locked->update([
                'delivery_provider_id' => $provider->id,
                // Snapshotted so the order still names its courier after the
                // provider row is deleted.
                'delivery_provider_name' => $provider->name,
                'tracking_number' => $data['tracking_number'] ?? null,
                'dispatched_at' => now(),
                'dispatched_by' => $dispatchedBy,
            ]);

            return $locked->fresh(['items', 'customer', 'cashier', 'payments', 'dispatchedBy']);
        });
    }

    /**
     * Records that the shop returned the money — it never moves any. For
     * manual methods the cash went straight between customer and shop, so
     * there is nothing here to reverse, only an attestation to store. A
     * future card refund would call the gateway first, then land here.
     */
    public function refundOrder(Order $order, array $data, ?int $refundedBy = null): Order
    {
        return DB::transaction(function () use ($order, $data, $refundedBy) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            abort_if(
                $locked->payment_status !== 'paid',
                422,
                'This order has no received payment to refund.'
            );

            $locked->update([
                'payment_status' => 'refunded',
                'refunded_at' => now(),
                'refund_note' => $data['refund_note'] ?? null,
                'refunded_by' => $refundedBy,
            ]);

            // Only rows that actually succeeded — a failed or already-refunded
            // attempt has nothing to reverse.
            $locked->payments()
                ->where('status', 'success')
                ->update(['status' => 'refunded']);

            return $locked->fresh(['items', 'customer', 'cashier', 'payments']);
        });
    }

    /**
     * Restricted to gateway='manual'. A card payment is settled only by its
     * webhook against the amount the provider reports — letting a human tick
     * a box settle one would mark an order paid for money that never arrived.
     */
    private function settleManualPayments(Order $order): void
    {
        $order->payments()
            ->where('gateway', 'manual')
            ->where('status', 'pending')
            ->update(['status' => 'success', 'paid_at' => now()]);
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
     * Uses $order->tenant rather than the ambient app('tenant') binding — the
     * order already carries its tenant, so this doesn't depend on container
     * state. Called after the transaction returns, so no afterCommit needed.
     */
    private function notifyTenantOfNewOnlineOrder(Order $order): void
    {
        Notification::send($order->tenant->users, new NewOnlineOrderReceived($order));
    }

    /**
     * Re-fetched through the tenant scope — price, cost and existence are
     * never trusted from the request, and another tenant's id isn't found.
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
     * Slug instead of id — the public checkout's only valid identifier. Kept
     * separate rather than a mode flag on resolveCartLines(): the two have
     * different trust boundaries, and one method with a flag makes it easy to
     * wire the wrong lookup to the wrong route.
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

    /**
     * The one definition of what an order costs. Written out in full though
     * discount and tax are 0 today, so the POS and storefront paths can't
     * rediscover the arithmetic separately and disagree.
     *
     * The delivery fee adds to what the customer pays; it's excluded only
     * from margin reporting (Order::GOODS_REVENUE_SQL).
     */
    private function calculateTotal(float $subtotal, float $discount, float $tax, float $deliveryFee): float
    {
        return round($subtotal - $discount + $tax + $deliveryFee, 2);
    }

    private function createOrderItems(Order $order, Collection $lines): void
    {
        foreach ($lines as $line) {
            $variant = $line['variant'];

            $deducted = $this->stockService->deductForSale($variant, $line['quantity'], $order);

            // From the post-deduction balance, not the allow_preorder flag: the
            // flag says the shop is WILLING to sell below zero, the balance says
            // this line actually did. A partial dip (2 in stock, 5 ordered)
            // counts — that customer is waiting either way.
            $isPreorder = $deducted->track_stock && (float) $deducted->current_stock < 0;

            // After the deduction for the same reason as above: only the real
            // balance knows this line went below zero. One offending line
            // refuses the whole order — an order carries a single payment
            // method, so part-paying one line isn't expressible. A null method
            // is a POS sale, already paid at the counter.
            if ($isPreorder
                && $deducted->preorder_requires_prepayment
                && $order->payment_method !== null
                && ! PaymentMethodCatalog::collectsUpfront($order->payment_method)) {
                throw new PreorderRequiresPrepaymentException($deducted);
            }

            OrderItem::create([
                'order_id' => $order->id,
                'product_variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->variant_name,
                // Snapshotted like unit_price: product_variant_id is
                // nullOnDelete, and a surviving variant can still be renamed.
                'sku' => $variant->sku,
                'attributes' => $variant->attributes,
                'quantity' => $line['quantity'],
                'is_preorder' => $isPreorder,
                // Only on a line that's actually waiting.
                'preorder_lead_time_days' => $isPreorder ? $deducted->preorder_lead_time_days : null,
                'unit_price' => $variant->selling_price,
                'unit_cost' => $variant->buying_price,
                'line_total' => $line['lineTotal'],
            ]);
        }
    }

    /**
     * Matched by phone within the current tenant. A differing name at checkout
     * deliberately does NOT overwrite the stored one.
     */
    private function findOrCreateCustomer(string $name, string $phone, ?string $address = null): Customer
    {
        $customer = Customer::where('phone', $phone)->first();

        if ($customer === null) {
            return Customer::create(['name' => $name, 'phone' => $phone, 'address' => $address]);
        }

        // Overwritten with a real value but never blanked — a pickup order
        // carries no address, and letting that erase the stored one would lose
        // it for the next delivery. Unlike the name: people do move.
        if ($address !== null && $address !== $customer->address) {
            $customer->update(['address' => $address]);
        }

        return $customer;
    }

    /**
     * Date-prefixed random code, not a sequential counter: sequential needs a
     * locked per-tenant counter row to be safe under concurrency, which the
     * schema doesn't have. Cost is that numbers aren't gapless — if tax
     * compliance ever needs that, add the counter, don't patch this.
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
