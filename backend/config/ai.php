<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Search & Embedding Provider Configuration
    |--------------------------------------------------------------------------
    |
    | In accordance with CAI-002F and MVP_SCOPE.md, the AI provider is kept
    | OPEN / PENDING REVIEW. External API calls are disabled by default.
    |
    */

    'enabled' => (bool) env('AI_SEARCH_ENABLED', false),

    'provider' => env('AI_PROVIDER', null),

    'timeout' => (int) env('AI_REQUEST_TIMEOUT', 8),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'timeout' => (int) env('AI_REQUEST_TIMEOUT', 8),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

];
