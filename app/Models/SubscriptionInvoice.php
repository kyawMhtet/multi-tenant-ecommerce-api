<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
     * A transfer that was asked for and that nobody has yet claimed to have
     * paid: pending, manual, no screenshot.
     *
     * The predicate behind the chase list and the expiry rule — and, more
     * importantly, the ONLY thing anything may void. An invoice carrying a
     * screenshot is a claim on money that has already left a shop's bank
     * account; voiding one drops it out of scopeAwaitingApproval(), where it
     * becomes invisible to every reviewer while the money stays gone. Both
     * places that void invoices (a shop changing plan, staff changing billing
     * currency) go through this scope so neither can reach an evidenced one.
     */
    public function scopeUnclaimedIntent(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where('gateway', 'manual')
            ->whereNull('proof_path');
    }

    /**
     * The boundary between an intent still worth chasing and a dead one.
     * Shared so the two sides can never overlap or leave a gap between them.
     */
    private static function intentExpiryCutoff(): Carbon
    {
        return now()->subDays((int) config('billing.transfer_intent_expiry_days'));
    }

    /**
     * The shop asked for bank details, has sent nothing yet, and asked
     * recently enough that chasing it still makes sense.
     *
     * Deliberately NOT part of the review queue: there is nothing for a human
     * to decide here. It is a list to chase or ignore, and mixing the two made
     * the queue mean two different things at once.
     *
     * Stale intents are EXCLUDED rather than swept. They are voided lazily,
     * when the shop next asks to pay (ManualBillingRail), so a shop that
     * clicked once and never came back would otherwise sit on this list
     * forever — and nobody is going to chase a year-old click. Derived from
     * the dates rather than written by a scheduler, the same position grace
     * and effectivePlan() take. Nothing is hidden: a stale intent is still in
     * the full ledger, which is where reconciliation looks.
     */
    public function scopeAwaitingTransfer(Builder $query): Builder
    {
        return $query->unclaimedIntent()
            ->where('created_at', '>=', self::intentExpiryCutoff())
            ->orderBy('created_at');
    }

    /**
     * Asked for, never sent, and old enough that the period it quotes has
     * stopped being meaningful. Reusing one would bill the shop for a month
     * that has already passed. The exact complement of scopeAwaitingTransfer().
     */
    public function scopeStaleIntent(Builder $query): Builder
    {
        return $query->unclaimedIntent()
            ->where('created_at', '<', self::intentExpiryCutoff());
    }
}
