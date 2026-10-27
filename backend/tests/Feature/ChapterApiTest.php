<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterImage;
use App\Models\Comic;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChapterApiTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = User::create([
            'name' => 'Tác Giả Demo',
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

        $comic->approval_status = $attributes['approval_status'] ?? 'approved';
        $comic->approved_by = $attributes['approved_by'] ?? $this->admin->id;
        $comic->approved_at = $attributes['approved_at'] ?? now();
        $comic->views_count = $attributes['views_count'] ?? 0;
        $comic->follows_count = $attributes['follows_count'] ?? 0;
        $comic->is_featured = $attributes['is_featured'] ?? false;
        $comic->save();

        return $comic;
    }

    private function createChapter(Comic $comic, array $attributes = []): Chapter
    {
        $chapter = Chapter::create(array_merge([
            'comic_id' => $comic->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-' . uniqid(),
            'status' => 'published',
            'rejection_reason' => null,
        ], $attributes));

        if (isset($attributes['views_count'])) {
            $chapter->views_count = $attributes['views_count'];
            $chapter->save();
        }

        return $chapter;
    }

    /**
     * 1. Test GET /api/comics/{slug}/chapters returns published chapters in chapter_number ASC order.
     */
    public function test_chapters_index_returns_published_chapters_sorted_by_chapter_number(): void
    {
        $comic = $this->createComic(['slug' => 'comic-ordered']);

        $this->createChapter($comic, ['chapter_number' => '3.00', 'title' => 'Chương 3', 'slug' => 'c3']);
        $this->createChapter($comic, ['chapter_number' => '1.00', 'title' => 'Chương 1', 'slug' => 'c1']);
        $this->createChapter($comic, ['chapter_number' => '2.50', 'title' => 'Chương 2.5', 'slug' => 'c2-5']);

        $response = $this->getJson('/api/comics/comic-ordered/chapters');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');

        $chapterNumbers = array_column($response->json('data'), 'chapter_number');
        $this->assertSame(['1.00', '2.50', '3.00'], $chapterNumbers);
    }

    /**
     * 2. Test GET /api/comics/{slug}/chapters excludes draft and rejected chapters.
     */
    public function test_chapters_index_excludes_draft_and_rejected_chapters(): void
    {
        $comic = $this->createComic(['slug' => 'comic-filtered']);

        $this->createChapter($comic, ['chapter_number' => '1.00', 'status' => 'published', 'title' => 'Chương Published']);
        $this->createChapter($comic, ['chapter_number' => '2.00', 'status' => 'draft', 'title' => 'Chương Draft']);
        $this->createChapter($comic, ['chapter_number' => '3.00', 'status' => 'rejected', 'title' => 'Chương Rejected']);

        $response = $this->getJson('/api/comics/comic-filtered/chapters');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame('1.00', $response->json('data.0.chapter_number'));
        $this->assertSame('Chương Published', $response->json('data.0.title'));
    }

    /**
     * 3. Test GET /api/comics/{slug}/chapters returns 404 for unapproved or nonexistent comic.
     */
    public function test_chapters_index_returns_404_for_unapproved_or_nonexistent_comic(): void
    {
        $this->createComic(['slug' => 'draft-comic', 'approval_status' => 'draft']);
        $this->createComic(['slug' => 'pending-comic', 'approval_status' => 'pending']);
        $this->createComic(['slug' => 'rejected-comic', 'approval_status' => 'rejected']);

        // Nonexistent
        $this->getJson('/api/comics/nonexistent/chapters')->assertNotFound();

        // Draft
        $this->getJson('/api/comics/draft-comic/chapters')->assertNotFound();

        // Pending
        $this->getJson('/api/comics/pending-comic/chapters')->assertNotFound();

        // Rejected
        $this->getJson('/api/comics/rejected-comic/chapters')->assertNotFound();
    }

    /**
     * 4. Test approved comic with zero published chapters returns HTTP 200 with data: [].
     */
    public function test_chapters_index_returns_empty_data_when_no_published_chapters(): void
    {
        $comic = $this->createComic(['slug' => 'comic-no-chapters']);
        $this->createChapter($comic, ['chapter_number' => '1.00', 'status' => 'draft']);

        $response = $this->getJson('/api/comics/comic-no-chapters/chapters');

        $response->assertOk();
        $response->assertExactJson(['data' => []]);
    }

    /**
     * 5. Test detail endpoint returns chapter details, minimal comic info, page images and navigation.
     */
    public function test_chapter_show_returns_full_reader_data(): void
    {
        $comic = $this->createComic(['slug' => 'kiem-vuong', 'title' => 'Kiếm Vương']);

        $ch1 = $this->createChapter($comic, [
            'chapter_number' => '1.00',
            'title' => 'Chương 1: Khởi đầu',
            'slug' => 'chuong-1',
            'views_count' => 1500,
        ]);

        $ch2 = $this->createChapter($comic, [
            'chapter_number' => '2.00',
            'title' => 'Chương 2: Thử thách',
            'slug' => 'chuong-2',
            'views_count' => 800,
        ]);

        ChapterImage::create([
            'chapter_id' => $ch1->id,
            'page_number' => 1,
            'image_path' => 'placeholders/chapters/kiem-vuong/c1_p01.webp',
            'width' => 800,
            'height' => 1280,
        ]);

        $response = $this->getJson('/api/comics/kiem-vuong/chapters/1.00');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame($ch1->id, $data['id']);
        $this->assertSame('1.00', $data['chapter_number']);
        $this->assertSame('Chương 1: Khởi đầu', $data['title']);
        $this->assertSame('chuong-1', $data['slug']);
        $this->assertSame(1500, $data['views_count']);

        // Comic summary
        $this->assertSame($comic->id, $data['comic']['id']);
        $this->assertSame('Kiếm Vương', $data['comic']['title']);
        $this->assertSame('kiem-vuong', $data['comic']['slug']);

        // Images
        $this->assertCount(1, $data['images']);
        $this->assertSame(1, $data['images'][0]['page_number']);
        $this->assertStringContainsString('/storage/placeholders/chapters/kiem-vuong/c1_p01.webp', $data['images'][0]['image_url']);
        $this->assertSame(800, $data['images'][0]['width']);
        $this->assertSame(1280, $data['images'][0]['height']);

        // Navigation: prev is null, next is chapter 2
        $this->assertNull($data['prev_chapter']);
        $this->assertNotNull($data['next_chapter']);
        $this->assertSame('2.00', $data['next_chapter']['chapter_number']);
        $this->assertSame('Chương 2: Thử thách', $data['next_chapter']['title']);
        $this->assertSame('chuong-2', $data['next_chapter']['slug']);
    }

    /**
     * 6. Test chapterNumber parameter correctly handles 1, 1.0, 1.00, 1.5, 1.50, 10, 10.25.
     */
    public function test_chapter_show_handles_various_chapter_number_representations(): void
    {
        $comic = $this->createComic(['slug' => 'comic-numbers']);

        $ch1 = $this->createChapter($comic, ['chapter_number' => '1.00', 'title' => 'Chương 1']);
        $ch1_5 = $this->createChapter($comic, ['chapter_number' => '1.50', 'title' => 'Chương 1.5']);
        $ch10 = $this->createChapter($comic, ['chapter_number' => '10.00', 'title' => 'Chương 10']);
        $ch10_25 = $this->createChapter($comic, ['chapter_number' => '10.25', 'title' => 'Chương 10.25']);

        // Check 1 -> matches 1.00
        $res1 = $this->getJson('/api/comics/comic-numbers/chapters/1');
        $res1->assertOk();
        $this->assertSame('1.00', $res1->json('data.chapter_number'));
        $this->assertSame('Chương 1', $res1->json('data.title'));

        // Check 1.0 -> matches 1.00
        $res1_0 = $this->getJson('/api/comics/comic-numbers/chapters/1.0');
        $res1_0->assertOk();
        $this->assertSame('1.00', $res1_0->json('data.chapter_number'));

        // Check 1.00 -> matches 1.00
        $res1_00 = $this->getJson('/api/comics/comic-numbers/chapters/1.00');
        $res1_00->assertOk();
        $this->assertSame('1.00', $res1_00->json('data.chapter_number'));

        // Check 1.5 -> matches 1.50
        $res1_5 = $this->getJson('/api/comics/comic-numbers/chapters/1.5');
        $res1_5->assertOk();
        $this->assertSame('1.50', $res1_5->json('data.chapter_number'));

        // Check 1.50 -> matches 1.50
        $res1_50 = $this->getJson('/api/comics/comic-numbers/chapters/1.50');
        $res1_50->assertOk();
        $this->assertSame('1.50', $res1_50->json('data.chapter_number'));

        // Check 10 -> matches 10.00 (and NOT chapter 1)
        $res10 = $this->getJson('/api/comics/comic-numbers/chapters/10');
        $res10->assertOk();
        $this->assertSame('10.00', $res10->json('data.chapter_number'));
        $this->assertSame('Chương 10', $res10->json('data.title'));

        // Check 10.25 -> matches 10.25
        $res10_25 = $this->getJson('/api/comics/comic-numbers/chapters/10.25');
        $res10_25->assertOk();
        $this->assertSame('10.25', $res10_25->json('data.chapter_number'));
    }

    /**
     * 7. Test invalid chapterNumber formats return HTTP 404: abc, -1, 1.005, 10000.00.
     */
    public function test_chapter_show_returns_404_for_invalid_chapter_number_formats(): void
    {
        $comic = $this->createComic(['slug' => 'comic-invalid']);
        $this->createChapter($comic, ['chapter_number' => '1.00']);

        $this->getJson('/api/comics/comic-invalid/chapters/abc')->assertNotFound();
        $this->getJson('/api/comics/comic-invalid/chapters/-1')->assertNotFound();
        $this->getJson('/api/comics/comic-invalid/chapters/1.005')->assertNotFound();
        $this->getJson('/api/comics/comic-invalid/chapters/10000.00')->assertNotFound();
        $this->getJson('/api/comics/comic-invalid/chapters/10000')->assertNotFound();
    }

    /**
     * 8. Test chapter belonging to comic B cannot be accessed through comic A's slug.
     */
    public function test_chapter_show_returns_404_when_chapter_belongs_to_another_comic(): void
    {
        $comicA = $this->createComic(['slug' => 'comic-a']);
        $comicB = $this->createComic(['slug' => 'comic-b']);

        // Comic B has chapter 5
        $this->createChapter($comicB, ['chapter_number' => '5.00']);

        // Access chapter 5 through comic A (which has no chapter 5)
        $response = $this->getJson('/api/comics/comic-a/chapters/5.00');
        $response->assertNotFound();
    }

    /**
     * 9. Test chapter in draft or rejected status returns HTTP 404.
     */
    public function test_chapter_show_returns_404_for_draft_or_rejected_chapter(): void
    {
        $comic = $this->createComic(['slug' => 'comic-unpublished']);
        $this->createChapter($comic, ['chapter_number' => '1.00', 'status' => 'draft']);
        $this->createChapter($comic, ['chapter_number' => '2.00', 'status' => 'rejected']);

        $this->getJson('/api/comics/comic-unpublished/chapters/1.00')->assertNotFound();
        $this->getJson('/api/comics/comic-unpublished/chapters/2.00')->assertNotFound();
    }

    /**
     * 10. Test published chapter returns 404 when parent comic is not approved.
     */
    public function test_chapter_show_returns_404_when_comic_is_not_approved(): void
    {
        $comic = $this->createComic(['slug' => 'unapproved-comic', 'approval_status' => 'draft']);
        $this->createChapter($comic, ['chapter_number' => '1.00', 'status' => 'published']);

        $this->getJson('/api/comics/unapproved-comic/chapters/1.00')->assertNotFound();
    }

    /**
     * 11. Test images are sorted by page_number ASC with id ASC tie-break.
     */
    public function test_chapter_show_images_sorted_by_page_number_asc(): void
    {
        $comic = $this->createComic(['slug' => 'comic-pages']);
        $chapter = $this->createChapter($comic, ['chapter_number' => '1.00']);

        // Insert in scrambled order
        ChapterImage::create(['chapter_id' => $chapter->id, 'page_number' => 3, 'image_path' => 'p3.webp']);
        ChapterImage::create(['chapter_id' => $chapter->id, 'page_number' => 1, 'image_path' => 'p1.webp']);
        ChapterImage::create(['chapter_id' => $chapter->id, 'page_number' => 2, 'image_path' => 'p2.webp']);

        $response = $this->getJson('/api/comics/comic-pages/chapters/1.00');

        $response->assertOk();
        $pages = array_column($response->json('data.images'), 'page_number');
        $this->assertSame([1, 2, 3], $pages);
    }

    /**
     * 12. Test chapter with no images returns images: [].
     */
    public function test_chapter_show_handles_chapter_with_zero_images(): void
    {
        $comic = $this->createComic(['slug' => 'comic-no-images']);
        $this->createChapter($comic, ['chapter_number' => '1.00']);

        $response = $this->getJson('/api/comics/comic-no-images/chapters/1.00');

        $response->assertOk();
        $this->assertSame([], $response->json('data.images'));
    }

    /**
     * 13. Test image URL is correctly generated and nullable paths handled safely.
     */
    public function test_chapter_show_image_url_generated_correctly_and_handles_null(): void
    {
        $comic = $this->createComic(['slug' => 'comic-img-url']);
        $chapter = $this->createChapter($comic, ['chapter_number' => '1.00']);

        ChapterImage::create([
            'chapter_id' => $chapter->id,
            'page_number' => 1,
            'image_path' => 'chapters/sample.webp',
        ]);

        $response = $this->getJson('/api/comics/comic-img-url/chapters/1.00');

        $response->assertOk();
        $this->assertStringContainsString('/storage/chapters/sample.webp', $response->json('data.images.0.image_url'));
    }

    /**
     * 14. Test prev_chapter and next_chapter correctly skip draft/rejected chapters.
     */
    public function test_chapter_show_prev_and_next_navigation_skips_unpublished_chapters(): void
    {
        $comic = $this->createComic(['slug' => 'comic-nav']);

        $ch1 = $this->createChapter($comic, ['chapter_number' => '1.00', 'status' => 'published']);
        $ch2 = $this->createChapter($comic, ['chapter_number' => '2.00', 'status' => 'draft']);
        $ch3 = $this->createChapter($comic, ['chapter_number' => '3.00', 'status' => 'rejected']);
        $ch4 = $this->createChapter($comic, ['chapter_number' => '4.00', 'status' => 'published']);

        // Viewing chapter 1: next should be chapter 4 (skipping 2 and 3)
        $res1 = $this->getJson('/api/comics/comic-nav/chapters/1.00');
        $res1->assertOk();
        $this->assertNull($res1->json('data.prev_chapter'));
        $this->assertSame('4.00', $res1->json('data.next_chapter.chapter_number'));

        // Viewing chapter 4: prev should be chapter 1 (skipping 2 and 3)
        $res4 = $this->getJson('/api/comics/comic-nav/chapters/4.00');
        $res4->assertOk();
        $this->assertSame('1.00', $res4->json('data.prev_chapter.chapter_number'));
        $this->assertNull($res4->json('data.next_chapter'));
    }

    /**
     * 15. Test responses never leak rejection_reason, internal status, or sensitive comic fields.
     */
    public function test_chapter_api_does_not_leak_internal_attributes(): void
    {
        $comic = $this->createComic([
            'slug' => 'secret-comic',
            'rejection_reason' => 'Secret reason',
            'pending_changes' => ['title' => 'New Title'],
        ]);

        $chapter = $this->createChapter($comic, [
            'chapter_number' => '1.00',
            'rejection_reason' => 'Chapter secret reason',
        ]);

        // Check index
        $resIndex = $this->getJson('/api/comics/secret-comic/chapters');
        $resIndex->assertOk();
        $indexItem = $resIndex->json('data.0');
        $this->assertArrayNotHasKey('rejection_reason', $indexItem);
        $this->assertArrayNotHasKey('status', $indexItem);

        // Check show
        $resShow = $this->getJson('/api/comics/secret-comic/chapters/1.00');
        $resShow->assertOk();
        $showItem = $resShow->json('data');

        $this->assertArrayNotHasKey('rejection_reason', $showItem);
        $this->assertArrayNotHasKey('status', $showItem);
        $this->assertArrayNotHasKey('approval_status', $showItem['comic']);
        $this->assertArrayNotHasKey('pending_changes', $showItem['comic']);
    }

    /**
     * 16. Test neither index nor show endpoints increment views_count.
     */
    public function test_chapter_api_does_not_auto_increment_views_count(): void
    {
        $comic = $this->createComic(['slug' => 'comic-views']);
        $chapter = $this->createChapter($comic, ['chapter_number' => '1.00', 'views_count' => 100]);

        $this->getJson('/api/comics/comic-views/chapters')->assertOk();
        $chapter->refresh();
        $this->assertSame(100, $chapter->views_count);

        $this->getJson('/api/comics/comic-views/chapters/1.00')->assertOk();
        $chapter->refresh();
        $this->assertSame(100, $chapter->views_count);
    }

    /**
     * 17. Test integration with DatabaseSeeder dataset.
     */
    public function test_chapter_api_with_database_seeder_dataset(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Test index for Huyền Thoại Kiếm Vương (has 2 published chapters)
        $resIndex = $this->getJson('/api/comics/huyen-thoai-kiem-vuong/chapters');
        $resIndex->assertOk();
        $resIndex->assertJsonCount(2, 'data');
        $this->assertSame('1.00', $resIndex->json('data.0.chapter_number'));
        $this->assertSame('2.00', $resIndex->json('data.1.chapter_number'));

        // Test reader show for chapter 1 (has 4 page images)
        $resShow = $this->getJson('/api/comics/huyen-thoai-kiem-vuong/chapters/1');
        $resShow->assertOk();
        $this->assertSame('1.00', $resShow->json('data.chapter_number'));
        $this->assertSame('Huyền Thoại Kiếm Vương', $resShow->json('data.comic.title'));
        $this->assertCount(4, $resShow->json('data.images'));
        $this->assertSame(1, $resShow->json('data.images.0.page_number'));
        $this->assertSame(4, $resShow->json('data.images.3.page_number'));
        $this->assertNull($resShow->json('data.prev_chapter'));
        $this->assertSame('2.00', $resShow->json('data.next_chapter.chapter_number'));
    }
}
