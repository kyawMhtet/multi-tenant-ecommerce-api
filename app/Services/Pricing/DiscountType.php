<?php

namespace App\Services\Pricing;

/**
 * How a variant's discount is expressed.
 *
 * An enum rather than a free string for the same reason PlanFeature is one:
 * each case implies arithmetic the app has to perform, so a third case can't
 * be invented in a database row — it would name a calculation nothing knows
 * how to do. The match() below is exhaustive, so adding a case surfaces as a
 * compile-time hole rather than a silently-zero discount.
 *
 * Percent is the primary case. It's what shops here actually advertise, and
 * it survives a reprice: a stored "sale price" would silently deepen or
 * invert the moment selling_price moved, since the two would be two sources
 * of truth for one fact. Fixed exists because "500 MMK off" is also real, and
 * it makes the pair a strict superset of any single sale-price column.
 */
enum DiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Money off ONE unit, given that unit's list price.
     *
     * Clamped to the list price, so a discount can never produce a negative
     * price — a fixed 5,000 off an item repriced down to 3,000 makes it free,
     * not a debt to the customer. The clamp is the real guard rather than
     * validation, because selling_price is mutable: a fixed amount that was
     * sane when it was set can be excessive a month later without anyone
     * touching the discount.
     *
     * Deliberately NOT clamped at buying_price. Selling below cost is
     * clearance, a real thing shops do on purpose, and margin reporting shows
     * the loss honestly — same position as a variant sitting at -7 stock.
     */
    public function amountOff(float $listPrice, float $value): float
    {
        $off = match ($this) {
            self::Percent => $listPrice * $value / 100,
            self::Fixed => $value,
        };

        return round(min(max($off, 0), max($listPrice, 0)), 2);
    }
}
