<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billing period's charge. History, not state — Subscription answers
 * "what can this shop do today", this answers "did they pay for March, how,
 * and who said so".
 */
#[Fillable([
    'subscription_id', 'plan', 'amount', 'currency', 'gateway', 'external_ref',
    'period_start', 'period_end', 'status', 'paid_at', 'proof_path',
    'reviewed_by', 'reviewed_at', 'note', 'meta',
])]
class SubscriptionInvoice extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * The platform staff member who ruled on this claim — approving OR
     * rejecting it. Never a User: every user belongs to a tenant, and that
     * relationship would model a shop signing off its own payment.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'reviewed_by');
    }

    /**
     * Derived from the absence of a gateway processor, matching
     * TenantPaymentMethod::isManual() — no processor means no webhook is
     * coming, so a human has to settle it.
     */
    public function isManual(): bool
    {
        return $this->gateway === 'manual';
    }

    /**
     * A shop has uploaded a transfer screenshot and is waiting on a human.
     *
     * Note what this does NOT mean: proof_path being set is a CLAIM, never
     * payment. Nothing may treat this scope's rows as settled — status and
     * paid_at are what say so. Same rule as payments.proof_path on the shop side,
     * and it matters more here, because the party uploading the screenshot is
     * the party being billed.
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where('gateway', 'manual')
            ->whereNotNull('proof_path')
            ->orderBy('created_at');
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'failed']);
    }

    /**
     * The shop asked for bank details and has sent nothing yet.
     *
     * Deliberately NOT part of the review queue: there is nothing for a human
     * to decide here. It is a list to chase or ignore, and mixing the two made
     * the queue mean two different things at once.
     */
    public function scopeAwaitingTransfer(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where('gateway', 'manual')
            ->whereNull('proof_path')
            ->orderBy('created_at');
    }

    /**
     * Asked for, never sent, and old enough that the period it quotes has
     * stopped being meaningful. Reusing one would bill the shop for a month
     * that has already passed.
     */
    public function scopeStaleIntent(Builder $query): Builder
    {
        return $query->awaitingTransfer()->where(
            'created_at', '<', now()->subDays((int) config('billing.transfer_intent_expiry_days'))
        );
    }
}
