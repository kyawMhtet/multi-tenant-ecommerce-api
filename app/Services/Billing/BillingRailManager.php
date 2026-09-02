<?php

namespace App\Services\Billing;

use App\Services\Billing\Contracts\BillingRail;
use App\Services\Billing\Data\RailAvailability;
use App\Services\Billing\Rails\ManualBillingRail;
use App\Services\Billing\Rails\StripeBillingRail;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves a billing rail by name, mirroring PaymentGatewayManager. Adding a
 * provider is one entry here plus one class.
 */
class BillingRailManager
{
    /** @var array<string, BillingRail> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /** @return list<string> */
    public function names(): array
    {
        return ['stripe', 'manual'];
    }

    public function rail(string $name): BillingRail
    {
        return $this->resolved[$name] ??= match ($name) {
            'stripe' => $this->container->make(StripeBillingRail::class),
            'manual' => $this->container->make(ManualBillingRail::class),
            default => throw new InvalidArgumentException("Unsupported billing rail [{$name}]."),
        };
    }

    /**
     * Which rails this deployment can actually offer for a plan. The settings
     * screen renders from this rather than a hardcoded list, so a missing
     * price id shows up as "card unavailable" instead of a dead button.
     *
     * @return list<string>
     */
    public function availableFor(string $plan, string $currency): array
    {
        return array_values(array_filter(
            $this->names(),
            fn (string $name) => $this->rail($name)->availability($plan, $currency)->isAvailable(),
        ));
    }

    /**
     * Every rail with its reason, not just the usable ones.
     *
     * availableFor() answers "which buttons do I render". This answers "what
     * do I say about the ones I can't" — and those need different words: a
     * missing bank account is "we're setting this up", while Stripe and Kyat
     * is "this will never work here". Collapsing both into an absent button
     * left a Myanmar shop being invited to get in touch about a card option
     * that cannot exist.
     *
     * @return array<string, RailAvailability>
     */
    public function statusFor(string $plan, string $currency): array
    {
        return collect($this->names())
            ->mapWithKeys(fn (string $name) => [
                $name => $this->rail($name)->availability($plan, $currency),
            ])
            ->all();
    }
}
