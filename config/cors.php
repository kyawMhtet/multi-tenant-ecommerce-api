<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Explicit list, not '*': this app authenticates with a Bearer token
    // (Sanctum's stateful/cookie mode is never enabled — see
    // bootstrap/app.php), so a wildcard origin wouldn't actually be a
    // credential-leak risk here, but naming known frontend origins is
    // still the clearer default. Add production/staging Next.js domains
    // (the admin app, not the storefront) to CORS_ALLOWED_ORIGINS
    // (comma-separated) as they exist.
    'allowed_origins' => array_values(array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')))),

    // The storefront is one per-tenant subdomain (test-shop.localhost:3000
    // locally; {tenant}.example.com in production), so a fixed
    // allowed_origins list can't name every tenant — a pattern is the only
    // way to allow "any subdomain of our storefront host" without adding
    // an origin per tenant by hand. Each pattern is a raw PCRE regex (with
    // delimiters), matched via preg_match() against the full Origin header
    // — see vendor/fruitcake/php-cors's CorsService::isOriginAllowed().
    'allowed_origins_patterns' => array_values(array_filter(explode(',', env('CORS_ALLOWED_ORIGIN_PATTERNS', '#^http://[a-z0-9-]+\.localhost:3000$#')))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
