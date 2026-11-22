<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use App\Services\AiSearchService;
use App\Services\Contracts\AiSearchServiceInterface;
use App\Services\FallbackSearchService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AiSearchServiceTest extends TestCase
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

    private function createApprovedComic(array $attributes = []): Comic
    {
        $comic = Comic::create(array_merge([
            'title' => 'Võ Thần Trùng Sinh',
            'slug' => 'vo-than-trung-sinh-' . uniqid(),
            'author_name' => 'Nguyễn Nhật Ánh',
            'alternative_titles' => 'Martial God Rebirth',
            'user_id' => $this->creator->id,
            'description' => 'Một cao thủ kiếm thuật chuyển sinh vào một học viện ma thuật.',
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
     * Test container resolves contracts and services properly.
     */
    public function test_service_container_resolves_contracts(): void
    {
        $aiService = app(AiSearchServiceInterface::class);
        $fallbackService = app(FallbackSearchService::class);

        $this->assertInstanceOf(AiSearchServiceInterface::class, $aiService);
        $this->assertInstanceOf(AiSearchService::class, $aiService);
        $this->assertInstanceOf(FallbackSearchService::class, $fallbackService);
    }

    /**
     * Test minimum query length guard (< 2 characters returns empty).
     */
    public function test_fallback_search_ignores_queries_shorter_than_two_characters(): void
    {
        $this->createApprovedComic(['title' => 'Đại Hiệp']);

        $service = app(FallbackSearchService::class);

        $this->assertSame([], $service->search(''));
        $this->assertSame([], $service->search(' '));
        $this->assertSame([], $service->search('a'));
    }

    /**
     * Test that unapproved comics (draft, pending, rejected) are NEVER returned.
     */
    public function test_fallback_search_strictly_excludes_unapproved_comics(): void
    {
        // 1 Approved comic
        $this->createApprovedComic([
            'title' => 'Bí Kíp Tu Tiên Đã Duyệt',
            'approval_status' => 'approved',
        ]);

        // 3 Unapproved comics with identical keywords
        $this->createApprovedComic([
            'title' => 'Bí Kíp Tu Tiên Bản Nháp',
            'approval_status' => 'draft',
        ]);
        $this->createApprovedComic([
            'title' => 'Bí Kíp Tu Tiên Chờ Duyệt',
            'approval_status' => 'pending',
        ]);
        $this->createApprovedComic([
            'title' => 'Bí Kíp Tu Tiên Bị Từ Chối',
            'approval_status' => 'rejected',
        ]);

        $service = app(FallbackSearchService::class);
        $results = $service->search('Tu Tiên');

        $this->assertCount(1, $results);
        $this->assertSame('Bí Kíp Tu Tiên Đã Duyệt', $results[0]['title']);
        $this->assertTrue($results[0]['is_fallback']);
    }

    /**
     * Test keyword matching across title, alternative_titles, author, description, tags, and genres.
     */
    public function test_fallback_search_matches_various_fields_and_relations(): void
    {
        $actionGenre = Genre::create(['name' => 'Hành Động', 'slug' => 'hanh-dong']);
        $swordTag = Tag::create(['name' => 'Kiếm Thuật', 'slug' => 'kiem-thuat']);

        $comic1 = $this->createApprovedComic([
            'title' => 'Nhất Kiếm Chấn Thiên Hạ',
            'author_name' => 'Trần Kiếm',
            'description' => 'Hành trình tầm đạo gian nan.',
        ]);
        $comic1->genres()->attach($actionGenre->id);
        $comic1->tags()->attach($swordTag->id);

        $comic2 = $this->createApprovedComic([
            'title' => 'Pháp Sư Đại Tài',
            'alternative_titles' => 'Grand Archmage Tales',
            'author_name' => 'Hồ Quang',
            'description' => 'Pháp thuật tối thượng thống trị lục địa.',
        ]);

        $service = app(FallbackSearchService::class);

        // Test title match
        $titleResults = $service->search('Nhất Kiếm');
        $this->assertCount(1, $titleResults);
        $this->assertSame($comic1->id, $titleResults[0]['id']);

        // Test alternative_titles match
        $altResults = $service->search('Archmage');
        $this->assertCount(1, $altResults);
        $this->assertSame($comic2->id, $altResults[0]['id']);

        // Test author_name match
        $authorResults = $service->search('Trần Kiếm');
        $this->assertCount(1, $authorResults);
        $this->assertSame($comic1->id, $authorResults[0]['id']);

        // Test description match
        $descResults = $service->search('thống trị lục địa');
        $this->assertCount(1, $descResults);
        $this->assertSame($comic2->id, $descResults[0]['id']);

        // Test tag match (key semantic bridge)
        $tagResults = $service->search('truyện kiếm thuật');
        $this->assertCount(1, $tagResults);
        $this->assertSame($comic1->id, $tagResults[0]['id']);
        $this->assertContains('Kiếm Thuật', $tagResults[0]['matched_tags']);

        // Test genre match
        $genreResults = $service->search('hành động đỉnh cao');
        $this->assertCount(1, $genreResults);
        $this->assertSame($comic1->id, $genreResults[0]['id']);
        $this->assertContains('Hành Động', $genreResults[0]['matched_genres']);
    }

    /**
     * Test safe LIKE wildcard escaping (% and _ and \ do not crash or leak).
     */
    public function test_fallback_search_escapes_special_wildcard_characters(): void
    {
        $this->createApprovedComic(['title' => '100% Khát Vọng']);
        $this->createApprovedComic(['title' => 'Kẻ_Hủy_Diệt']);

        $service = app(FallbackSearchService::class);

        $percentResults = $service->search('100%');
        $this->assertCount(1, $percentResults);
        $this->assertSame('100% Khát Vọng', $percentResults[0]['title']);

        $underscoreResults = $service->search('Kẻ_Hủy');
        $this->assertCount(1, $underscoreResults);
        $this->assertSame('Kẻ_Hủy_Diệt', $underscoreResults[0]['title']);
    }

    /**
     * Test result payload contains required metadata structure.
     */
    public function test_search_results_contain_standardized_metadata(): void
    {
        $comic = $this->createApprovedComic(['title' => 'Chuẩn Định Dạng Metadata']);

        $service = app(FallbackSearchService::class);
        $results = $service->search('Metadata');

        $this->assertNotEmpty($results);
        $item = $results[0];

        $this->assertSame($comic->id, $item['id']);
        $this->assertSame('Chuẩn Định Dạng Metadata', $item['title']);
        $this->assertArrayHasKey('slug', $item);
        $this->assertArrayHasKey('cover_path', $item);
        $this->assertArrayHasKey('author_name', $item);
        $this->assertArrayHasKey('age_rating', $item);
        $this->assertArrayHasKey('status', $item);
        $this->assertArrayHasKey('score', $item);
        $this->assertIsFloat($item['score']);
        $this->assertArrayHasKey('is_fallback', $item);
        $this->assertTrue($item['is_fallback']);
        $this->assertArrayHasKey('match_reason', $item);
        $this->assertArrayHasKey('matched_tags', $item);
        $this->assertArrayHasKey('matched_genres', $item);
    }

    /**
     * Test fallback summary generation creates structured text.
     */
    public function test_generate_summary_creates_structured_fallback_text(): void
    {
        $service = app(FallbackSearchService::class);

        $this->assertNull($service->generateSummary('   '));

        $summary = $service->generateSummary(
            'Hành trình tu tiên tìm kiếm đạo tâm bất diệt giữa muôn ngàn hiểm nguy.',
            ['Tu Tiên', 'Hành Động'],
            ['Kiếm Tu', 'Trọng Sinh']
        );

        $this->assertNotNull($summary);
        $this->assertStringContainsString('Hành trình tu tiên', $summary);
        $this->assertStringContainsString('Thể loại: Tu Tiên, Hành Động', $summary);
        $this->assertStringContainsString('Đặc điểm: Kiếm Tu, Trọng Sinh', $summary);
    }

    /**
     * Test AiSearchService delegates to fallback when AI is unconfigured.
     */
    public function test_ai_search_service_delegates_to_fallback_when_unconfigured(): void
    {
        $this->createApprovedComic(['title' => 'Truyện Chờ AI']);

        $aiService = app(AiSearchServiceInterface::class);

        $this->assertFalse($aiService->isAvailable());

        $results = $aiService->search('Chờ AI');
        $this->assertCount(1, $results);
        $this->assertSame('Truyện Chờ AI', $results[0]['title']);
        $this->assertTrue($results[0]['is_fallback']);
    }

    /**
     * Test AiSearchService calls provider when configured and available.
     */
    public function test_ai_search_service_uses_mock_provider_when_available(): void
    {
        $fallback = app(FallbackSearchService::class);
        $aiService = new AiSearchService($fallback);

        // Configure mock AI provider driver
        $aiService->setProvider(function (string $query, array $filters, int $limit) {
            return [
                [
                    'id' => 999,
                    'title' => 'Mocked Vector Result',
                    'slug' => 'mocked-vector-result',
                    'cover_path' => 'placeholders/comics/vector.webp',
                    'author_name' => 'AI Provider',
                    'age_rating' => '13+',
                    'status' => 'ongoing',
                    'score' => 0.9521,
                    'match_reason' => 'Cosine similarity match (0.9521)',
                    'matched_tags' => [],
                    'matched_genres' => [],
                    'ai_summary' => null,
                ],
            ];
        });
        $aiService->setAvailabilityOverride(true);

        $this->assertTrue($aiService->isAvailable());

        $results = $aiService->search('Tìm kiếm ngữ nghĩa');
        $this->assertCount(1, $results);
        $this->assertSame(999, $results[0]['id']);
        $this->assertFalse($results[0]['is_fallback']);
        $this->assertSame('Cosine similarity match (0.9521)', $results[0]['match_reason']);
    }

    /**
     * Test that HTTP 429 quota exhaustion or API crash triggers graceful fallback.
     */
    public function test_ai_search_service_catches_quota_429_or_exception_and_falls_back(): void
    {
        $comic = $this->createApprovedComic(['title' => 'Cứu Hộ Thành Công']);

        $fallback = app(FallbackSearchService::class);
        $aiService = new AiSearchService($fallback);

        // Simulate provider throwing QuotaExhausted / HTTP 429 exception
        $aiService->setProvider(function () {
            throw new RuntimeException('HTTP 429: Too Many Requests / Quota Exceeded');
        });
        $aiService->setAvailabilityOverride(true);

        $results = $aiService->search('Cứu Hộ');

        // Verify that the system DID NOT crash or return empty
        $this->assertCount(1, $results);
        $this->assertSame($comic->id, $results[0]['id']);
        $this->assertTrue($results[0]['is_fallback']);
        $this->assertArrayHasKey('fallback_notice', $results[0]);
        $this->assertStringContainsString('Dịch vụ AI tạm thời gián đoạn hoặc cạn quota', $results[0]['fallback_notice']);
    }

    /**
     * Test that provider timeout triggers graceful fallback.
     */
    public function test_ai_search_service_catches_timeout_and_falls_back(): void
    {
        $comic = $this->createApprovedComic(['title' => 'Truyện Chống Timeout']);

        $fallback = app(FallbackSearchService::class);
        $aiService = new AiSearchService($fallback);

        // Configure strict timeout of 1 second and provider that sleeps 1.2s
        $aiService->setTimeout(1);
        $aiService->setProvider(function () {
            usleep(1100000); // 1.1s
            return [];
        });
        $aiService->setAvailabilityOverride(true);

        $results = $aiService->search('Chống Timeout');

        $this->assertCount(1, $results);
        $this->assertSame($comic->id, $results[0]['id']);
        $this->assertTrue($results[0]['is_fallback']);
        $this->assertArrayHasKey('fallback_notice', $results[0]);
        $this->assertStringContainsString('Dịch vụ AI phản hồi chậm', $results[0]['fallback_notice']);
    }

    /**
     * Test summary generator fallback when primary summary driver throws exception.
     */
    public function test_summary_generation_falls_back_on_driver_exception(): void
    {
        $fallback = app(FallbackSearchService::class);
        $aiService = new AiSearchService($fallback);

        $aiService->setAvailabilityOverride(true);
        $aiService->setSummaryDriver(function () {
            throw new Exception('AI model overload');
        });

        $summary = $aiService->generateSummary('Mô tả nguyên bản của truyện.', ['Hành Động']);

        $this->assertNotNull($summary);
        $this->assertStringContainsString('Mô tả nguyên bản của truyện.', $summary);
        $this->assertStringContainsString('Thể loại: Hành Động', $summary);
    }
}
