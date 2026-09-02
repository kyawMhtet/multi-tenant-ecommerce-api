<?php

namespace App\Services\Billing;

use App\Services\Billing\Contracts\BillingRail;
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
            fn (string $name) => $this->rail($name)->isAvailable($plan, $currency),
        ));
    }
}
