<?php

namespace App\Services;

use App\Exceptions\NonRetryableEmbeddingException;
use App\Exceptions\TransientEmbeddingException;
use App\Services\Contracts\EmbeddingServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeminiEmbeddingService implements EmbeddingServiceInterface
{
    protected ?string $apiKey;
    protected string $model;
    protected int $defaultDimensions;
    protected int $timeout;
    protected string $baseUrl;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?int $defaultDimensions = null,
        ?int $timeout = null,
        ?string $baseUrl = null
    ) {
        $this->apiKey = $apiKey ?? config('ai.gemini.api_key');
        $this->model = $model ?? config('ai.gemini.embedding_model', 'gemini-embedding-001');
        $this->defaultDimensions = $defaultDimensions ?? (int) config('ai.gemini.embedding_dimensions', 768);
        $this->timeout = $timeout ?? (int) config('ai.gemini.timeout', 8);
        $this->baseUrl = rtrim($baseUrl ?? config('ai.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
    }

    /**
     * Check if Gemini API credentials and model are configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Generate an L2-normalized vector embedding for a given text.
     *
     * @param string $text Input text
     * @param int|null $dimensions Optional target dimensions
     * @return array<int, float>|null L2-normalized vector or null if unconfigured/empty
     *
     * @throws TransientEmbeddingException
     * @throws NonRetryableEmbeddingException
     */
    public function embed(string $text, ?int $dimensions = null): ?array
    {
        $normalizedText = trim($text);

        if (mb_strlen($normalizedText) === 0) {
            return null;
        }

        if (! $this->isConfigured()) {
            Log::info('Gemini embedding generation skipped: API key is not configured.');
            return null;
        }

        $targetDimensions = $dimensions ?? $this->defaultDimensions;
        $effectiveTimeout = max(1, min(8, $this->timeout)); // Cap at 8s per CAI-002F

        try {
            $url = "{$this->baseUrl}/models/{$this->model}:embedContent";

            $response = Http::timeout($effectiveTimeout)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ])
                ->post($url, [
                    'model' => "models/{$this->model}",
                    'content' => [
                        'parts' => [
                            ['text' => $normalizedText],
                        ],
                    ],
                    'outputDimensionality' => $targetDimensions,
                ]);

            if ($response->status() === 429) {
                Log::warning('Gemini Embedding API rate limit exceeded (HTTP 429)', [
                    'content_length' => mb_strlen($normalizedText),
                ]);
                throw new TransientEmbeddingException('Gemini Embedding API rate limit exceeded (HTTP 429).');
            }

            if ($response->status() >= 500) {
                Log::warning('Gemini Embedding API server error', [
                    'status' => $response->status(),
                    'content_length' => mb_strlen($normalizedText),
                ]);
                throw new TransientEmbeddingException("Gemini Embedding API server error (HTTP {$response->status()}).");
            }

            if (! $response->successful()) {
                Log::warning('Gemini Embedding API client error', [
                    'status' => $response->status(),
                    'content_length' => mb_strlen($normalizedText),
                ]);
                throw new NonRetryableEmbeddingException("Gemini Embedding API client error (HTTP {$response->status()}).");
            }

            $rawValues = $response->json('embedding.values');

            return $this->validateAndNormalizeVector($rawValues, $targetDimensions);

        } catch (TransientEmbeddingException | NonRetryableEmbeddingException $e) {
            throw $e;
        } catch (Throwable $e) {
            $sanitizedMessage = preg_replace('/key=[a-zA-Z0-9_\-]+/', 'key=[REDACTED]', $e->getMessage());

            Log::warning('Gemini embedding generation connection/timeout exception', [
                'error_type' => get_class($e),
                'error_message' => $sanitizedMessage,
                'content_length' => mb_strlen($normalizedText),
            ]);

            throw new TransientEmbeddingException('Gemini Embedding API connection or timeout failure: ' . $sanitizedMessage, 0, $e);
        }
    }

    /**
     * Validate raw vector values and perform L2 normalization.
     *
     * @param mixed $rawValues
     * @param int $expectedDimensions
     * @return array<int, float>
     *
     * @throws NonRetryableEmbeddingException
     */
    public function validateAndNormalizeVector(mixed $rawValues, int $expectedDimensions): array
    {
        if (! is_array($rawValues)) {
            throw new NonRetryableEmbeddingException('Gemini Embedding API response missing embedding.values array.');
        }

        $actualCount = count($rawValues);
        if ($actualCount !== $expectedDimensions) {
            throw new NonRetryableEmbeddingException("Vector dimension mismatch: expected {$expectedDimensions}, received {$actualCount}.");
        }

        $sumSquares = 0.0;
        foreach ($rawValues as $val) {
            if (! is_numeric($val)) {
                throw new NonRetryableEmbeddingException('Vector contains non-numeric element.');
            }
            $floatVal = (float) $val;
            if (is_nan($floatVal) || is_infinite($floatVal)) {
                throw new NonRetryableEmbeddingException('Vector contains NaN or Infinite value.');
            }
            $sumSquares += $floatVal * $floatVal;
        }

        $norm = sqrt($sumSquares);
        // Reject vectors with zero norm (all zeros), NaN or Infinite norm
        if ($norm <= 1e-9 || is_nan($norm) || is_infinite($norm)) {
            throw new NonRetryableEmbeddingException('Vector Euclidean norm is zero or degenerate.');
        }

        // L2 normalize: each element divided by Euclidean norm
        $normalized = [];
        foreach ($rawValues as $val) {
            $normalized[] = (float) (((float) $val) / $norm);
        }

        return $normalized;
    }
}
