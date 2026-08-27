<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Guards a product_variant slug against colliding with a path segment the
 * storefront frontend needs at its own root — once product pages live at
 * something like /{slug}, a product slug of "cart" or "admin" would be
 * ambiguous with the real cart/admin route, not just an ugly URL.
 *
 * Not length-aware on purpose. Today's slugs are a fixed 8-character
 * random code (see ProductService::generateVariantSlug()), so most of
 * these words could never collide by chance under the current scheme —
 * but hard-coding that assumption into this check would make it silently
 * useless the moment slug generation changes (a different length, or an
 * admin-editable "vanity slug" field, which is the scenario this really
 * guards against: a shop owner deliberately typing "checkout" as their
 * own product's slug).
 */
class NotReservedSlug implements ValidationRule
{
    public const RESERVED = [
        // Storefront-facing routes
        'cart', 'checkout', 'login', 'logout', 'register', 'signup', 'account',
        'orders', 'wishlist', 'search', 'about', 'contact', 'help', 'faq',
        'terms', 'privacy', 'shipping', 'returns',

        // Admin / system routes
        'admin', 'api', 'dashboard', 'settings', 'pos', 'products', 'categories',
        'customers', 'reports', 'auth',

        // Framework / infra paths that must never be shadowed
        'static', 'assets', 'public', 'storage', 'favicon', 'robots', 'sitemap',

        // Hostname labels that can't be a tenant subdomain. Only relevant
        // since this rule now also guards tenant slugs (which become
        // storefront subdomains), not just product slugs — 'www' already
        // routes to the admin app, and the rest are conventional
        // infrastructure hostnames a shop must never be able to claim.
        'www', 'mail', 'ftp', 'smtp', 'ns1', 'ns2', 'cdn', 'status',
    ];

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower($slug), self::RESERVED, true);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::isReserved((string) $value)) {
            $fail('This value is reserved and cannot be used.');
        }
    }
}
