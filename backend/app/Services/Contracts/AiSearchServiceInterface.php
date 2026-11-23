<?php

namespace App\Services\Contracts;

interface AiSearchServiceInterface
{
    /**
     * Search comics using natural language / semantic query with fallback capability.
     *
     * @param string $query Natural language search query (e.g. "truyện về kiếm thuật học đường")
     * @param array<string, mixed> $filters Optional pre-filters (status, age_rating, genre, tag, etc.)
     * @param int $limit Maximum number of results to return
     * @return array<int, array<string, mixed>> Standardized list of search results
     */
    public function search(string $query, array $filters = [], int $limit = 15): array;

    /**
     * Search comics using structured AI-extracted clues (series, characters, visual clues, events).
     *
     * @param array<string, mixed> $clues Structured clues from query understanding
     * @param array<string, mixed> $filters Optional pre-filters
     * @param int $limit Maximum number of results to return
     * @param string|null $rawQuery Optional raw user query text for semantic retrieval
     * @return array<int, array<string, mixed>> Standardized list of search results
     */
    public function searchWithClues(array $clues, array $filters = [], int $limit = 15, ?string $rawQuery = null): array;

    /**
     * Search comics using hybrid retrieval combining keyword matching and vector semantic similarity.
     * Formula: hybrid_score = alpha * semantic_score + (1 - alpha) * keyword_score
     *
     * @param string $query Natural language search query
     * @param array<string, mixed> $filters Optional pre-filters
     * @param int $limit Maximum number of results to return
     * @param array<string, mixed>|null $clues Optional structured clues from query understanding
     * @return array<int, array<string, mixed>> Standardized list of search results
     */
    public function searchHybrid(string $query, array $filters = [], int $limit = 15, ?array $clues = null): array;

    /**
     * Generate a structured semantic summary from description, genres, and tags.
     *
     * @param string $description Comic raw description
     * @param array<int, string> $genres Genre names
     * @param array<int, string> $tags Tag names
     * @return string|null Formatted summary or null
     */
    public function generateSummary(string $description, array $genres = [], array $tags = []): ?string;

    /**
     * Check if the primary AI search provider is available and configured.
     *
     * @return bool
     */
    public function isAvailable(): bool;
}
