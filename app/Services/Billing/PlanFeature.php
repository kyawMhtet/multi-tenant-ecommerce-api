<?php

namespace App\Services\Billing;

/**
 * The complete set of things a plan can switch on. An enum rather than loose
 * strings so a typo is a fatal error at the call site instead of a feature
 * that silently never unlocks — the failure mode of `allows('profit_reports')`
 * vs `allows('profit_report')` is a shop paying for something it can't reach
 * and no exception anywhere.
 *
 * Deliberately small. Every entry is a real capability with an enforcement
 * point in code; a plan difference that has no enforcement point is marketing,
 * not a feature, and does not belong here.
 *
 * Note what is NOT here: the storefront, the POS, the catalogue, stock, and
 * orders. Those are the product. Gating them would mean a shop that stops
 * paying stops being able to sell, which is both how you lose a shop for good
 * and how you break public product links its customers already hold.
 */
enum PlanFeature: string
{
    /** Stripe Connect onboarding and `card` as a checkout method. */
    case CardPayments = 'card_payments';

    /** The sales & profit report — the only place unit_cost margins surface. */
    case ProfitReports = 'profit_reports';

    /** Selling below zero stock: allow_preorder on a variant. */
    case Preorder = 'preorder';
}

/*
 * Considered and rejected: gating couriers, dispatch and tracking numbers.
 *
 * It looks like a classic logistics upsell, and in most markets it would be.
 * Here it is the core selling flow — COD plus delivery is how the majority of
 * these shops trade, so a Starter shop unable to record which courier took a
 * parcel is a Starter shop that cannot run its actual business. Gating it
 * would gate the product.
 *
 * Three real gates beat four where one is wrong.
 */
