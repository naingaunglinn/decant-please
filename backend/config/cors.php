<?php

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Multi-tenancy (Step 24): a second shop's storefront is its own domain, so the
    // allowlist accepts a comma-separated FRONTEND_URL — still one value today, but
    // adding shop #2's origin later needs no config-shape change.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3001'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
