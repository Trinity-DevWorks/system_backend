<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', '*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        '*',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://app.localhost:3000',
        'http://*.localhost:3000',
    ],
    'allowed_origins_patterns' => [
        '#^http://[a-z0-9-]+\.localhost:3000$#',
        '#^http://[a-z0-9-]+\.app\.localhost:\d+$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
