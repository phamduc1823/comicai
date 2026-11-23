<?php

namespace Tests\Feature;

use App\Models\Comic;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use App\Services\AiSearchService;
use App\Services\Contracts\AiSearchServiceInterface;
use App\Services\Contracts\EmbeddingServiceInterface;
use App\Services\FallbackSearchService;
use App\Services\GeminiEmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HybridSemanticSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private User $admin;
    private int $dimensions = 768;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = User::create([
            'name' => 'Tác Giả Kiểm Thử',
            'email' => 'creator@example.com',
            'password' => 'secret123',
            'role' => 'creator',
        ]);

        $this->admin = User::create([
            'name' => 'Admin Phê Duyệt',
            'email' => 'admin@example.com',
            'password' => 'secret123',
            'role' => 'admin',
        ]);

        Config::set('ai.gemini.api_key', 'test-gemini-key');
        Config::set('ai.gemini.embedding_dimensions', $this->dimensions);
        Config::set('ai.hybrid.alpha', 0.55);
        Config::set('ai.hybrid.threshold', 0.35);
    }

    /**
     * Helper to create a normalized 768-dim mock vector with a spike at a specific index.
     */
    private function createUnitVector(int $activeDim = 0): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);
        $vector[$activeDim % $this->dimensions] = 1.0;
        return $vector;
    }

    private function createApprovedComic(array $attributes = []): Comic
    {
        $comic = Comic::forceCreate(array_merge([
            'title' => 'Kiếm Tiên Trở Lại',
            'slug' => 'kiem-tien-tro-lai-' . uniqid(),
            'author_name' => 'Lý Bạch',
            'user_id' => $this->creator->id,
            'description' => 'Một câu chuyện về kiếm tiên tái xuất giang hồ.',
            'cover_path' => 'placeholders/comics/demo.webp',
            'banner_path' => 'placeholders/comics/banner.webp',
            'age_rating' => '13+',
            'status' => 'ongoing',
            'approval_status' => 'approved',
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
            'views_count' => 100,
        ], $attributes));

        return $comic;
    }

    private function attachEmbedding(Comic $comic, array $vector): void
    {
        $comic->loadMissing(['genres', 'tags']);
        $corpus = AiSearchService::buildComicCorpus($comic);
        $comic->embedding = $vector;
        $comic->embedding_hash = hash('sha256', $corpus);
        $comic->save();
    }

    // =========================================================================
    // 1. COSINE SIMILARITY UNIT CHECKS
    // =========================================================================

    public function test_cosine_similarity_identical_vectors_returns_one(): void
    {
        $vecA = [0.6, 0.8, 0.0];
        $vecB = [0.6, 0.8, 0.0];

        $sim = AiSearchService::cosineSimilarity($vecA, $vecB);
        $this->assertEqualsWithDelta(1.0, $sim, 0.0001);
    }

    public function test_cosine_similarity_orthogonal_vectors_returns_zero(): void
    {
        $vecA = [1.0, 0.0, 0.0];
        $vecB = [0.0, 1.0, 0.0];

        $sim = AiSearchService::cosineSimilarity($vecA, $vecB);
        $this->assertEqualsWithDelta(0.0, $sim, 0.0001);
    }

    public function test_cosine_similarity_opposite_vectors_returns_negative_one(): void
    {
        $vecA = [1.0, 0.0, 0.0];
        $vecB = [-1.0, 0.0, 0.0];

        $sim = AiSearchService::cosineSimilarity($vecA, $vecB);
        $this->assertEqualsWithDelta(-1.0, $sim, 0.0001);
    }

    public function test_cosine_similarity_dimension_mismatch_returns_zero(): void
    {
        $vecA = [1.0, 0.0];
        $vecB = [1.0, 0.0, 0.0];

        $sim = AiSearchService::cosineSimilarity($vecA, $vecB);
        $this->assertSame(0.0, $sim);
    }

    public function test_cosine_similarity_empty_vectors_returns_zero(): void
    {
        $this->assertSame(0.0, AiSearchService::cosineSimilarity([], []));
        $this->assertSame(0.0, AiSearchService::cosineSimilarity([1.0], []));
    }

    public function test_cosine_similarity_zero_norm_vector_returns_zero_without_division_by_zero(): void
    {
        $vecA = [0.0, 0.0, 0.0];
        $vecB = [1.0, 2.0, 3.0];

        $sim = AiSearchService::cosineSimilarity($vecA, $vecB);
        $this->assertSame(0.0, $sim);
    }

    public function test_cosine_similarity_handles_nan_infinity_and_non_numeric_safely(): void
    {
        $vecValid = [1.0, 2.0, 3.0];

        $this->assertSame(0.0, AiSearchService::cosineSimilarity([NAN, 2.0, 3.0], $vecValid));
        $this->assertSame(0.0, AiSearchService::cosineSimilarity([INF, 2.0, 3.0], $vecValid));
        $this->assertSame(0.0, AiSearchService::cosineSimilarity([-INF, 2.0, 3.0], $vecValid));
        $this->assertSame(0.0, AiSearchService::cosineSimilarity(['invalid_string', 2.0, 3.0], $vecValid));
    }

    // =========================================================================
    // 2. STALE HASH & CORPUS INTEGRITY
    // =========================================================================

    public function test_stale_embedding_hash_is_detected_and_skipped(): void
    {
        $comic = $this->createApprovedComic(['title' => 'Truyện Thần Thoại']);
        $this->attachEmbedding($comic, $this->createUnitVector(0));

        // Corrupt the hash or change the comic title without updating hash
        $comic->title = 'Truyện Thần Thoại Đã Sửa Đổi';
        $comic->save();

        $this->assertFalse(AiSearchService::isEmbeddingFresh($comic));
    }

    public function test_fresh_embedding_hash_is_verified(): void
    {
        $comic = $this->createApprovedComic(['title' => 'Truyện Nguyên Bản']);
        $this->attachEmbedding($comic, $this->createUnitVector(0));

        $this->assertTrue(AiSearchService::isEmbeddingFresh($comic));
    }

    // =========================================================================
    // 3. HYBRID RANKING TESTS
    // =========================================================================

    public function test_hybrid_ranking_combines_high_semantic_and_high_keyword_scores(): void
    {
        Config::set('ai.enabled', true);

        // Comic 1: Exact keyword match ('Võ Đạo Độc Tôn') AND High semantic match
        $comicBothHigh = $this->createApprovedComic(['title' => 'Võ Đạo Độc Tôn']);
        $this->attachEmbedding($comicBothHigh, $this->createUnitVector(10));

        // Comic 2: Keyword match only ('Võ Đạo Thường') with orthogonal semantic vector
        $comicKeywordOnly = $this->createApprovedComic(['title' => 'Võ Đạo Thường']);
        $this->attachEmbedding($comicKeywordOnly, $this->createUnitVector(50));

        // Comic 3: Semantic match only ('Độc Tôn Thần Giới' does not match query, but has high semantic similarity)
        $comicSemanticOnly = $this->createApprovedComic(['title' => 'Thiên Địa Huyền Môn']);
        $this->attachEmbedding($comicSemanticOnly, $this->createUnitVector(10));

        // Query embedding is aligned with index 10
        $queryVector = $this->createUnitVector(10);

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($queryVector);

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        $results = $aiService->searchHybrid('Võ Đạo Độc Tôn', [], 10);

        $this->assertNotEmpty($results);

        // Comic 1 has both high keyword and high semantic -> ranks #1
        $this->assertSame($comicBothHigh->id, $results[0]['id']);
        $this->assertGreaterThan(0.6, $results[0]['score']);
        $this->assertStringContainsString('Khớp ngữ nghĩa AI', $results[0]['match_reason']);
        $this->assertStringContainsString('Khớp chính xác tiêu đề', $results[0]['match_reason']);
    }

    public function test_comic_with_keyword_match_but_no_embedding_is_preserved(): void
    {
        Config::set('ai.enabled', true);

        // Comic without embedding (null embedding)
        $comicNoEmbedding = $this->createApprovedComic([
            'title' => 'Tuyệt Thế Đan Thần',
            'description' => 'Luyện đan tu tiên vô địch.',
            'embedding' => null,
            'embedding_hash' => null,
        ]);

        $queryVector = $this->createUnitVector(5);
        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($queryVector);

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        $results = $aiService->searchHybrid('Tuyệt Thế Đan Thần', [], 10);

        $this->assertNotEmpty($results);
        $found = collect($results)->firstWhere('id', $comicNoEmbedding->id);

        $this->assertNotNull($found, 'Comic without embedding must not be dropped if keyword matches.');
        $this->assertGreaterThan(0.0, $found['score']);
        $this->assertStringContainsString('Chưa có embedding ngữ nghĩa', $found['match_reason']);
    }

    public function test_semantic_match_below_threshold_is_discarded_when_keyword_score_is_zero(): void
    {
        Config::set('ai.enabled', true);
        Config::set('ai.hybrid.threshold', 0.40);

        // Comic has completely different title (no keyword match), and low semantic similarity (0.25)
        $comicLowSemantic = $this->createApprovedComic(['title' => 'Nấu Ăn Cùng Mèo']);

        // Vector with 0.25 similarity
        $vectorLow = array_fill(0, $this->dimensions, 0.0);
        $vectorLow[0] = 0.25;
        $vectorLow[1] = sqrt(1.0 - 0.25 * 0.25);
        $this->attachEmbedding($comicLowSemantic, $vectorLow);

        $queryVector = $this->createUnitVector(0); // similarity with vectorLow will be 0.25
        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($queryVector);

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        // Search for something that doesn't match 'Nấu Ăn Cùng Mèo'
        $results = $aiService->searchHybrid('Chiến Tranh Không Gian', [], 10);

        $found = collect($results)->firstWhere('id', $comicLowSemantic->id);
        $this->assertNull($found, 'Semantic-only match below threshold (0.25 < 0.40) must be discarded.');
    }

    public function test_alpha_and_threshold_are_loaded_dynamically_from_config(): void
    {
        Config::set('ai.enabled', true);
        Config::set('ai.hybrid.alpha', 0.80); // 80% semantic, 20% keyword
        Config::set('ai.hybrid.threshold', 0.20);

        $comic = $this->createApprovedComic(['title' => 'Truyện Test Config']);
        $this->attachEmbedding($comic, $this->createUnitVector(0));

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($this->createUnitVector(0));

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        $results = $aiService->searchHybrid('Truyện Test Config', [], 10);

        $this->assertNotEmpty($results);
        $found = $results[0];

        // Semantic score = 1.0 (vector 0 matches vector 0)
        // Keyword score = 1.0 (exact title match)
        // Hybrid score with alpha 0.8: 0.8 * 1.0 + 0.2 * 1.0 = 1.0
        $this->assertEqualsWithDelta(1.0, $found['score'], 0.01);
    }

    // =========================================================================
    // 4. ELIGIBILITY CHECKS
    // =========================================================================

    public function test_strictly_excludes_draft_pending_and_rejected_comics_from_semantic_search(): void
    {
        Config::set('ai.enabled', true);

        $vector = $this->createUnitVector(0);

        $approved = $this->createApprovedComic(['title' => 'Kiếm Tiên Đã Duyệt', 'approval_status' => 'approved']);
        $this->attachEmbedding($approved, $vector);

        $draft = $this->createApprovedComic(['title' => 'Kiếm Tiên Nháp', 'approval_status' => 'draft']);
        $this->attachEmbedding($draft, $vector);

        $pending = $this->createApprovedComic(['title' => 'Kiếm Tiên Chờ Duyệt', 'approval_status' => 'pending']);
        $this->attachEmbedding($pending, $vector);

        $rejected = $this->createApprovedComic(['title' => 'Kiếm Tiên Bị Loại', 'approval_status' => 'rejected']);
        $this->attachEmbedding($rejected, $vector);

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($vector);

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        $results = $aiService->searchHybrid('Kiếm Tiên', [], 10);

        $resultIds = collect($results)->pluck('id')->all();

        $this->assertContains($approved->id, $resultIds);
        $this->assertNotContains($draft->id, $resultIds);
        $this->assertNotContains($pending->id, $resultIds);
        $this->assertNotContains($rejected->id, $resultIds);
    }

    // =========================================================================
    // 5. FALLBACK & ERROR BOUNDARY CHECKS
    // =========================================================================

    public function test_query_embedding_null_falls_back_to_keyword_search(): void
    {
        Config::set('ai.enabled', true);

        $comic = $this->createApprovedComic(['title' => 'Truyện Keyword Cứu Hộ']);

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn(null); // API returns null

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        $results = $aiService->search('Keyword Cứu Hộ', [], 10);

        $this->assertNotEmpty($results);
        $this->assertSame($comic->id, $results[0]['id']);
        $this->assertTrue($results[0]['is_fallback']);
        $this->assertStringContainsString('tự động chuyển sang tìm kiếm từ khóa an toàn', $results[0]['fallback_notice']);
    }

    public function test_query_embedding_exception_falls_back_without_throwing_500(): void
    {
        Config::set('ai.enabled', true);

        $comic = $this->createApprovedComic(['title' => 'Truyện Chống Exception']);

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willThrowException(new \RuntimeException('Connection timed out to Google'));

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        $results = $aiService->search('Chống Exception', [], 10);

        $this->assertNotEmpty($results);
        $this->assertSame($comic->id, $results[0]['id']);
        $this->assertTrue($results[0]['is_fallback']);
    }

    public function test_search_when_no_comics_have_embeddings_still_returns_keyword_results(): void
    {
        Config::set('ai.enabled', true);

        // Comics in DB have NO embeddings
        $comic = $this->createApprovedComic([
            'title' => 'Thư Viện Không Vector',
            'embedding' => null,
            'embedding_hash' => null,
        ]);

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($this->createUnitVector(0));

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        $results = $aiService->search('Không Vector', [], 10);

        $this->assertNotEmpty($results);
        $this->assertSame($comic->id, $results[0]['id']);
        $this->assertGreaterThan(0.0, $results[0]['score']);
    }

    // =========================================================================
    // 6. END-TO-END CONTROLLER INTEGRATION & API COMPATIBILITY
    // =========================================================================

    public function test_controller_ai_mode_returns_hybrid_retrieval_with_meta(): void
    {
        Config::set('ai.enabled', true);

        $comic = $this->createApprovedComic([
            'title' => 'Pháp Sư Huyền Thoại',
            'description' => 'Một chàng trai khám phá phép thuật cổ đại.',
        ]);
        $this->attachEmbedding($comic, $this->createUnitVector(0));

        Http::fake([
            // Gemini Query Understanding
            'https://generativelanguage.googleapis.com/*generateContent*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'series' => 'Pháp Sư Huyền Thoại',
                                        'characters' => [],
                                        'remembered_events' => ['phép thuật cổ đại'],
                                        'opponent_description' => null,
                                        'visual_clues' => [],
                                        'time_reference' => null,
                                        'keywords' => ['pháp sư', 'phép thuật cổ đại'],
                                        'search_intent' => 'comic_or_chapter',
                                        'uncertainty_notes' => [],
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            // Gemini Query Embedding
            'https://generativelanguage.googleapis.com/*embedContent*' => Http::response([
                'embedding' => [
                    'values' => $this->createUnitVector(0),
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/ai-search?q=Pháp+Sư+Huyền+Thoại&mode=ai');

        $response->assertOk();
        $response->assertJsonPath('meta.mode', 'ai');
        $this->assertTrue($response->json('meta.ai_used'));
        $this->assertFalse($response->json('meta.is_fallback'));

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'slug',
                    'cover_path',
                    'author_name',
                    'age_rating',
                    'status',
                    'score',
                    'is_fallback',
                    'match_reason',
                    'matched_tags',
                    'matched_genres',
                    'ai_summary',
                ],
            ],
            'meta' => [
                'total',
                'query',
                'limit',
                'mode',
                'is_fallback',
                'ai_used',
                'fallback_notice',
            ],
        ]);

        $this->assertSame($comic->id, $response->json('data.0.id'));
        $this->assertGreaterThan(0.5, $response->json('data.0.score'));
    }

    public function test_controller_normal_mode_does_not_invoke_embedding_or_ai_apis(): void
    {
        Http::preventStrayRequests();

        $comic = $this->createApprovedComic(['title' => 'Chế Độ Thuần Túy Keyword']);

        $response = $this->getJson('/api/ai-search?q=Thuần+Túy&mode=normal');

        $response->assertOk();
        $response->assertJsonPath('meta.mode', 'normal');
        $this->assertFalse($response->json('meta.ai_used'));
        $this->assertTrue($response->json('meta.is_fallback'));
        $this->assertSame($comic->id, $response->json('data.0.id'));

        Http::assertNothingSent();
    }

    // =========================================================================
    // 7. SUPPLEMENTAL VERIFICATION: WEIGHTS, FILTERS, PAGINATION & CALL COUNT
    // =========================================================================

    public function test_alpha_zero_operates_as_pure_keyword_search_excluding_semantic_only_results(): void
    {
        Config::set('ai.enabled', true);
        Config::set('ai.hybrid.alpha', 0.0); // Pure keyword weight

        // Comic A matches keyword
        $comicKeyword = $this->createApprovedComic(['title' => 'Đấu Phá Khung Thương']);
        // Comic B has semantic match only, does not match keyword in title/desc
        $comicSemantic = $this->createApprovedComic(['title' => 'Huyền Thoại Kiếm Khách']);
        $this->attachEmbedding($comicSemantic, $this->createUnitVector(0));

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($this->createUnitVector(0));

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        $results = $aiService->search('Đấu Phá Khung Thương', [], 10);

        $this->assertNotEmpty($results);
        $resultIds = array_column($results, 'id');
        // Comic A MUST be included with score equal to its keyword score
        $this->assertContains($comicKeyword->id, $resultIds);
        // Comic B (pure semantic match) MUST be excluded when alpha = 0
        $this->assertNotContains($comicSemantic->id, $resultIds);

        // Find Comic A in results and verify no AI semantic reason is added
        $itemA = array_values(array_filter($results, fn ($r) => $r['id'] === $comicKeyword->id))[0];
        $this->assertStringNotContainsString('Khớp ngữ nghĩa AI', $itemA['match_reason']);
    }

    public function test_alpha_one_operates_as_pure_semantic_search_excluding_unembedded_keyword_results(): void
    {
        Config::set('ai.enabled', true);
        Config::set('ai.hybrid.alpha', 1.0); // Pure semantic weight
        Config::set('ai.hybrid.threshold', 0.35);

        // Comic A matches keyword, but has NO embedding
        $comicKeywordOnly = $this->createApprovedComic([
            'title' => 'Tây Du Ký Ngoại Truyện',
            'embedding' => null,
            'embedding_hash' => null,
        ]);

        // Comic B has strong semantic match (cosine sim = 1.0)
        $comicSemantic = $this->createApprovedComic(['title' => 'Truyền Thuyết Hoa Quả Sơn']);
        $this->attachEmbedding($comicSemantic, $this->createUnitVector(0));

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($this->createUnitVector(0));

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        $results = $aiService->search('Tây Du Ký Ngoại Truyện', [], 10);

        $this->assertNotEmpty($results);
        $resultIds = array_column($results, 'id');
        // Comic B (semantic match) MUST be included
        $this->assertContains($comicSemantic->id, $resultIds);
        // Comic A (unembedded keyword match) MUST be excluded when alpha = 1.0
        $this->assertNotContains($comicKeywordOnly->id, $resultIds);

        // Verify score of Comic B equals its semantic similarity (1.0)
        $itemB = array_values(array_filter($results, fn ($r) => $r['id'] === $comicSemantic->id))[0];
        $this->assertEqualsWithDelta(1.0, $itemB['score'], 0.01);
        $this->assertStringContainsString('Khớp ngữ nghĩa AI', $itemB['match_reason']);
    }

    public function test_filters_age_rating_and_status_strictly_applied_to_semantic_candidates(): void
    {
        Config::set('ai.enabled', true);

        // Comic A: 18+, completed
        $comicA = $this->createApprovedComic([
            'title' => 'Truyện Người Lớn Đã Hoàn Thành',
            'age_rating' => '18+',
            'status' => 'completed',
        ]);
        $this->attachEmbedding($comicA, $this->createUnitVector(0));

        // Comic B: 13+, ongoing (same high semantic match)
        $comicB = $this->createApprovedComic([
            'title' => 'Truyện Thiếu Niên Đang Ra',
            'age_rating' => '13+',
            'status' => 'ongoing',
        ]);
        $this->attachEmbedding($comicB, $this->createUnitVector(0));

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($this->createUnitVector(0));

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        // Filter age_rating = 18+
        $resAge = $aiService->search('Truyện', ['age_rating' => '18+'], 10);
        $idsAge = array_column($resAge, 'id');
        $this->assertContains($comicA->id, $idsAge);
        $this->assertNotContains($comicB->id, $idsAge);

        // Filter status = ongoing
        $resStatus = $aiService->search('Truyện', ['status' => 'ongoing'], 10);
        $idsStatus = array_column($resStatus, 'id');
        $this->assertContains($comicB->id, $idsStatus);
        $this->assertNotContains($comicA->id, $idsStatus);
    }

    public function test_pagination_with_page_and_limit_slices_correctly_after_hybrid_ranking(): void
    {
        Config::set('ai.enabled', true);

        // Create 3 comics with different scores
        $comic1 = $this->createApprovedComic(['title' => 'Truyện Xếp Hạng Nhất', 'views_count' => 300]);
        $this->attachEmbedding($comic1, $this->createUnitVector(0)); // Sim = 1.0

        $comic2 = $this->createApprovedComic(['title' => 'Truyện Xếp Hạng Nhì', 'views_count' => 200]);
        $this->attachEmbedding($comic2, $this->createUnitVector(1)); // Orthogonal, sim = 0.0

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($this->createUnitVector(0));

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        // Page 1 with limit 1: should return Comic 1
        $page1 = $aiService->search('Xếp Hạng', ['page' => 1], 1);
        $this->assertCount(1, $page1);
        $this->assertSame($comic1->id, $page1[0]['id']);

        // Page 2 with limit 1: should return Comic 2
        $page2 = $aiService->search('Xếp Hạng', ['page' => 2], 1);
        $this->assertCount(1, $page2);
        $this->assertSame($comic2->id, $page2[0]['id']);
    }

    public function test_query_embedding_is_called_at_most_once_per_ai_search_request(): void
    {
        Config::set('ai.enabled', true);

        $comic = $this->createApprovedComic(['title' => 'Truyện Test Số Lần Gọi Embed']);
        $this->attachEmbedding($comic, $this->createUnitVector(0));

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        // Expect embed() to be called EXACTLY ONCE
        $mockEmbeddingService->expects($this->once())
            ->method('embed')
            ->willReturn($this->createUnitVector(0));

        $this->app->instance(EmbeddingServiceInterface::class, $mockEmbeddingService);

        Http::fake([
            'https://generativelanguage.googleapis.com/*generateContent*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'series' => 'Truyện Test Số Lần Gọi Embed',
                                        'keywords' => ['test', 'embed'],
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/ai-search?q=Truyện+Test+Số+Lần+Gọi+Embed&mode=ai');

        $response->assertOk();
        $this->assertTrue($response->json('meta.ai_used'));
    }

    public function test_catalog_larger_than_candidate_limit_correctly_retrieves_high_semantic_comic_outside_keyword_candidates(): void
    {
        Config::set('ai.enabled', true);
        Config::set('ai.hybrid.candidate_limit', 30); // Set lower floor to simulate large catalog easily

        // Create 35 approved comics matching keyword "Tu Tiên" in title, with NO embeddings
        // These 35 comics will completely fill and exceed the 30 candidate limit
        for ($i = 1; $i <= 35; $i++) {
            $this->createApprovedComic([
                'title' => "Truyện Tu Tiên Tập {$i}",
                'views_count' => $i * 10,
                'embedding' => null,
                'embedding_hash' => null,
            ]);
        }

        // Create Comic S: title does NOT match "Tu Tiên", but has strong semantic embedding (cosine sim = 1.0)
        // Comic S is outside the keyword candidate pool!
        $comicSemanticOnly = $this->createApprovedComic([
            'title' => 'Võ Đạo Độc Tôn Kỳ Hiệp',
            'views_count' => 500,
        ]);
        $this->attachEmbedding($comicSemanticOnly, $this->createUnitVector(0));

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($this->createUnitVector(0));

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);

        // Search for "Tu Tiên"
        $results = $aiService->search('Tu Tiên', [], 10);

        $this->assertNotEmpty($results);
        $this->assertCount(10, $results);

        // Comic S MUST be retrieved and ranked at #1 because:
        // 1. Semantic query fetches all comics with embeddings without limit.
        // 2. Comic S has semantic score 1.0, giving hybrid score 0.55 * 1.0 = 0.55.
        // 3. Keyword-only comics without embeddings have hybrid score (1 - 0.55) * kw_score <= 0.45.
        // 4. Therefore 0.55 > 0.45 ranks Comic S at Rank 1!
        $this->assertSame($comicSemanticOnly->id, $results[0]['id']);
        $this->assertEqualsWithDelta(0.55, $results[0]['score'], 0.01);
        $this->assertStringContainsString('Khớp ngữ nghĩa AI', $results[0]['match_reason']);
    }

    public function test_meta_total_reflects_current_page_count_in_api_contract(): void
    {
        Config::set('ai.enabled', true);

        for ($i = 1; $i <= 5; $i++) {
            $this->createApprovedComic(['title' => "Truyện Kiếm Đạo {$i}"]);
        }

        // Request with limit 3
        $response = $this->getJson('/api/ai-search?q=Kiếm+Đạo&limit=3');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
        // In Package 10.3A contract, meta.total returns count of items in returned page
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_hybrid_search_assigns_structured_match_types_accurately(): void
    {
        Config::set('ai.enabled', true);
        Config::set('ai.hybrid.alpha', 0.55);

        // 1. Comic with both keyword match and semantic match -> 'hybrid'
        $comicHybrid = $this->createApprovedComic(['title' => 'Đấu Phá Khung Thương']);
        $this->attachEmbedding($comicHybrid, $this->createUnitVector(0));

        // 2. Comic with semantic match only (title differs completely) -> 'semantic'
        $comicSemantic = $this->createApprovedComic([
            'title' => 'Vô Địch Thiên Hạ',
            'description' => 'Tu tiên bí cảnh',
        ]);
        $this->attachEmbedding($comicSemantic, $this->createUnitVector(0));

        // 3. Comic with keyword match but NO embedding -> 'clue'
        $comicClue = $this->createApprovedComic(['title' => 'Đấu Phá Ngoại Truyện']);

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($this->createUnitVector(0));

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);
        $results = $aiService->searchHybrid('Đấu Phá');

        $byTitle = [];
        foreach ($results as $item) {
            $byTitle[$item['title']] = $item;
        }

        $this->assertArrayHasKey('Đấu Phá Khung Thương', $byTitle);
        $this->assertSame('hybrid', $byTitle['Đấu Phá Khung Thương']['match_type']);

        $this->assertArrayHasKey('Vô Địch Thiên Hạ', $byTitle);
        $this->assertSame('semantic', $byTitle['Vô Địch Thiên Hạ']['match_type']);

        $this->assertArrayHasKey('Đấu Phá Ngoại Truyện', $byTitle);
        $this->assertSame('clue', $byTitle['Đấu Phá Ngoại Truyện']['match_type']);
    }

    public function test_alpha_zero_edge_case_semantic_exists_keyword_zero_is_strictly_excluded_and_hybrid_typed_clue(): void
    {
        Config::set('ai.enabled', true);
        Config::set('ai.hybrid.alpha', 0.0); // alpha = 0: pure keyword weighting

        // 1. Comic with keyword match and NO embedding -> included as 'clue'
        $comicKeywordOnly = $this->createApprovedComic(['title' => 'Tuyệt Thế Vũ Thần']);

        // 2. Comic with keyword match AND embedding attached -> included as 'clue' (semantic has 0 weight)
        $comicKeywordWithEmbedding = $this->createApprovedComic(['title' => 'Tuyệt Thế Kiếm Thần']);
        $this->attachEmbedding($comicKeywordWithEmbedding, $this->createUnitVector(0));

        // 3. Comic with strong semantic match (cosine sim = 1.0) but ZERO keyword match
        $comicSemanticOnly = $this->createApprovedComic([
            'title' => 'Đại Lục Huyền Ảo',
            'description' => 'Tu chân giới dị giới phiêu lưu ký',
        ]);
        $this->attachEmbedding($comicSemanticOnly, $this->createUnitVector(0));

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($this->createUnitVector(0));

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);
        $results = $aiService->searchHybrid('Tuyệt Thế');

        $resultIds = array_column($results, 'id');
        $byTitle = [];
        foreach ($results as $item) {
            $byTitle[$item['title']] = $item;
        }

        // Edge case: semanticScore exists but keywordScore = 0 when alpha = 0 MUST be strictly excluded
        $this->assertNotContains($comicSemanticOnly->id, $resultIds, 'Semantic-only comic must be excluded when alpha = 0');
        $this->assertArrayNotHasKey('Đại Lục Huyền Ảo', $byTitle);

        // Comic with keyword and embedding must be included with match_type = 'clue'
        $this->assertArrayHasKey('Tuyệt Thế Kiếm Thần', $byTitle);
        $this->assertSame('clue', $byTitle['Tuyệt Thế Kiếm Thần']['match_type']);
        $this->assertGreaterThan(0.0, $byTitle['Tuyệt Thế Kiếm Thần']['score']);
        $this->assertStringNotContainsString('Khớp ngữ nghĩa AI', $byTitle['Tuyệt Thế Kiếm Thần']['match_reason']);

        // Comic with keyword only must be included with match_type = 'clue'
        $this->assertArrayHasKey('Tuyệt Thế Vũ Thần', $byTitle);
        $this->assertSame('clue', $byTitle['Tuyệt Thế Vũ Thần']['match_type']);
        $this->assertGreaterThan(0.0, $byTitle['Tuyệt Thế Vũ Thần']['score']);
    }

    public function test_hybrid_search_when_semantic_below_threshold_preserves_comic_as_clue_without_semantic_contribution(): void
    {
        Config::set('ai.enabled', true);
        Config::set('ai.hybrid.alpha', 0.55);
        Config::set('ai.hybrid.threshold', 0.40);

        // Comic matches keyword (title: 'Phá Vỡ Hư Không', exact title match keywordScore = 1.0)
        // but has low semantic similarity (0.20 < threshold 0.40)
        $comicLowSemantic = $this->createApprovedComic(['title' => 'Phá Vỡ Hư Không']);

        // Vector with cosine similarity = 0.20 with unit vector 0
        $vectorLow = array_fill(0, $this->dimensions, 0.0);
        $vectorLow[0] = 0.20;
        $vectorLow[1] = sqrt(1.0 - 0.20 * 0.20);
        $this->attachEmbedding($comicLowSemantic, $vectorLow);

        $mockEmbeddingService = $this->createMock(EmbeddingServiceInterface::class);
        $mockEmbeddingService->method('isConfigured')->willReturn(true);
        $mockEmbeddingService->method('embed')->willReturn($this->createUnitVector(0));

        $aiService = new AiSearchService(app(FallbackSearchService::class), $mockEmbeddingService);
        $results = $aiService->searchHybrid('Phá Vỡ Hư Không', [], 10);

        $this->assertNotEmpty($results);
        $found = collect($results)->firstWhere('id', $comicLowSemantic->id);

        $this->assertNotNull($found, 'Comic matching keyword must be preserved even if semantic score is below threshold');
        // Semantic score below threshold (0.20 < 0.40) must NOT contribute to score
        // Score should only be (1 - alpha) * keywordScore = (1 - 0.55) * 1.0 = 0.45
        $this->assertEqualsWithDelta(0.45, $found['score'], 0.01, 'Score must only reflect keyword contribution without semantic noise');
        // Must be typed as 'clue' since semantic did not contribute
        $this->assertSame('clue', $found['match_type']);
        // Must NOT contain semantic match reason
        $this->assertStringNotContainsString('Khớp ngữ nghĩa AI', $found['match_reason']);
        $this->assertStringContainsString('Khớp chính xác tiêu đề', $found['match_reason']);
    }
}
