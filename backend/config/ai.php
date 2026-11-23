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

    'query_embedding_cache_ttl' => (int) env('AI_QUERY_EMBEDDING_CACHE_TTL', 86400 * 7),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
        'embedding_dimensions' => (int) env('GEMINI_EMBEDDING_DIMENSIONS', 768),
        'timeout' => (int) env('AI_REQUEST_TIMEOUT', 8),

    'query_embedding_cache_ttl' => (int) env('AI_QUERY_EMBEDDING_CACHE_TTL', 86400 * 7),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hybrid Search & Semantic Retrieval Configuration
    |--------------------------------------------------------------------------
    |
    | - alpha: Weight for semantic score in hybrid ranking formula:
    |   hybrid_score = alpha * semantic_score + (1 - alpha) * keyword_score
    |   Baseline: 0.55.
    | - threshold: Minimum semantic similarity threshold for semantic-only matches.
    |   Baseline: 0.35.
    |
    */
    'hybrid' => [
        'alpha' => (float) env('AI_HYBRID_ALPHA', 0.55),
        'threshold' => (float) env('AI_SEMANTIC_THRESHOLD', 0.35),
        'candidate_limit' => (int) env('AI_HYBRID_CANDIDATE_LIMIT', 100),
    ],

];
