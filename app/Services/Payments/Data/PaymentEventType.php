<?php

namespace App\Services\Payments\Data;

/**
 * The complete vocabulary this application understands about a payment.
 *
 * Every gateway speaks its own dialect — Stripe says
 * "checkout.session.completed", 2C2P posts a form with a status code,
 * MyanMyanPay will do something else again. Each gateway class translates
 * its dialect into one of these three cases at the edge, and nothing
 * downstream ever learns which provider was involved.
 *
 * Keeping this set deliberately tiny is the point. If a fourth case ever
 * feels necessary, that's a signal a gateway detail is leaking into the
 * application — check whether it can be expressed as one of these first.
 */
enum PaymentEventType: string
{
    /** Money captured. The order can be marked paid. */
    case Succeeded = 'succeeded';

    /** The customer never completed payment and the window closed. Release reserved stock. */
    case Expired = 'expired';

    /** The attempt was made and declined. The order stays unpaid; the customer may retry. */
    case Failed = 'failed';
}
