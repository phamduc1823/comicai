<?php

namespace App\Services;

use App\Services\Contracts\AiSearchServiceInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiSearchService implements AiSearchServiceInterface
{
    /**
     * Optional callable AI provider driver for semantic search and embedding generation.
     * Signature: function(string $query, array $filters, int $limit): array
     *
     * @var (callable(string, array, int): array)|null
     */
    protected $provider = null;

    /**
     * Optional callable summary generator driver.
     * Signature: function(string $description, array $genres, array $tags): ?string
     *
     * @var (callable(string, array, array): ?string)|null
     */
    protected $summaryDriver = null;

    /**
     * Maximum execution time before declaring timeout and falling back (in seconds).
     * CAI-002F & MVP_SCOPE.md mandate timeout <= 8s.
     */
    protected int $timeoutSeconds = 8;

    /**
     * Forced provider availability override for testing or manual maintenance mode.
     */
    protected ?bool $availabilityOverride = null;

    public function __construct(
        protected FallbackSearchService $fallbackService,
        ?callable $provider = null,
        ?int $timeoutSeconds = null
    ) {
        $this->provider = $provider;
        $this->timeoutSeconds = $timeoutSeconds ?? (int) config('ai.timeout', 8);
    }

    /**
     * Set or replace the primary AI provider driver.
     *
     * @param (callable(string, array, int): array)|null $provider
     */
    public function setProvider(?callable $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    /**
     * Set or replace the summary generator driver.
     *
     * @param (callable(string, array, array): ?string)|null $driver
     */
    public function setSummaryDriver(?callable $driver): self
    {
        $this->summaryDriver = $driver;
        return $this;
    }

    /**
     * Set execution timeout limit in seconds (must be <= 8s).
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeoutSeconds = max(1, min(8, $seconds));
        return $this;
    }

    /**
     * Explicitly override availability (useful for testing fallback triggering).
     */
    public function setAvailabilityOverride(?bool $isAvailable): self
    {
        $this->availabilityOverride = $isAvailable;
        return $this;
    }

    /**
     * Retrieve the underlying fallback search service.
     */
    public function getFallbackService(): FallbackSearchService
    {
        return $this->fallbackService;
    }

    /**
     * Check if the primary AI search provider is available and enabled.
     */
    public function isAvailable(): bool
    {
        if ($this->availabilityOverride !== null) {
            return $this->availabilityOverride;
        }

        $enabled = (bool) config('ai.enabled', false);

        return $enabled && $this->provider !== null;
    }

    /**
     * Execute natural language / semantic search with strict error boundary & fallback.
     *
     * If the AI provider is unavailable, times out, exceeds quota (HTTP 429), or throws
     * any exception, this method automatically catches it and delegates to FallbackSearchService.
     *
     * @param string $query Natural language search query
     * @param array<string, mixed> $filters Optional pre-filters
     * @param int $limit Maximum number of results to return
     * @return array<int, array<string, mixed>> Standardized list of search results
     */
    public function search(string $query, array $filters = [], int $limit = 15): array
    {
        // 1. If AI is not available / not configured, immediately delegate to fallback
        if (! $this->isAvailable()) {
            return $this->fallbackService->search($query, $filters, $limit);
        }

        // 2. Execute AI provider within protected boundary
        try {
            $startTime = microtime(true);

            $results = call_user_func($this->provider, $query, $filters, $limit);

            $elapsed = microtime(true) - $startTime;

            // Enforce timeout guard
            if ($elapsed > $this->timeoutSeconds) {
                Log::warning('AI search provider exceeded timeout threshold', [
                    'query_length' => mb_strlen($query),
                    'elapsed_seconds' => round($elapsed, 3),
                    'timeout_limit' => $this->timeoutSeconds,
                ]);

                return $this->executeFallbackWithNotice(
                    $query,
                    $filters,
                    $limit,
                    'Dịch vụ AI phản hồi chậm vượt quá thời gian tối đa; đã chuyển sang tìm kiếm từ khóa an toàn.'
                );
            }

            // Ensure returned results conform to contract
            if (! is_array($results)) {
                throw new \UnexpectedValueException('AI provider must return an array of results.');
            }

            return array_map(function ($item) {
                $item['is_fallback'] = false;
                return $item;
            }, $results);

        } catch (Throwable $e) {
            // Log warning without exposing sensitive user queries or API credentials
            Log::warning('AI search provider failed, invoking safe database fallback', [
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'query_length' => mb_strlen($query),
            ]);

            return $this->executeFallbackWithNotice(
                $query,
                $filters,
                $limit,
                'Dịch vụ AI tạm thời gián đoạn hoặc cạn quota; đã chuyển sang tìm kiếm từ khóa an toàn.'
            );
        }
    }

    /**
     * Search comics using structured AI-extracted clues with safe error boundary.
     *
     * @param array<string, mixed> $clues Structured clues from query understanding
     * @param array<string, mixed> $filters Optional pre-filters
     * @param int $limit Maximum number of results to return
     * @return array<int, array<string, mixed>> Standardized list of search results
     */
    public function searchWithClues(array $clues, array $filters = [], int $limit = 15): array
    {
        try {
            return $this->fallbackService->searchWithClues($clues, $filters, $limit);
        } catch (Throwable $e) {
            Log::warning('Search with AI clues failed, using plain fallback', [
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
            ]);

            $series = $clues['series'] ?? '';
            $keywords = implode(' ', $clues['keywords'] ?? []);
            $fallbackQuery = trim("{$series} {$keywords}");

            return $this->fallbackService->search($fallbackQuery ?: 'truyen', $filters, $limit);
        }
    }

    /**
     * Generate semantic summary with safe fallback.
     */
    public function generateSummary(string $description, array $genres = [], array $tags = []): ?string
    {
        if (! $this->isAvailable() || $this->summaryDriver === null) {
            return $this->fallbackService->generateSummary($description, $genres, $tags);
        }

        try {
            $summary = call_user_func($this->summaryDriver, $description, $genres, $tags);
            return is_string($summary) ? $summary : $this->fallbackService->generateSummary($description, $genres, $tags);
        } catch (Throwable $e) {
            Log::warning('AI summary generation failed, using fallback excerpt', [
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
            ]);

            return $this->fallbackService->generateSummary($description, $genres, $tags);
        }
    }

    /**
     * Helper to run fallback search and decorate results with a user-friendly notice.
     */
    protected function executeFallbackWithNotice(string $query, array $filters, int $limit, string $notice): array
    {
        $fallbackResults = $this->fallbackService->search($query, $filters, $limit);

        return array_map(function ($item) use ($notice) {
            $item['is_fallback'] = true;
            $item['fallback_notice'] = $notice;
            return $item;
        }, $fallbackResults);
    }
}
