<?php

namespace Tests\Feature;

use App\Models\Comic;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSearchApiTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = User::create([
            'name' => 'Họa Sĩ Minh Trí',
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
    }

    private function createComic(array $attributes = []): Comic
    {
        $comic = Comic::create(array_merge([
            'title' => 'Võ Đạo Độc Tôn',
            'slug' => 'vo-dao-doc-ton-' . uniqid(),
            'author_name' => 'Trần Văn Tác',
            'user_id' => $this->creator->id,
            'description' => 'Một câu chuyện tu tiên kiếm hiệp ly kỳ và hấp dẫn.',
            'cover_path' => 'placeholders/comics/demo-cover.webp',
            'banner_path' => 'placeholders/comics/demo-banner.webp',
            'age_rating' => '13+',
            'status' => 'ongoing',
        ], $attributes));

        $comic->approval_status = $attributes['approval_status'] ?? 'approved';
        $comic->approved_by = $attributes['approved_by'] ?? $this->admin->id;
        $comic->approved_at = $attributes['approved_at'] ?? now();
        $comic->save();

        return $comic;
    }

    /**
     * 1. Valid query returns HTTP 200 with standard structure.
     */
    public function test_valid_query_returns_http_200_with_expected_structure(): void
    {
        $this->createComic(['title' => 'Đại Hiệp Khám Phá Bí Cảnh']);

        $response = $this->getJson('/api/ai-search?q=Đại+Hiệp');

        $response->assertOk();
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
                'is_fallback',
                'fallback_notice',
            ],
        ]);
        $response->assertJsonPath('meta.query', 'Đại Hiệp');
        $response->assertJsonPath('meta.total', 1);
        $this->assertTrue($response->json('meta.is_fallback'));
    }

    /**
     * 2. Missing query parameter returns HTTP 422.
     */
    public function test_missing_query_parameter_returns_http_422(): void
    {
        $response = $this->getJson('/api/ai-search');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['q']);
    }

    /**
     * 3. Query under 2 characters returns HTTP 422.
     */
    public function test_query_under_two_characters_returns_http_422(): void
    {
        $response = $this->getJson('/api/ai-search?q=a');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['q']);
    }

    /**
     * 4. Query exceeding 300 characters returns HTTP 422.
     */
    public function test_query_exceeding_300_characters_returns_http_422(): void
    {
        $longQuery = str_repeat('k', 301);

        $response = $this->getJson('/api/ai-search?q=' . $longQuery);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['q']);
    }

    /**
     * 5. Limit under 1 or exceeding 50 returns HTTP 422.
     */
    public function test_limit_validation_enforces_bounds(): void
    {
        // Limit < 1
        $resZero = $this->getJson('/api/ai-search?q=kiem+thuat&limit=0');
        $resZero->assertStatus(422);
        $resZero->assertJsonValidationErrors(['limit']);

        // Limit > 50
        $resOver = $this->getJson('/api/ai-search?q=kiem+thuat&limit=51');
        $resOver->assertStatus(422);
        $resOver->assertJsonValidationErrors(['limit']);

        // Non-integer limit
        $resInvalid = $this->getJson('/api/ai-search?q=kiem+thuat&limit=abc');
        $resInvalid->assertStatus(422);
        $resInvalid->assertJsonValidationErrors(['limit']);

        // Valid limit within bounds
        $resValid = $this->getJson('/api/ai-search?q=kiem+thuat&limit=25');
        $resValid->assertOk();
        $this->assertSame(25, $resValid->json('meta.limit'));
    }

    /**
     * 6. Filter genre operates accurately.
     */
    public function test_genre_filter_narrows_results(): void
    {
        $actionGenre = Genre::create(['name' => 'Hành Động', 'slug' => 'hanh-dong']);
        $romanceGenre = Genre::create(['name' => 'Tình Cảm', 'slug' => 'tinh-cam']);

        $actionComic = $this->createComic(['title' => 'Truyện Võ Thuật Hành Động']);
        $actionComic->genres()->attach($actionGenre->id);

        $romanceComic = $this->createComic(['title' => 'Truyện Võ Thuật Tình Cảm']);
        $romanceComic->genres()->attach($romanceGenre->id);

        $response = $this->getJson('/api/ai-search?q=Võ+Thuật&genre=hanh-dong');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame($actionComic->id, $response->json('data.0.id'));
    }

    /**
     * 7. Filter tag operates accurately.
     */
    public function test_tag_filter_narrows_results(): void
    {
        $swordTag = Tag::create(['name' => 'Kiếm Thuật', 'slug' => 'kiem-thuat']);
        $magicTag = Tag::create(['name' => 'Ma Pháp', 'slug' => 'ma-phap']);

        $swordComic = $this->createComic(['title' => 'Tu Tiên Kiếm Đạo']);
        $swordComic->tags()->attach($swordTag->id);

        $magicComic = $this->createComic(['title' => 'Tu Tiên Ma Pháp']);
        $magicComic->tags()->attach($magicTag->id);

        $response = $this->getJson('/api/ai-search?q=Tu+Tiên&tag=kiem-thuat');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame($swordComic->id, $response->json('data.0.id'));
    }

    /**
     * 8. Filter age_rating and status conform to system schema.
     */
    public function test_age_rating_and_status_validation_and_filtering(): void
    {
        // Invalid age_rating returns 422
        $resBadAge = $this->getJson('/api/ai-search?q=kiem+hiep&age_rating=25+');
        $resBadAge->assertStatus(422);
        $resBadAge->assertJsonValidationErrors(['age_rating']);

        // Invalid status returns 422
        $resBadStatus = $this->getJson('/api/ai-search?q=kiem+hiep&status=unknown');
        $resBadStatus->assertStatus(422);
        $resBadStatus->assertJsonValidationErrors(['status']);

        // Valid filters match schema
        $comic18 = $this->createComic(['title' => 'Kiếm Hiệp Trưởng Thành', 'age_rating' => '18+']);
        $comic13 = $this->createComic(['title' => 'Kiếm Hiệp Thiếu Niên', 'age_rating' => '13+']);

        $response = $this->getJson('/api/ai-search?q=Kiếm+Hiệp&age_rating=18%2B');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame($comic18->id, $response->json('data.0.id'));

        // Also handles literal plus in query string gracefully
        $resLiteralPlus = $this->getJson('/api/ai-search?q=Kiếm+Hiệp&age_rating=18+');
        $resLiteralPlus->assertOk();
        $this->assertSame($comic18->id, $resLiteralPlus->json('data.0.id'));
    }

    /**
     * 9. Strictly returns only approved comics.
     */
    public function test_strictly_excludes_unapproved_comics(): void
    {
        $this->createComic(['title' => 'Truyện Tu Tiên Đã Phê Duyệt', 'approval_status' => 'approved']);
        $this->createComic(['title' => 'Truyện Tu Tiên Bản Nháp', 'approval_status' => 'draft']);
        $this->createComic(['title' => 'Truyện Tu Tiên Đang Chờ Duyệt', 'approval_status' => 'pending']);
        $this->createComic(['title' => 'Truyện Tu Tiên Bị Từ Chối', 'approval_status' => 'rejected']);

        $response = $this->getJson('/api/ai-search?q=Tu+Tiên');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame('Truyện Tu Tiên Đã Phê Duyệt', $response->json('data.0.title'));
    }

    /**
     * 10. Response contains correct metadata and is_fallback flag.
     */
    public function test_response_has_authoritative_fallback_metadata(): void
    {
        $this->createComic(['title' => 'Truyện Kiểm Tra Metadata']);

        $response = $this->getJson('/api/ai-search?q=Kiểm+Tra+Metadata');

        $response->assertOk();
        $this->assertTrue($response->json('meta.is_fallback'));
        $this->assertTrue($response->json('data.0.is_fallback'));
        $this->assertNotEmpty($response->json('data.0.match_reason'));
    }

    /**
     * 11. Endpoint does not require authentication (public access).
     */
    public function test_endpoint_is_publicly_accessible_without_auth(): void
    {
        $this->createComic(['title' => 'Truyện Cho Độc Giả Vãng Lai']);

        // Explicitly assert user is unauthenticated guest
        $this->assertGuest();

        $response = $this->getJson('/api/ai-search?q=Vãng+Lai');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    /**
     * 12. No external API requests are made during test execution.
     */
    public function test_no_external_api_requests_are_dispatched(): void
    {
        Http::preventStrayRequests();

        $this->createComic(['title' => 'Bảo Vệ Kết Nối Mạng']);

        $response = $this->getJson('/api/ai-search?q=Bảo+Vệ');

        $response->assertOk();
    }

    /**
     * 13. Default mode is normal and does not invoke Gemini.
     */
    public function test_default_mode_is_normal_and_does_not_call_gemini(): void
    {
        Http::preventStrayRequests();

        $this->createComic(['title' => 'Chế Độ Mặc Định Normal']);

        $response = $this->getJson('/api/ai-search?q=Normal');

        $response->assertOk();
        $response->assertJsonPath('meta.mode', 'normal');
        $this->assertFalse($response->json('meta.ai_used'));
        $this->assertTrue($response->json('meta.is_fallback'));
        Http::assertNothingSent();
    }

    /**
     * 14. Explicit normal mode does not invoke Gemini.
     */
    public function test_explicit_normal_mode_does_not_call_gemini(): void
    {
        Http::preventStrayRequests();

        $this->createComic(['title' => 'Chế Độ Tường Minh Normal']);

        $response = $this->getJson('/api/ai-search?q=Normal&mode=normal');

        $response->assertOk();
        $response->assertJsonPath('meta.mode', 'normal');
        $this->assertFalse($response->json('meta.ai_used'));
        Http::assertNothingSent();
    }

    /**
     * 15. Invalid mode returns HTTP 422.
     */
    public function test_invalid_mode_returns_http_422(): void
    {
        $response = $this->getJson('/api/ai-search?q=Kiem+Hiep&mode=super_ai');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['mode']);
    }

    /**
     * 16. AI mode invokes Gemini and retrieves actual database records based on clues.
     */
    public function test_ai_mode_invokes_gemini_and_returns_structured_results_from_database(): void
    {
        $comic = $this->createComic([
            'title' => 'Thám Tử Học Đường',
            'alternative_titles' => 'Detective Club Chronicles',
            'description' => 'Một nhóm bạn trẻ cùng nữ sinh Sonoko khám phá bí ẩn về quái vật màu hồng trong trường.',
        ]);

        config(['ai.gemini.api_key' => 'mock-test-key']);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'series' => 'Thám Tử Học Đường',
                                        'characters' => ['Sonoko'],
                                        'remembered_events' => ['khám phá bí ẩn'],
                                        'opponent_description' => 'quái vật',
                                        'visual_clues' => ['quái vật màu hồng'],
                                        'time_reference' => null,
                                        'keywords' => ['thám tử', 'Sonoko', 'quái vật màu hồng'],
                                        'search_intent' => 'comic_or_chapter',
                                        'uncertainty_notes' => [],
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/ai-search?q=Toi+nho+co+Sonoko+va+quai+vat+mau+hong&mode=ai');

        $response->assertOk();
        $response->assertJsonPath('meta.mode', 'ai');
        $this->assertTrue($response->json('meta.ai_used'));
        $this->assertFalse($response->json('meta.is_fallback'));
        $this->assertNull($response->json('meta.fallback_notice'));

        $response->assertJsonCount(1, 'data');
        $this->assertSame($comic->id, $response->json('data.0.id'));
        $this->assertFalse($response->json('data.0.is_fallback'));
        $this->assertStringContainsString('AI phân tích manh mối', $response->json('data.0.match_reason'));

        Http::assertSentCount(1);
    }

    /**
     * 17. AI mode falls back when Gemini returns invalid JSON.
     */
    public function test_ai_mode_falls_back_when_gemini_returns_invalid_json(): void
    {
        $comic = $this->createComic(['title' => 'Truyện Tìm Bằng Từ Khóa An Toàn']);

        config(['ai.gemini.api_key' => 'mock-test-key']);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'This is not a JSON object, it is just invalid random text.'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/ai-search?q=Từ+Khóa+An+Toàn&mode=ai');

        $response->assertOk();
        $response->assertJsonPath('meta.mode', 'ai');
        $this->assertFalse($response->json('meta.ai_used'));
        $this->assertTrue($response->json('meta.is_fallback'));
        $this->assertNotNull($response->json('meta.fallback_notice'));
        $this->assertStringContainsString('tự động chuyển sang tìm kiếm từ khóa thông thường', $response->json('meta.fallback_notice'));

        // Results still returned safely via fallback
        $response->assertJsonCount(1, 'data');
        $this->assertSame($comic->id, $response->json('data.0.id'));
    }

    /**
     * 18. AI mode falls back when Gemini times out.
     */
    public function test_ai_mode_falls_back_when_gemini_times_out(): void
    {
        $comic = $this->createComic(['title' => 'Truyện Chống Network Timeout']);

        config(['ai.gemini.api_key' => 'mock-test-key']);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 8000 milliseconds');
            },
        ]);

        $response = $this->getJson('/api/ai-search?q=Network+Timeout&mode=ai');

        $response->assertOk();
        $response->assertJsonPath('meta.mode', 'ai');
        $this->assertFalse($response->json('meta.ai_used'));
        $this->assertTrue($response->json('meta.is_fallback'));
        $this->assertNotNull($response->json('meta.fallback_notice'));
        $this->assertSame($comic->id, $response->json('data.0.id'));
    }

    /**
     * 19. AI mode falls back when Gemini returns HTTP 429 quota or 500 error.
     */
    public function test_ai_mode_falls_back_when_gemini_returns_quota_429(): void
    {
        $comic = $this->createComic(['title' => 'Truyện Chống Quota Exhaustion']);

        config(['ai.gemini.api_key' => 'mock-test-key']);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 429,
                    'message' => 'Resource has been exhausted (check quota).',
                ],
            ], 429),
        ]);

        $response = $this->getJson('/api/ai-search?q=Quota+Exhaustion&mode=ai');

        $response->assertOk();
        $response->assertJsonPath('meta.mode', 'ai');
        $this->assertFalse($response->json('meta.ai_used'));
        $this->assertTrue($response->json('meta.is_fallback'));
        $this->assertSame($comic->id, $response->json('data.0.id'));
    }

    /**
     * 20. AI mode falls back when Gemini API key is missing without making HTTP calls.
     */
    public function test_ai_mode_falls_back_when_api_key_is_missing(): void
    {
        Http::preventStrayRequests();

        config(['ai.gemini.api_key' => null]);

        $comic = $this->createComic(['title' => 'Truyện Không Cần API Key']);

        $response = $this->getJson('/api/ai-search?q=Không+Cần+API&mode=ai');

        $response->assertOk();
        $response->assertJsonPath('meta.mode', 'ai');
        $this->assertFalse($response->json('meta.ai_used'));
        $this->assertTrue($response->json('meta.is_fallback'));
        $this->assertSame($comic->id, $response->json('data.0.id'));

        Http::assertNothingSent();
    }

    /**
     * 21. AI mode strictly excludes unapproved comics.
     */
    public function test_ai_mode_strictly_excludes_unapproved_comics(): void
    {
        $approved = $this->createComic(['title' => 'Truyện Duyệt Hợp Lệ', 'approval_status' => 'approved', 'description' => 'Hiệp sĩ rồng cứu công chúa.']);
        $draft = $this->createComic(['title' => 'Truyện Nháp Không Duyệt', 'approval_status' => 'draft', 'description' => 'Hiệp sĩ rồng cứu công chúa.']);

        config(['ai.gemini.api_key' => 'mock-test-key']);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'series' => null,
                                        'characters' => ['Hiệp sĩ rồng'],
                                        'remembered_events' => ['cứu công chúa'],
                                        'opponent_description' => null,
                                        'visual_clues' => [],
                                        'time_reference' => null,
                                        'keywords' => ['Hiệp sĩ rồng', 'công chúa'],
                                        'search_intent' => 'comic_or_chapter',
                                        'uncertainty_notes' => [],
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/ai-search?q=Hiep+si+rong+cuu+cong+chua&mode=ai');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame($approved->id, $response->json('data.0.id'));
    }
}
