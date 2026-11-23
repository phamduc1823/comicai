<?php

namespace Tests\Feature;

use App\Exceptions\NonRetryableEmbeddingException;
use App\Exceptions\TransientEmbeddingException;
use App\Services\Contracts\EmbeddingServiceInterface;
use App\Services\GeminiEmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiEmbeddingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Config::set('ai.gemini.api_key', 'test-embedding-api-key');
        Config::set('ai.gemini.embedding_model', 'gemini-embedding-001');
        Config::set('ai.gemini.embedding_dimensions', 768);
        Config::set('ai.gemini.timeout', 8);
        Config::set('ai.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
    }

    /**
     * Helper to generate a dummy non-zero vector of given dimension.
     *
     * @param int $dim
     * @param float $val
     * @return array<int, float>
     */
    protected function makeDummyVector(int $dim = 768, float $val = 0.5): array
    {
        return array_fill(0, $dim, $val);
    }

    public function test_service_resolves_from_container(): void
    {
        $service = app(EmbeddingServiceInterface::class);
        $this->assertInstanceOf(GeminiEmbeddingService::class, $service);
        $this->assertTrue($service->isConfigured());
    }

    public function test_embed_returns_l2_normalized_vector_with_expected_dimensions(): void
    {
        $dummyValues = $this->makeDummyVector(768, 2.0);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent*' => Http::response([
                'embedding' => [
                    'values' => $dummyValues,
                ],
            ], 200),
        ]);

        $service = new GeminiEmbeddingService();
        $vector = $service->embed('Truyện tranh trinh thám học đường');

        $this->assertNotNull($vector);
        $this->assertIsArray($vector);
        $this->assertCount(768, $vector);

        // Calculate L2 norm: sqrt(sum(v_i^2)) must be ~1.0
        $sumSquares = 0.0;
        foreach ($vector as $val) {
            $sumSquares += $val * $val;
        }
        $l2Norm = sqrt($sumSquares);

        $this->assertEqualsWithDelta(1.0, $l2Norm, 0.0001, 'Vector must be L2-normalized with norm ~ 1.0');

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->hasHeader('x-goog-api-key', 'test-embedding-api-key')
                && $request->header('Content-Type')[0] === 'application/json'
                && ($data['model'] ?? null) === 'models/gemini-embedding-001'
                && ($data['outputDimensionality'] ?? null) === 768
                && ($data['content']['parts'][0]['text'] ?? null) === 'Truyện tranh trinh thám học đường';
        });
    }

    public function test_embed_supports_custom_dimensions(): void
    {
        $dummyValues = $this->makeDummyVector(512, 1.5);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent*' => Http::response([
                'embedding' => [
                    'values' => $dummyValues,
                ],
            ], 200),
        ]);

        $service = new GeminiEmbeddingService();
        $vector = $service->embed('Kiếm hiệp cổ trang', 512);

        $this->assertNotNull($vector);
        $this->assertCount(512, $vector);

        $sumSquares = array_reduce($vector, fn ($carry, $v) => $carry + ($v * $v), 0.0);
        $this->assertEqualsWithDelta(1.0, sqrt($sumSquares), 0.0001);

        Http::assertSent(function ($request) {
            return ($request->data()['outputDimensionality'] ?? null) === 512;
        });
    }

    public function test_embed_returns_null_when_api_key_is_missing(): void
    {
        Config::set('ai.gemini.api_key', null);

        $service = new GeminiEmbeddingService();
        $this->assertFalse($service->isConfigured());

        $result = $service->embed('Bất kỳ văn bản nào');
        $this->assertNull($result);

        Http::assertNothingSent();
    }

    public function test_embed_returns_null_for_empty_or_whitespace_text(): void
    {
        $service = new GeminiEmbeddingService();

        $this->assertNull($service->embed(''));
        $this->assertNull($service->embed('   '));
        $this->assertNull($service->embed("\n\t  "));

        Http::assertNothingSent();
    }

    public function test_embed_throws_transient_exception_on_http_429_rate_limit(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent*' => Http::response([
                'error' => [
                    'code' => 429,
                    'message' => 'Resource has been exhausted (e.g. check quota).',
                    'status' => 'RESOURCE_EXHAUSTED',
                ],
            ], 429),
        ]);

        $this->expectException(TransientEmbeddingException::class);
        $this->expectExceptionMessage('HTTP 429');

        $service = new GeminiEmbeddingService();
        $service->embed('Test rate limit handling');
    }

    public function test_embed_throws_transient_exception_on_http_500_server_error(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent*' => Http::response([
                'error' => [
                    'code' => 500,
                    'message' => 'Internal server error',
                ],
            ], 500),
        ]);

        $this->expectException(TransientEmbeddingException::class);
        $this->expectExceptionMessage('HTTP 500');

        $service = new GeminiEmbeddingService();
        $service->embed('Test 500 error');
    }

    public function test_embed_throws_transient_exception_on_connection_timeout(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 8000 milliseconds with 0 bytes received');
            },
        ]);

        $this->expectException(TransientEmbeddingException::class);

        $service = new GeminiEmbeddingService();
        $service->embed('Test timeout handling');
    }

    public function test_embed_throws_non_retryable_exception_on_non_numeric_elements(): void
    {
        $invalidValues = $this->makeDummyVector(768, 0.5);
        $invalidValues[10] = 'not-a-number';

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent*' => Http::response([
                'embedding' => [
                    'values' => $invalidValues,
                ],
            ], 200),
        ]);

        $this->expectException(NonRetryableEmbeddingException::class);

        $service = new GeminiEmbeddingService();
        $service->embed('Test invalid numeric');
    }

    public function test_embed_throws_non_retryable_exception_on_nan_or_infinite_values(): void
    {
        $service = new GeminiEmbeddingService();

        $valuesWithNan = $this->makeDummyVector(768, 0.5);
        $valuesWithNan[5] = NAN;

        $this->expectException(NonRetryableEmbeddingException::class);
        $service->validateAndNormalizeVector($valuesWithNan, 768);
    }

    public function test_embed_throws_non_retryable_exception_on_wrong_dimensions(): void
    {
        $wrongDimValues = $this->makeDummyVector(500, 0.5); // 500 instead of 768

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent*' => Http::response([
                'embedding' => [
                    'values' => $wrongDimValues,
                ],
            ], 200),
        ]);

        $this->expectException(NonRetryableEmbeddingException::class);

        $service = new GeminiEmbeddingService();
        $service->embed('Test dimension mismatch');
    }

    public function test_embed_throws_non_retryable_exception_on_zero_norm_vector(): void
    {
        $allZeros = $this->makeDummyVector(768, 0.0);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent*' => Http::response([
                'embedding' => [
                    'values' => $allZeros,
                ],
            ], 200),
        ]);

        $this->expectException(NonRetryableEmbeddingException::class);

        $service = new GeminiEmbeddingService();
        $service->embed('Test zero norm');
    }

    public function test_embed_throws_non_retryable_exception_on_empty_vector_response(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent*' => Http::response([
                'embedding' => [
                    'values' => [],
                ],
            ], 200),
        ]);

        $this->expectException(NonRetryableEmbeddingException::class);

        $service = new GeminiEmbeddingService();
        $service->embed('Test empty response');
    }
}
