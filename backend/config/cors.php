<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| BlueToon uses Sanctum SPA cookie/session authentication, so credentialed
| cross-origin requests must be allowed for the Vue frontend origin only.
|
| Browsers reject `Access-Control-Allow-Origin: *` together with credentials,
| and a wildcard combined with credentials would let arbitrary sites act on a
| logged-in user's behalf. Any "*" entry is therefore stripped below; origins
| must be listed explicitly via CORS_ALLOWED_ORIGINS (comma separated).
|
| To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
|
*/

$allowedOrigins = array_values(array_filter(
    array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5173,http://127.0.0.1:5173'
    ))),
    fn (string $origin): bool => $origin !== '' && $origin !== '*'
));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'X-CSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
