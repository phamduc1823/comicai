<?php

namespace App\Services\Contracts;

interface EmbeddingServiceInterface
{
    /**
     * Generate an L2-normalized vector embedding for a given text corpus.
     *
     * @param string $text Input text to be embedded
     * @param int|null $dimensions Target vector dimensions (defaults to config)
     * @return array<int, float>|null L2-normalized vector array, or null when unconfigured/empty
     *
     * @throws \App\Exceptions\TransientEmbeddingException When a temporary error occurs (HTTP 429, 5xx, timeout, network failure)
     * @throws \App\Exceptions\NonRetryableEmbeddingException When a permanent error occurs (HTTP 400, malformed vector, zero norm)
     */
    public function embed(string $text, ?int $dimensions = null): ?array;

    /**
     * Check if the embedding provider is configured and available.
     *
     * @return bool
     */
    public function isConfigured(): bool;
}
