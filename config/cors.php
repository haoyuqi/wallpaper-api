<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'HEAD'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*')),
    ), fn (string $origin): bool => $origin !== '')),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type'],
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
    'max_age' => 600,
    'supports_credentials' => false,
];
