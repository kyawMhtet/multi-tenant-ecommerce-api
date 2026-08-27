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
    'order_number', 'source', 'customer_id', 'cashier_id', 'status', 'payment_status',
    'subtotal', 'discount_amount', 'tax_amount', 'total', 'currency', 'notes',
])]
class Order extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * The one definition of "counts as a real sale" — shared by
     * DashboardService's today card and ReportService's date-range report,
     * so the two can never quietly disagree about what a given day's
     * revenue is. Deliberately checks status only, not payment_status: a
     * status=paid order still counts in full even if payment_status is
     * 'partial'.
     */
    public const REVENUE_STATUSES = ['paid', 'completed'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
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
