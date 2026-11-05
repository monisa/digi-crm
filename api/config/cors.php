<?php

/*
| Only the CRM website may call the API from a browser. (Logins use bearer
| tokens, not cookies; this is an extra fence.)
*/
return [
    'paths' => ['*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_unique([
        rtrim((string) env('FRONTEND_URL', 'http://localhost:4200'), '/'),
        env('APP_ENV') === 'local' ? 'http://localhost:4200' : null,
    ]))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
