<?php

namespace App\Services;

use App\Models\Comic;
use App\Services\Contracts\AiSearchServiceInterface;
use App\Services\Contracts\EmbeddingServiceInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        protected ?EmbeddingServiceInterface $embeddingService = null,
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
     * Set or replace the embedding service.
     */
    public function setEmbeddingService(?EmbeddingServiceInterface $embeddingService): self
    {
        $this->embeddingService = $embeddingService;
        return $this;
    }

    /**
     * Retrieve the underlying embedding service.
     */
    public function getEmbeddingService(): ?EmbeddingServiceInterface
    {
        return $this->embeddingService;
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
     * Check if the primary AI search provider or embedding service is available and enabled.
     */
    public function isAvailable(): bool
    {
        if ($this->availabilityOverride !== null) {
            return $this->availabilityOverride;
        }

        $enabled = (bool) config('ai.enabled', false);

        if (! $enabled) {
            return false;
        }

        if ($this->provider !== null) {
            return true;
        }

        return $this->embeddingService !== null && $this->embeddingService->isConfigured();
    }

    /**
     * Compute Cosine Similarity between two numeric vectors.
     *
     * @param array<int, mixed> $vecA
     * @param array<int, mixed> $vecB
     * @return float Cosine similarity clamped to [-1.0, 1.0], or 0.0 on error/invalid data
     */
    public static function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dimA = count($vecA);
        $dimB = count($vecB);

        // Vector must not be empty and must have matching dimensions
        if ($dimA === 0 || $dimA !== $dimB) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $dimA; $i++) {
            $valA = $vecA[$i];
            $valB = $vecB[$i];

            // Strict numeric validation: reject non-numeric, NaN, Infinity
            if (! is_numeric($valA) || ! is_numeric($valB)) {
                return 0.0;
            }

            $floatA = (float) $valA;
            $floatB = (float) $valB;

            if (is_nan($floatA) || is_nan($floatB) || is_infinite($floatA) || is_infinite($floatB)) {
                return 0.0;
            }

            $dotProduct += $floatA * $floatB;
            $normA += $floatA * $floatA;
            $normB += $floatB * $floatB;
        }

        // Handle zero norm vector safely (cannot divide by zero)
        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        $denominator = sqrt($normA) * sqrt($normB);
        if ($denominator <= 0.0) {
            return 0.0;
        }

        $similarity = $dotProduct / $denominator;

        // Clamp to [-1.0, 1.0] for precision safety
        return max(-1.0, min(1.0, $similarity));
    }

    /**
     * Build corpus text strictly from approved comic data matching GenerateComicEmbeddingJob.
     */
    public static function buildComicCorpus(Comic $comic): string
    {
        $genres = $comic->relationLoaded('genres') && $comic->genres
            ? $comic->genres->pluck('name')->filter()->implode(', ')
            : '';
        $tags = $comic->relationLoaded('tags') && $comic->tags
            ? $comic->tags->pluck('name')->filter()->implode(', ')
            : '';

        $parts = [
            "Tiêu đề: {$comic->title}",
            ! empty($comic->alternative_titles) ? "Tên khác: {$comic->alternative_titles}" : null,
            "Tác giả: {$comic->author_name}",
            ! empty($genres) ? "Thể loại: {$genres}" : null,
            ! empty($tags) ? "Đặc điểm: {$tags}" : null,
            ! empty($comic->description) ? "Cốt truyện: {$comic->description}" : null,
            ! empty($comic->ai_summary) ? "Bối cảnh & sự kiện: {$comic->ai_summary}" : null,
        ];

        return implode("\n", array_filter($parts));
    }

    /**
     * Verify whether a comic's embedding matches its current database corpus.
     */
    public static function isEmbeddingFresh(Comic $comic): bool
    {
        if (empty($comic->embedding) || empty($comic->embedding_hash)) {
            return false;
        }

        $corpus = self::buildComicCorpus($comic);
        $expectedHash = hash('sha256', $corpus);

        return hash_equals($comic->embedding_hash, $expectedHash);
    }

    /**
     * Execute natural language / semantic search with strict error boundary & fallback.
     *
     * @param string $query Natural language search query
     * @param array<string, mixed> $filters Optional pre-filters
     * @param int $limit Maximum number of results to return
     * @return array<int, array<string, mixed>> Standardized list of search results
     */
    public function search(string $query, array $filters = [], int $limit = 15): array
    {
        // 1. If custom provider is set, execute provider within timeout guard
        if ($this->provider !== null && $this->isAvailable()) {
            return $this->executeCustomProvider($query, $filters, $limit);
        }

        // 2. If AI / Embedding is available, perform Hybrid Semantic Search
        if ($this->isAvailable() && $this->embeddingService !== null) {
            return $this->searchHybrid($query, $filters, $limit);
        }

        // 3. Otherwise immediately delegate to fallback
        return $this->fallbackService->search($query, $filters, $limit);
    }

    /**
     * Search comics using structured AI-extracted clues with safe error boundary.
     *
     * @param array<string, mixed> $clues Structured clues from query understanding
     * @param array<string, mixed> $filters Optional pre-filters
     * @param int $limit Maximum number of results to return
     * @param string|null $rawQuery Optional raw user query text for semantic retrieval
     * @return array<int, array<string, mixed>> Standardized list of search results
     */
    public function searchWithClues(array $clues, array $filters = [], int $limit = 15, ?string $rawQuery = null): array
    {
        // If AI is enabled and we have the raw user query, perform hybrid search with clues
        if ($this->isAvailable() && $this->embeddingService !== null && $rawQuery !== null && mb_strlen(trim($rawQuery)) >= 2) {
            return $this->searchHybrid($rawQuery, $filters, $limit, $clues);
        }

        try {
            return $this->fallbackService->searchWithClues($clues, $filters, $limit, $rawQuery);
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
     * Search comics using hybrid retrieval combining keyword matching and vector semantic similarity.
     * Formula: hybrid_score = alpha * semantic_score + (1 - alpha) * keyword_score
     *
     * @param string $query Natural language search query
     * @param array<string, mixed> $filters Optional pre-filters
     * @param int $limit Maximum number of results to return
     * @param array<string, mixed>|null $clues Optional structured clues from query understanding
     * @return array<int, array<string, mixed>> Standardized list of search results
     */
    public function searchHybrid(string $query, array $filters = [], int $limit = 15, ?array $clues = null): array
    {
        $normalizedQuery = trim($query);

        if (mb_strlen($normalizedQuery) < 2) {
            return [];
        }

        if (mb_strlen($normalizedQuery) > 300) {
            $normalizedQuery = mb_substr($normalizedQuery, 0, 300);
        }

        // 1. Generate or retrieve query embedding from cache if service is available
        $queryEmbedding = null;
        if ($this->isAvailable() && $this->embeddingService !== null) {
            $model = (string) config('ai.gemini.embedding_model', 'text-embedding-004');
            $dims = (int) config('ai.embedding_dimensions', 768);
            $queryHash = hash('sha256', mb_strtolower(trim($normalizedQuery)));
            $cacheKey = "ai:query_embedding:{$model}:{$dims}:{$queryHash}";
            $cacheTtl = (int) config('ai.query_embedding_cache_ttl', 86400 * 7);

            try {
                $cached = Cache::get($cacheKey);
                if (is_array($cached) && ! empty($cached)) {
                    $queryEmbedding = $cached;
                }
            } catch (Throwable $e) {
                Log::debug('Failed to read query embedding cache', ['error' => $e->getMessage()]);
            }

            if ($queryEmbedding === null) {
                try {
                    $startTime = microtime(true);
                    $queryEmbedding = $this->embeddingService->embed($normalizedQuery);
                    $elapsed = microtime(true) - $startTime;

                    if ($elapsed > $this->timeoutSeconds) {
                        Log::warning('Query embedding generation exceeded timeout threshold', [
                            'elapsed_seconds' => round($elapsed, 3),
                            'timeout_limit' => $this->timeoutSeconds,
                        ]);
                        $queryEmbedding = null;
                    } elseif (is_array($queryEmbedding) && ! empty($queryEmbedding)) {
                        try {
                            Cache::put($cacheKey, $queryEmbedding, $cacheTtl);
                        } catch (Throwable $e) {
                            Log::debug('Failed to write query embedding cache', ['error' => $e->getMessage()]);
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning('Query embedding generation failed, falling back to keyword search', [
                        'error_type' => get_class($e),
                        'error_message' => $e->getMessage(),
                    ]);
                    $queryEmbedding = null;
                }
            }
        }

        // If query embedding could not be generated, fall back safely
        if ($queryEmbedding === null) {
            if (! empty($clues)) {
                return $this->fallbackService->searchWithClues($clues, $filters, $limit, $normalizedQuery);
            }

            return $this->executeFallbackWithNotice(
                $normalizedQuery,
                $filters,
                $limit,
                'Dịch vụ AI tạo embedding tạm thời gián đoạn; đã tự động chuyển sang tìm kiếm từ khóa an toàn.'
            );
        }

        // 2. Fetch keyword/clues candidates
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $offset = ($page - 1) * $limit;
        $candidateFloor = (int) config('ai.hybrid.candidate_limit', 100);
        $candidateLimit = max($candidateFloor, ($offset + $limit) * 3);

        $keywordResults = ! empty($clues)
            ? $this->fallbackService->searchWithClues($clues, $filters, $candidateLimit, $normalizedQuery)
            : $this->fallbackService->search($normalizedQuery, $filters, $candidateLimit);

        $keywordMap = [];
        foreach ($keywordResults as $item) {
            $keywordMap[$item['id']] = $item;
        }

        // 3. Retrieve approved comics with embeddings for semantic retrieval
        $semanticQuery = Comic::query()
            ->where('approval_status', 'approved')
            ->whereNotNull('embedding')
            ->with([
                'genres:id,name,slug',
                'tags:id,name,slug',
            ]);

        // Pre-filtering: Comic status (exclude 'draft' by default)
        if (! empty($filters['status'])) {
            $semanticQuery->where('status', $filters['status']);
        } else {
            $semanticQuery->where('status', '!=', 'draft');
        }

        if (! empty($filters['age_rating'])) {
            $semanticQuery->where('age_rating', $filters['age_rating']);
        }

        if (! empty($filters['genre'])) {
            $semanticQuery->whereHas('genres', function (Builder $q) use ($filters) {
                if (is_numeric($filters['genre'])) {
                    $q->where('genres.id', (int) $filters['genre']);
                } else {
                    $q->where('genres.slug', $filters['genre']);
                }
            });
        }

        if (! empty($filters['tag'])) {
            $semanticQuery->whereHas('tags', function (Builder $q) use ($filters) {
                if (is_numeric($filters['tag'])) {
                    $q->where('tags.id', (int) $filters['tag']);
                } else {
                    $q->where('tags.slug', $filters['tag']);
                }
            });
        }

        if (Schema::hasColumn('comics', 'deleted_at')) {
            $semanticQuery->whereNull('deleted_at');
        }

        $comicsWithEmbedding = $semanticQuery->get([
            'id', 'title', 'slug', 'cover_path', 'author_name', 'age_rating', 'status', 'approval_status',
            'views_count', 'ai_summary', 'embedding', 'embedding_hash', 'description', 'alternative_titles',
        ]);

        // 4. Calculate Cosine Similarity for each comic
        $semanticMap = [];
        $queryDim = count($queryEmbedding);

        foreach ($comicsWithEmbedding as $comic) {
            $vector = $comic->embedding;
            if (! is_array($vector) || count($vector) !== $queryDim) {
                continue;
            }

            // Stale check: if embedding_hash is present, verify against current corpus
            if (! empty($comic->embedding_hash)) {
                $corpus = self::buildComicCorpus($comic);
                if (! hash_equals($comic->embedding_hash, hash('sha256', $corpus))) {
                    continue;
                }
            }

            $sim = self::cosineSimilarity($queryEmbedding, $vector);
            $semanticScore = round(max(0.0, min(1.0, $sim)), 4);

            $semanticMap[$comic->id] = [
                'comic' => $comic,
                'semantic_score' => $semanticScore,
            ];
        }

        // 5. Combine via Hybrid Ranking
        $alpha = (float) config('ai.hybrid.alpha', 0.55);
        $threshold = (float) config('ai.hybrid.threshold', 0.35);

        // Clamp alpha and threshold safely to [0.0, 1.0]
        $alpha = max(0.0, min(1.0, $alpha));
        $threshold = max(0.0, min(1.0, $threshold));

        $allIds = array_values(array_unique(array_merge(array_keys($keywordMap), array_keys($semanticMap))));
        $hybridResults = [];

        foreach ($allIds as $id) {
            $kItem = $keywordMap[$id] ?? null;
            $sItem = $semanticMap[$id] ?? null;

            $keywordScore = $kItem ? (float) $kItem['score'] : 0.0;
            $semanticScore = $sItem ? (float) $sItem['semantic_score'] : null;

            $comicModel = $sItem['comic'] ?? ($kItem ? Comic::with(['genres', 'tags'])->find($id) : null);
            if (! $comicModel) {
                continue;
            }

            // Public eligibility guard
            if ($comicModel->approval_status !== 'approved' || $comicModel->status === 'draft') {
                continue;
            }

            // In-memory filter verification (defense-in-depth)
            if (! empty($filters['status']) && $comicModel->status !== $filters['status']) {
                continue;
            }
            if (! empty($filters['age_rating']) && $comicModel->age_rating !== $filters['age_rating']) {
                continue;
            }

            $reasons = [];
            $matchType = 'clue';

            // Determine active meaningful contributions
            $hasValidSemantic = ($semanticScore !== null && $semanticScore >= $threshold && $alpha > 0.0);
            $hasValidKeyword = ($keywordScore > 0.0 && (1.0 - $alpha) > 0.0);

            if ($hasValidSemantic && $hasValidKeyword) {
                // Both semantic and keyword contribute meaningfully
                $hybridScore = round($alpha * $semanticScore + (1.0 - $alpha) * $keywordScore, 4);
                $reasons[] = 'Khớp ngữ nghĩa AI (' . number_format($semanticScore, 4) . ')';
                if (! empty($kItem['match_reason'])) {
                    $reasons[] = $kItem['match_reason'];
                }
                $matchType = 'hybrid';
            } elseif ($hasValidSemantic) {
                // Semantic only contributes (either keywordScore = 0 or alpha = 1.0)
                $hybridScore = round($alpha * $semanticScore, 4);
                $reasons[] = 'Khớp ngữ nghĩa AI (' . number_format($semanticScore, 4) . ')';
                $matchType = 'semantic';
            } elseif ($hasValidKeyword) {
                // Keyword only contributes (either no embedding, or semanticScore < threshold, or alpha = 0.0)
                $hybridScore = round((1.0 - $alpha) * $keywordScore, 4);
                if (! empty($kItem['match_reason'])) {
                    $reasons[] = $kItem['match_reason'];
                }
                if ($semanticScore === null) {
                    $reasons[] = 'Chưa có embedding ngữ nghĩa';
                }
                $matchType = 'clue';
            } else {
                // Neither semantic nor keyword contributes meaningfully -> discard
                continue;
            }

            // Discard items where active weighted score is zero or negative
            if ($hybridScore <= 0.0) {
                continue;
            }

            $matchedTags = $kItem['matched_tags'] ?? ($comicModel->relationLoaded('tags') ? $comicModel->tags->pluck('name')->all() : []);
            $matchedGenres = $kItem['matched_genres'] ?? ($comicModel->relationLoaded('genres') ? $comicModel->genres->pluck('name')->all() : []);

            $hybridResults[] = [
                'id' => $comicModel->id,
                'title' => $comicModel->title,
                'slug' => $comicModel->slug,
                'cover_path' => $comicModel->cover_path,
                'cover_url' => $comicModel->cover_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($comicModel->cover_path) : null,
                'author_name' => $comicModel->author_name,
                'age_rating' => $comicModel->age_rating,
                'status' => $comicModel->status,
                'score' => round(max(0.05, min(1.0, $hybridScore)), 4),
                'is_fallback' => false,
                'match_type' => $matchType,
                'match_reason' => implode('; ', array_unique($reasons)),
                'matched_tags' => array_values(array_unique($matchedTags)),
                'matched_genres' => array_values(array_unique($matchedGenres)),
                'ai_summary' => $comicModel->ai_summary,
                'views_count' => (int) $comicModel->views_count,
            ];
        }

        // 6. Sort results: score DESC, views_count DESC, id DESC
        usort($hybridResults, function ($a, $b) {
            if ($b['score'] !== $a['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($b['views_count'] !== $a['views_count']) {
                return $b['views_count'] <=> $a['views_count'];
            }
            return $b['id'] <=> $a['id'];
        });

        // 7. Strip internal sorting field and slice with offset + limit
        return array_map(function ($item) {
            unset($item['views_count']);
            return $item;
        }, array_slice($hybridResults, $offset, $limit));
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
     * Helper to run custom callable provider with timeout guard.
     */
    protected function executeCustomProvider(string $query, array $filters, int $limit): array
    {
        try {
            $startTime = microtime(true);
            $results = call_user_func($this->provider, $query, $filters, $limit);
            $elapsed = microtime(true) - $startTime;

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

            if (! is_array($results)) {
                throw new \UnexpectedValueException('AI provider must return an array of results.');
            }

            return array_map(function ($item) {
                $item['is_fallback'] = false;
                return $item;
            }, $results);

        } catch (Throwable $e) {
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
