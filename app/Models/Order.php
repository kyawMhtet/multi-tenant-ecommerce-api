<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'order_number', 'source', 'fulfillment_type', 'delivery_address', 'customer_id', 'cashier_id', 'status', 'payment_status',
    'delivery_provider_id', 'delivery_provider_name', 'tracking_number', 'dispatched_at', 'dispatched_by',
    'payment_method', 'cancellation_reason', 'cancellation_note', 'cancelled_at', 'cancelled_by',
    'refunded_at', 'refund_note', 'refunded_by',
    'subtotal', 'discount_amount', 'tax_amount', 'delivery_fee', 'total', 'currency', 'notes',
])]
class Order extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * The one definition of "counts as a real sale", shared by DashboardService
     * and ReportService so the two can't disagree about a day's revenue.
     * Status only, not payment_status: a paid order counts in full even when
     * payment_status is 'partial'.
     */
    public const REVENUE_STATUSES = ['paid', 'completed'];

    /**
     * Sales revenue in SQL, for aggregates. The delivery fee is excluded: it's
     * mostly money handed to a courier, and nothing records what the courier
     * was paid — counting it as revenue while only goods appear in cost
     * overstates margin on every delivered order. It's still reported
     * separately, never hidden.
     *
     * `total - delivery_fee` rather than `subtotal`, which predates discounts
     * and tax and would stop being correct once those are real.
     */
    public const GOODS_REVENUE_SQL = 'total - delivery_fee';

    protected function casts(): array
    {
        return [
            'delivery_address' => 'array',
            'dispatched_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * Whether the shop still owes this customer their money back. Derived, so
     * it can't disagree with the facts underneath.
     *
     * For manual methods a refund is something we RECORD but never PERFORM, so
     * "cancelled but not yet refunded" is a real state a shop sits in for days
     * — it has to be visible rather than left to memory.
     */
    public function refundRequired(): bool
    {
        return $this->status === 'cancelled'
            && $this->payment_status === 'paid'
            && $this->refunded_at === null;
    }

    /**
     * Whether any line is waiting on stock the shop doesn't have. Derived from
     * the rows, so mixed carts need no special case.
     *
     * A preorder order sits at 'pending' for weeks — without this the shop
     * can't tell it from one nobody has got round to.
     *
     * Resolves cheapest-first (withCount alias, loaded collection, then a
     * query) so it's safe to call from a paginated list.
     */
    public function hasPreorderItems(): bool
    {
        if ($this->preorder_item_count !== null) {
            return (int) $this->preorder_item_count > 0;
        }

        if ($this->relationLoaded('items')) {
            return $this->items->contains(fn (OrderItem $item) => (bool) $item->is_preorder);
        }

        return $this->items()->where('is_preorder', true)->exists();
    }

    /**
     * When the whole order can ship: the LONGEST lead time across its preorder
     * lines, since one parcel can only leave once everything has landed.
     *
     * Measured from created_at, not today — counting from now would push the
     * estimate back a day every day, so the promise could never come due.
     * Null when no lead time was quoted; inventing a date is worse than none.
     */
    public function preorderReadyBy(): ?\Illuminate\Support\Carbon
    {
        $days = $this->items
            ->filter(fn (OrderItem $item) => $item->is_preorder)
            ->pluck('preorder_lead_time_days')
            ->filter()
            ->max();

        return $days ? $this->created_at?->copy()->addDays((int) $days) : null;
    }

    /**
     * Dispatch and commercial status are different axes: a COD order is
     * dispatched while still unpaid, and a pickup order completes without ever
     * being dispatched. Derived, so the fact isn't stored twice.
     */
    public function isDispatched(): bool
    {
        return $this->dispatched_at !== null;
    }

    public function deliveryProvider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class);
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * The minimum that must be collected before this order is accepted.
     *
     * Summed from the SNAPSHOT on each line, not recomputed from the variant's
     * current percentage — same rule as unit_price. A shop that changes its
     * deposit terms next week must not retroactively change what this customer
     * was asked for.
     *
     * Zero for an ordinary order: an in-stock line has nothing "due up front"
     * of its own, and is governed by the payment method alone.
     *
     * Resolved cheapest-first, like hasPreorderItems(), so a paginated list
     * never triggers a query per order.
     */
    public function depositDue(): float
    {
        if ($this->items_sum_deposit_amount !== null) {
            return (float) $this->items_sum_deposit_amount;
        }

        if ($this->relationLoaded('items')) {
            return round((float) $this->items->sum('deposit_amount'), 2);
        }

        return round((float) $this->items()->sum('deposit_amount'), 2);
    }

    public function requiresDeposit(): bool
    {
        return $this->depositDue() > 0;
    }

    /**
     * What the customer is charged NOW — the deposit when there is one, the
     * whole total otherwise.
     *
     * One definition, used by the gateway that creates the charge, the pending
     * payment row, and the webhook that validates what came back. Three places
     * computing this separately is how a customer gets charged one amount and
     * credited another.
     */
    public function amountDueNow(): float
    {
        return $this->requiresDeposit() ? $this->depositDue() : (float) $this->total;
    }

    /**
     * Money actually received, from the payments ledger rather than a column —
     * a deposit and the balance are two separate payments against one order,
     * and a counter could not represent that without drifting from the rows
     * that record it. Same reasoning as the stock ledger.
     */
    public function amountPaid(): float
    {
        $payments = $this->relationLoaded('payments')
            ? $this->payments->where('status', 'success')->sum('amount')
            : $this->payments()->where('status', 'success')->sum('amount');

        return round((float) $payments, 2);
    }

    /** What is still owed — collected on delivery for a deposit order. */
    public function balanceDue(): float
    {
        return round(max(0, (float) $this->total - $this->amountPaid()), 2);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
