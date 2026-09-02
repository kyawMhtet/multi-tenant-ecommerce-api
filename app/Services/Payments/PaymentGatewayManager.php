<?php

namespace App\Services\Payments;

use App\Models\TenantPaymentMethod;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Gateways\ManualGateway;
use App\Services\Payments\Gateways\StripeGateway;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the gateway for a configured payment method. Adding a provider is
 * one entry here plus one class. Resolved through the container so each
 * gateway declares its own dependencies rather than reaching for globals.
 */
class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /**
     * A null gateway means genuinely no processor. Rather than returning null
     * and making every caller branch, it resolves to ManualGateway, which
     * implements the same contract by doing nothing.
     */
    public function for(TenantPaymentMethod $method): PaymentGateway
    {
        $name = $method->gateway ?? 'manual';

        return $this->resolved[$name] ??= $this->make($name);
    }

    /**
     * For webhook routes, which know their provider from the URL rather than
     * from config — the webhook arrives before we know which tenant it's for.
     */
    public function gateway(string $name): PaymentGateway
    {
        return $this->resolved[$name] ??= $this->make($name);
    }

    private function make(string $name): PaymentGateway
    {
        return match ($name) {
            'stripe' => $this->container->make(StripeGateway::class),
            'manual' => $this->container->make(ManualGateway::class),
            default => throw new InvalidArgumentException("Unsupported payment gateway [{$name}]."),
        };
    }
}
