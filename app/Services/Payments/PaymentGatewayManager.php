<?php

namespace App\Services\Payments;

use App\Models\TenantPaymentMethod;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Gateways\ManualGateway;
use App\Services\Payments\Gateways\StripeGateway;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the right gateway for a configured payment method.
 *
 * The same shape Laravel uses for its own cache/queue/filesystem drivers
 * (see Illuminate\Filesystem\FilesystemManager): a registry of names to
 * factory closures, resolved on demand. Adding a provider is one entry
 * here plus one class — nothing else in the app changes.
 *
 * Gateways are resolved through the container rather than newed directly,
 * so each one declares its own dependencies (an SDK client, config)
 * normally instead of reaching for globals.
 */
class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /**
     * A null gateway on the method means there's genuinely no processor —
     * cash on delivery, bank transfer. Rather than returning null and
     * making every caller branch on "does this method have a gateway?",
     * that case resolves to ManualGateway, which implements the same
     * contract by doing nothing. Callers stay polymorphic; the database
     * stays honest about the absence.
     */
    public function for(TenantPaymentMethod $method): PaymentGateway
    {
        $name = $method->gateway ?? 'manual';

        return $this->resolved[$name] ??= $this->make($name);
    }

    /**
     * Used by the webhook routes, which know their provider from the URL
     * they're registered at (one endpoint per gateway) rather than from a
     * tenant's configuration — the webhook arrives before we know which
     * tenant it concerns.
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
