<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComicCatalogApiTest extends TestCase
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
            'avatar_path' => 'placeholders/avatars/creator.webp',
        ]);
        $this->creator->role = 'creator';
        $this->creator->save();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);
        $this->admin->role = 'admin';
        $this->admin->save();
    }

    private function createComic(array $attributes = []): Comic
    {
        $comic = Comic::create(array_merge([
            'title' => 'Truyện Mẫu',
            'slug' => 'truyen-mau-' . uniqid(),
            'author_name' => 'Tác Giả Demo',
            'user_id' => $this->creator->id,
            'description' => 'Mô tả truyện mẫu.',
            'cover_path' => 'placeholders/comics/demo-cover.webp',
            'banner_path' => 'placeholders/comics/demo-banner.webp',
            'age_rating' => '13+',
            'status' => 'ongoing',
        ], $attributes));

        // Assign protected attributes
        $comic->approval_status = $attributes['approval_status'] ?? 'approved';
        $comic->approved_by = $attributes['approved_by'] ?? $this->admin->id;
        $comic->approved_at = $attributes['approved_at'] ?? now();
        $comic->views_count = $attributes['views_count'] ?? 0;
        $comic->follows_count = $attributes['follows_count'] ?? 0;
        $comic->is_featured = $attributes['is_featured'] ?? false;
        if (isset($attributes['created_at'])) {
            $comic->created_at = $attributes['created_at'];
        }
        $comic->save();

        return $comic;
    }

    /**
     * 1. Test GET /api/comics returns only approved comics.
     */
    public function test_comics_index_returns_only_approved_comics(): void
    {
        $this->createComic(['title' => 'Truyện Đã Duyệt', 'slug' => 'approved-comic', 'approval_status' => 'approved']);
        $this->createComic(['title' => 'Truyện Bản Nháp', 'slug' => 'draft-comic', 'approval_status' => 'draft']);
        $this->createComic(['title' => 'Truyện Đang Chờ', 'slug' => 'pending-comic', 'approval_status' => 'pending']);
        $this->createComic(['title' => 'Truyện Bị Từ Chối', 'slug' => 'rejected-comic', 'approval_status' => 'rejected']);

        $response = $this->getJson('/api/comics');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame('approved-comic', $response->json('data.0.slug'));
    }

    /**
     * 2. Test unapproved comics never match search or filters.
     */
    public function test_comics_index_unapproved_comics_never_match_search_or_filter(): void
    {
        $genre = Genre::create(['name' => 'Hành Động', 'slug' => 'action']);

        $unapprovedComic = $this->createComic([
            'title' => 'Siêu Phẩm Bí Ẩn',
            'author_name' => 'Tác Giả Vô Danh',
            'approval_status' => 'draft',
        ]);
        $unapprovedComic->genres()->attach($genre->id);

        // Search by title
        $resSearch = $this->getJson('/api/comics?q=Bí+Ẩn');
        $resSearch->assertOk();
        $resSearch->assertJsonCount(0, 'data');

        // Filter by genre
        $resGenre = $this->getJson('/api/comics?genre=action');
        $resGenre->assertOk();
        $resGenre->assertJsonCount(0, 'data');
    }

    /**
     * 3. Test pagination default (15) and custom per_page.
     */
    public function test_comics_index_pagination_default_and_custom_per_page(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->createComic(['slug' => "comic-{$i}", 'title' => "Truyện {$i}"]);
        }

        // Default pagination: 15 items on page 1
        $resDefault = $this->getJson('/api/comics');
        $resDefault->assertOk();
        $resDefault->assertJsonCount(15, 'data');
        $this->assertSame(20, $resDefault->json('meta.total'));
        $this->assertSame(15, $resDefault->json('meta.per_page'));
        $this->assertSame(1, $resDefault->json('meta.current_page'));
        $this->assertSame(2, $resDefault->json('meta.last_page'));

        // Custom per_page = 5
        $resCustom = $this->getJson('/api/comics?per_page=5&page=2');
        $resCustom->assertOk();
        $resCustom->assertJsonCount(5, 'data');
        $this->assertSame(2, $resCustom->json('meta.current_page'));
    }

    /**
     * 4. Test validation errors: per_page > 50, page < 1, invalid status or sort return HTTP 422.
     */
    public function test_comics_index_validation_errors(): void
    {
        $resMaxPerPage = $this->getJson('/api/comics?per_page=51');
        $resMaxPerPage->assertStatus(422);
        $resMaxPerPage->assertJsonValidationErrors(['per_page']);

        $resMinPage = $this->getJson('/api/comics?page=0');
        $resMinPage->assertStatus(422);
        $resMinPage->assertJsonValidationErrors(['page']);

        $resInvalidStatus = $this->getJson('/api/comics?status=invalid_status');
        $resInvalidStatus->assertStatus(422);
        $resInvalidStatus->assertJsonValidationErrors(['status']);

        $resInvalidSort = $this->getJson('/api/comics?sort=invalid_sort');
        $resInvalidSort->assertStatus(422);
        $resInvalidSort->assertJsonValidationErrors(['sort']);
    }

    /**
     * 5. Test filter by genre slug.
     */
    public function test_comics_index_filters_by_genre_slug(): void
    {
        $action = Genre::create(['name' => 'Hành Động', 'slug' => 'action']);
        $romance = Genre::create(['name' => 'Tình Cảm', 'slug' => 'romance']);

        $comic1 = $this->createComic(['slug' => 'comic-action', 'title' => 'Truyện Action']);
        $comic1->genres()->attach($action->id);

        $comic2 = $this->createComic(['slug' => 'comic-romance', 'title' => 'Truyện Romance']);
        $comic2->genres()->attach($romance->id);

        $response = $this->getJson('/api/comics?genre=action');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame('comic-action', $response->json('data.0.slug'));
    }

    /**
     * 6. Test filter by tag slug.
     */
    public function test_comics_index_filters_by_tag_slug(): void
    {
        $tag1 = Tag::create(['name' => 'Kiếm thuật', 'slug' => 'kiem-thuat']);
        $tag2 = Tag::create(['name' => 'Học đường', 'slug' => 'hoc-duong']);

        $comic1 = $this->createComic(['slug' => 'comic-tag1']);
        $comic1->tags()->attach($tag1->id);

        $comic2 = $this->createComic(['slug' => 'comic-tag2']);
        $comic2->tags()->attach($tag2->id);

        $response = $this->getJson('/api/comics?tag=kiem-thuat');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame('comic-tag1', $response->json('data.0.slug'));
    }

    /**
     * 7. Test filter by status (ongoing, completed, hiatus).
     */
    public function test_comics_index_filters_by_status(): void
    {
        $this->createComic(['slug' => 'comic-ongoing', 'status' => 'ongoing']);
        $this->createComic(['slug' => 'comic-completed', 'status' => 'completed']);
        $this->createComic(['slug' => 'comic-hiatus', 'status' => 'hiatus']);

        $resOngoing = $this->getJson('/api/comics?status=ongoing');
        $resOngoing->assertOk();
        $resOngoing->assertJsonCount(1, 'data');
        $this->assertSame('comic-ongoing', $resOngoing->json('data.0.slug'));

        $resCompleted = $this->getJson('/api/comics?status=completed');
        $resCompleted->assertOk();
        $resCompleted->assertJsonCount(1, 'data');
        $this->assertSame('comic-completed', $resCompleted->json('data.0.slug'));
    }

    /**
     * 8. Test keyword search matches title, author_name, and alternative_titles.
     */
    public function test_comics_index_search_by_title_author_and_alternative_titles(): void
    {
        $this->createComic([
            'slug' => 'comic-by-title',
            'title' => 'Đại Lục Phép Thuật',
            'author_name' => 'Tác Giả A',
            'alternative_titles' => 'Magic Land',
        ]);

        $this->createComic([
            'slug' => 'comic-by-author',
            'title' => 'Võ Đạo Đỉnh Cao',
            'author_name' => 'Nguyễn Minh Trí',
            'alternative_titles' => null,
        ]);

        $this->createComic([
            'slug' => 'comic-by-alt',
            'title' => 'Thợ Săn Rồng',
            'author_name' => 'Tác Giả B',
            'alternative_titles' => 'Dragon Slayer Chronicles',
        ]);

        // Search by title
        $resTitle = $this->getJson('/api/comics?q=Phép+Thuật');
        $resTitle->assertOk();
        $resTitle->assertJsonCount(1, 'data');
        $this->assertSame('comic-by-title', $resTitle->json('data.0.slug'));

        // Search by author
        $resAuthor = $this->getJson('/api/comics?q=Minh+Trí');
        $resAuthor->assertOk();
        $resAuthor->assertJsonCount(1, 'data');
        $this->assertSame('comic-by-author', $resAuthor->json('data.0.slug'));

        // Search by alternative title
        $resAlt = $this->getJson('/api/comics?q=Dragon+Slayer');
        $resAlt->assertOk();
        $resAlt->assertJsonCount(1, 'data');
        $this->assertSame('comic-by-alt', $resAlt->json('data.0.slug'));
    }

    /**
     * 9. Test keyword search safely escapes % and _ wildcard characters.
     */
    public function test_comics_index_handles_wildcard_characters_safely(): void
    {
        $this->createComic([
            'slug' => 'comic-percent',
            'title' => 'Tiết Kiệm 100% Năng Lượng',
        ]);
        $this->createComic([
            'slug' => 'comic-without-percent',
            'title' => 'Tiết Kiệm 1000 Năng Lượng',
        ]);
        $this->createComic([
            'slug' => 'comic-underscore',
            'title' => 'Special_Team Alpha',
        ]);
        $this->createComic([
            'slug' => 'comic-without-underscore',
            'title' => 'SpecialXTeam Alpha',
        ]);

        // Search for literal '100%' should only match 'comic-percent', not '1000'
        $resPercent = $this->getJson('/api/comics?q=100%25');
        $resPercent->assertOk();
        $resPercent->assertJsonCount(1, 'data');
        $this->assertSame('comic-percent', $resPercent->json('data.0.slug'));

        // Search for literal '_' should only match 'comic-underscore', not 'comic-without-underscore'
        $resUnderscore = $this->getJson('/api/comics?q=Special_Team');
        $resUnderscore->assertOk();
        $resUnderscore->assertJsonCount(1, 'data');
        $this->assertSame('comic-underscore', $resUnderscore->json('data.0.slug'));
    }

    /**
     * 10. Test sorting: latest (created_at DESC), views (views_count DESC), follows (follows_count DESC) with id tie-break.
     */
    public function test_comics_index_sorting_latest_views_follows_with_tiebreak(): void
    {
        $c1 = $this->createComic(['slug' => 'c1', 'views_count' => 100, 'follows_count' => 50, 'created_at' => now()->subDays(3)]);
        $c2 = $this->createComic(['slug' => 'c2', 'views_count' => 500, 'follows_count' => 10, 'created_at' => now()->subDays(1)]);
        $c3 = $this->createComic(['slug' => 'c3', 'views_count' => 500, 'follows_count' => 90, 'created_at' => now()->subDays(2)]);

        // Sort by views DESC, tie-break id DESC (c3 id > c2 id since created later)
        $resViews = $this->getJson('/api/comics?sort=views');
        $resViews->assertOk();
        $viewsOrder = array_column($resViews->json('data'), 'slug');
        $this->assertSame(['c3', 'c2', 'c1'], $viewsOrder);

        // Sort by follows DESC
        $resFollows = $this->getJson('/api/comics?sort=follows');
        $resFollows->assertOk();
        $followsOrder = array_column($resFollows->json('data'), 'slug');
        $this->assertSame(['c3', 'c1', 'c2'], $followsOrder);

        // Sort by latest (created_at DESC)
        $resLatest = $this->getJson('/api/comics?sort=latest');
        $resLatest->assertOk();
        $latestOrder = array_column($resLatest->json('data'), 'slug');
        $this->assertSame(['c2', 'c3', 'c1'], $latestOrder);
    }

    /**
     * 11. Test GET /api/comics/{slug} returns full details for approved comic.
     */
    public function test_comics_show_returns_full_details_for_approved_comic(): void
    {
        $genre = Genre::create(['name' => 'Hành Động', 'slug' => 'action']);
        $tag = Tag::create(['name' => 'Kiếm thuật', 'slug' => 'kiem-thuat']);

        $comic = $this->createComic([
            'slug' => 'kiem-vuong',
            'title' => 'Kiếm Vương Tái Sinh',
            'alternative_titles' => 'Sword King',
            'description' => 'Mô tả chi tiết bộ truyện.',
            'ai_summary' => 'Tóm tắt nội dung bằng AI.',
            'views_count' => 12000,
            'follows_count' => 800,
        ]);
        $comic->genres()->attach($genre->id);
        $comic->tags()->attach($tag->id);

        Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
            'status' => 'published',
            'views_count' => 5000,
        ]);

        $response = $this->getJson('/api/comics/kiem-vuong');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame('kiem-vuong', $data['slug']);
        $this->assertSame('Kiếm Vương Tái Sinh', $data['title']);
        $this->assertSame('Mô tả chi tiết bộ truyện.', $data['description']);
        $this->assertSame('Tóm tắt nội dung bằng AI.', $data['ai_summary']);
        $this->assertSame(1, $data['published_chapters_count']);
        $this->assertCount(1, $data['genres']);
        $this->assertSame('action', $data['genres'][0]['slug']);
        $this->assertCount(1, $data['tags']);
        $this->assertSame('kiem-thuat', $data['tags'][0]['slug']);
        $this->assertCount(1, $data['chapters']);
        $this->assertSame('1.00', $data['chapters'][0]['chapter_number']);
        $this->assertStringContainsString('/storage/placeholders/comics/demo-cover.webp', $data['cover_url']);
        $this->assertStringContainsString('/storage/placeholders/comics/demo-banner.webp', $data['banner_url']);
    }

    /**
     * 12. Test GET /api/comics/{slug} returns 404 for nonexistent, draft, pending, or rejected comic.
     */
    public function test_comics_show_returns_404_for_nonexistent_or_unapproved_comic(): void
    {
        $this->createComic(['slug' => 'draft-comic', 'approval_status' => 'draft']);
        $this->createComic(['slug' => 'pending-comic', 'approval_status' => 'pending']);
        $this->createComic(['slug' => 'rejected-comic', 'approval_status' => 'rejected']);

        // Nonexistent
        $resNonexistent = $this->getJson('/api/comics/nonexistent-slug');
        $resNonexistent->assertNotFound();
        $resNonexistent->assertJson(['status' => 'error']);

        // Draft
        $resDraft = $this->getJson('/api/comics/draft-comic');
        $resDraft->assertNotFound();
        $resDraft->assertJson(['status' => 'error']);

        // Pending
        $resPending = $this->getJson('/api/comics/pending-comic');
        $resPending->assertNotFound();

        // Rejected
        $resRejected = $this->getJson('/api/comics/rejected-comic');
        $resRejected->assertNotFound();
    }

    /**
     * 13. Test GET /api/comics/{slug} only includes published chapters; draft/rejected chapters are excluded.
     */
    public function test_comics_show_excludes_draft_and_rejected_chapters(): void
    {
        $comic = $this->createComic(['slug' => 'multi-chapter-comic']);

        Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => '1.00',
            'title' => 'Chương Đã Xuất Bản',
            'slug' => 'chuong-1',
            'status' => 'published',
        ]);

        Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => '2.00',
            'title' => 'Chương Bản Nháp',
            'slug' => 'chuong-2',
            'status' => 'draft',
        ]);

        Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => '3.00',
            'title' => 'Chương Bị Từ Chối',
            'slug' => 'chuong-3',
            'status' => 'rejected',
        ]);

        $response = $this->getJson('/api/comics/multi-chapter-comic');

        $response->assertOk();
        $chapters = $response->json('data.chapters');

        $this->assertCount(1, $chapters);
        $this->assertSame('1.00', $chapters[0]['chapter_number']);
        $this->assertSame('Chương Đã Xuất Bản', $chapters[0]['title']);
    }

    /**
     * 14. Test published_chapters_count only counts published chapters.
     */
    public function test_comics_published_chapters_count_only_counts_published_chapters(): void
    {
        $comic = $this->createComic(['slug' => 'counted-comic']);

        Chapter::create(['comic_id' => $comic->id, 'chapter_number' => '1.00', 'status' => 'published', 'slug' => 'c1']);
        Chapter::create(['comic_id' => $comic->id, 'chapter_number' => '2.00', 'status' => 'published', 'slug' => 'c2']);
        Chapter::create(['comic_id' => $comic->id, 'chapter_number' => '3.00', 'status' => 'draft', 'slug' => 'c3']);

        // Check in list endpoint
        $resList = $this->getJson('/api/comics');
        $resList->assertOk();
        $this->assertSame(2, $resList->json('data.0.published_chapters_count'));

        // Check in show endpoint
        $resShow = $this->getJson('/api/comics/counted-comic');
        $resShow->assertOk();
        $this->assertSame(2, $resShow->json('data.published_chapters_count'));
    }

    /**
     * 15. Test user_id = null does not crash index or show endpoint, returns creator = null.
     */
    public function test_comics_index_and_show_handle_null_user_id_safely(): void
    {
        $comic = $this->createComic([
            'slug' => 'null-creator-comic',
            'user_id' => null,
            'author_name' => 'Tác Giả Sưu Tầm',
        ]);

        $resList = $this->getJson('/api/comics');
        $resList->assertOk();
        $this->assertNull($resList->json('data.0.creator'));
        $this->assertSame('Tác Giả Sưu Tầm', $resList->json('data.0.author_name'));

        $resShow = $this->getJson('/api/comics/null-creator-comic');
        $resShow->assertOk();
        $this->assertNull($resShow->json('data.creator'));
    }

    /**
     * 16. Test neither index nor show endpoints leak internal fields.
     */
    public function test_comics_api_does_not_leak_internal_attributes(): void
    {
        $comic = $this->createComic([
            'slug' => 'secret-check-comic',
            'rejection_reason' => 'Lý do mật.',
            'pending_changes' => ['title' => 'Tên mới'],
            'embedding' => [0.123, 0.456],
        ]);

        $resList = $this->getJson('/api/comics');
        $resList->assertOk();
        $listItem = $resList->json('data.0');

        $sensitiveFields = ['approved_by', 'approval_status', 'rejection_reason', 'pending_changes', 'embedding'];
        foreach ($sensitiveFields as $field) {
            $this->assertArrayNotHasKey($field, $listItem);
        }
        $this->assertArrayNotHasKey('email', $listItem['creator']);

        $resShow = $this->getJson('/api/comics/secret-check-comic');
        $resShow->assertOk();
        $showItem = $resShow->json('data');

        foreach ($sensitiveFields as $field) {
            $this->assertArrayNotHasKey($field, $showItem);
        }
        $this->assertArrayNotHasKey('email', $showItem['creator']);
    }

    /**
     * 17. Test nullable cover_path, banner_path, and creator avatar return null URLs.
     */
    public function test_comics_api_handles_null_cover_and_banner_paths_safely(): void
    {
        $comic = $this->createComic([
            'slug' => 'null-banner-comic',
            'banner_path' => null,
        ]);

        $response = $this->getJson('/api/comics/null-banner-comic');
        $response->assertOk();
        $this->assertNull($response->json('data.banner_path'));
        $this->assertNull($response->json('data.banner_url'));
    }

    /**
     * 18. Test paginated response structure matches standard convention.
     */
    public function test_comics_index_response_has_standard_pagination_structure(): void
    {
        $this->createComic();

        $response = $this->getJson('/api/comics');
        $response->assertOk();

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'slug',
                    'alternative_titles',
                    'author_name',
                    'cover_path',
                    'cover_url',
                    'age_rating',
                    'status',
                    'views_count',
                    'follows_count',
                    'is_featured',
                    'published_chapters_count',
                    'genres',
                    'tags',
                    'creator',
                    'created_at',
                    'updated_at',
                ],
            ],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'links',
                'path',
                'per_page',
                'to',
                'total',
            ],
            'links' => [
                'first',
                'last',
                'prev',
                'next',
            ],
        ]);
    }

    /**
     * 19. Test comics API with full database seeder dataset.
     */
    public function test_comics_api_with_database_seeder_dataset(): void
    {
        $this->seed(DatabaseSeeder::class);

        $resList = $this->getJson('/api/comics');
        $resList->assertOk();
        $resList->assertJsonCount(3, 'data');

        $resShow = $this->getJson('/api/comics/huyen-thoai-kiem-vuong');
        $resShow->assertOk();
        $this->assertSame('Huyền Thoại Kiếm Vương', $resShow->json('data.title'));
        $this->assertSame(2, $resShow->json('data.published_chapters_count'));
        $this->assertCount(2, $resShow->json('data.chapters'));
        $this->assertCount(2, $resShow->json('data.genres'));
        $this->assertCount(4, $resShow->json('data.tags'));
    }
}
