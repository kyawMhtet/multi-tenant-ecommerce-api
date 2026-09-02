<?php

namespace App\Services\Payments;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

/**
 * Onboards a shop onto Stripe Connect so it receives its own money.
 *
 * Bank details, tax ids and identity documents never touch this app —
 * Stripe hosts that flow and owns verification.
 */
class StripeConnectService
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * The account id is persisted BEFORE the link is generated: reversed, a
     * failed save would orphan a Stripe account and the next attempt would
     * create another — duplicates that are awkward to clean up once they hold
     * money. Links are single-use, so calling this repeatedly is fine.
     */
    public function createOnboardingLink(Tenant $tenant, string $returnUrl, string $refreshUrl): string
    {
        $accountId = $tenant->stripe_account_id ?? $this->createAccount($tenant);

        return $this->stripe->accountLinks->create([
            'account' => $accountId,
            'type' => 'account_onboarding',
            // Stripe sends the owner here when the link expires. It must mint
            // a NEW link, not show an error — timing out is normal.
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
        ])->url;
    }

    /**
     * DO NOT "simplify" this to `type => 'express'` — it fails at account
     * creation, and the reason is not obvious.
     *
     * This platform's Stripe account is Thai. `type => 'express'` implies the
     * PLATFORM absorbs losses when a seller can't cover a chargeback, and
     * Stripe forbids that for TH-based platforms. `stripe_dashboard.type =>
     * 'express'` is rejected for the same reason. The two constraints only
     * intersect at the Standard shape, so a Thai platform cannot offer Express
     * accounts at all — shop owners get the full dashboard, forced not chosen.
     *
     * losses.payments='stripe' means Stripe covers a seller's unpayable
     * chargebacks, not us. fees.payer='account' is what "shop is merchant of
     * record" means and pairs with direct charges.
     *
     * Country is inherited from the platform account, never set here: letting
     * a form choose it produces accounts that fail verification for reasons
     * the shop owner can't act on.
     */
    private function createAccount(Tenant $tenant): string
    {
        $account = $this->stripe->accounts->create([
            'controller' => [
                'losses' => ['payments' => 'stripe'],
                'fees' => ['payer' => 'account'],
                'stripe_dashboard' => ['type' => 'full'],
                'requirement_collection' => 'stripe',
            ],
            'email' => $tenant->business_email ?? $tenant->owner_email,
            'business_profile' => [
                'name' => $tenant->name,
            ],
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'tenant_slug' => $tenant->slug,
            ],
        ]);

        // A fresh query, not $tenant->save(): the caller's instance may be
        // stale and must not clobber other columns it's holding.
        DB::transaction(function () use ($tenant, $account) {
            Tenant::whereKey($tenant->id)->update(['stripe_account_id' => $account->id]);
        });

        $tenant->stripe_account_id = $account->id;

        return $account->id;
    }

    /**
     * Asked of Stripe, never cached: verification state changes on their side
     * without warning, and a cached "yes" means offering card checkout on a
     * shop that can no longer accept it.
     *
     * details_submitted separates "hasn't finished the form" from "finished,
     * still under review" — different messages for the owner.
     */
    public function status(Tenant $tenant): array
    {
        if (blank($tenant->stripe_account_id)) {
            return [
                'connected' => false,
                'details_submitted' => false,
                'charges_enabled' => false,
                'payouts_enabled' => false,
            ];
        }

        $account = $this->stripe->accounts->retrieve($tenant->stripe_account_id);

        return [
            'connected' => true,
            'details_submitted' => (bool) $account->details_submitted,
            'charges_enabled' => (bool) $account->charges_enabled,
            'payouts_enabled' => (bool) $account->payouts_enabled,
        ];
    }
}
