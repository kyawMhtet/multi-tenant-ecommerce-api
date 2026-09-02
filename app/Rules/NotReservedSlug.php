<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Stops a slug colliding with a path the storefront needs at its own root:
 * with product pages at /{slug}, a slug of "cart" is ambiguous with the real
 * cart route, not just ugly.
 *
 * Not length-aware on purpose. Today's 8-char random slugs could never hit
 * these by chance, but baking that in would make the check useless the moment
 * a vanity-slug field lets an owner type "checkout" deliberately.
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

        // Hostname labels a tenant subdomain must never claim — this rule also
        // guards tenant slugs, which become storefront subdomains.
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
