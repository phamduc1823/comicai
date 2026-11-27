<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterImage;
use App\Models\Comic;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminComicChapterManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $creator;
    protected User $reader;
    protected Comic $comic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->creator = User::factory()->create(['role' => 'creator', 'name' => 'Tác giả Phong', 'email' => 'phong@comicai.com', 'is_active' => true]);
        $this->reader = User::factory()->create(['role' => 'reader', 'is_active' => true]);

        $this->comic = Comic::create([
            'title' => 'Huyền Thoại Kiếm Khách',
            'slug' => 'huyen-thoai-kiem-khach',
            'author_name' => 'Kim Dung',
            'user_id' => $this->creator->id,
            'cover_path' => 'covers/kiemkhach.webp',
            'status' => 'ongoing',
            'approval_status' => 'approved',
            'is_vip' => true,
            'price' => 150,
            'views_count' => 1200,
            'follows_count' => 340,
        ]);
    }

    public function test_admin_can_list_comics_with_pagination_and_filters(): void
    {
        $genre = Genre::create(['name' => 'Tiên Hiệp', 'slug' => 'tien-hiep', 'is_active' => true]);
        $this->comic->genres()->attach($genre->id);

        // Another comic with different creator
        $otherCreator = User::factory()->create(['role' => 'creator', 'name' => 'Bút Chẳng Tà', 'email' => 'but@comicai.com']);
        $comic2 = Comic::create([
            'title' => 'Đại Vũ Trụ',
            'slug' => 'dai-vu-tru',
            'author_name' => 'Lưu Cừu',
            'user_id' => $otherCreator->id,
            'cover_path' => 'covers/vutru.webp',
            'status' => 'completed',
            'approval_status' => 'draft',
            'is_vip' => false,
            'price' => 0,
        ]);

        // 1. Basic index
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/comics');
        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data');

        // 2. Search by title
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/comics?q=Vũ+Trụ');
        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $comic2->id);

        // 3. Filter by creator
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/comics?creator=Phong');
        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->comic->id);

        // 4. Filter by VIP
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/comics?is_vip=1');
        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->comic->id);

        // 5. Filter by genre
        $res = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/comics?genre_id={$genre->id}");
        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->comic->id);
    }

    public function test_admin_can_view_comic_details_without_loading_all_chapters(): void
    {
        // Create 5 chapters
        for ($i = 1; $i <= 5; $i++) {
            Chapter::create([
                'comic_id' => $this->comic->id,
                'chapter_number' => $i,
                'title' => "Chương {$i}",
                'slug' => "chuong-{$i}",
                'status' => 'published',
            ]);
        }

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/comics/{$this->comic->id}");

        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $this->comic->id)
            ->assertJsonPath('data.title', 'Huyền Thoại Kiếm Khách')
            ->assertJsonPath('data.chapters_count', 5);

        // Verify chapters relation is NOT loaded inside comic detail payload to support large datasets
        $this->assertArrayNotHasKey('chapters', $res->json('data'));
    }

    public function test_admin_can_list_chapters_under_comic_with_pagination(): void
    {
        // Seed 25 chapters
        for ($i = 1; $i <= 25; $i++) {
            Chapter::create([
                'comic_id' => $this->comic->id,
                'chapter_number' => $i,
                'title' => "Chương kiếm hiệp {$i}",
                'slug' => "chuong-kiem-hiep-{$i}",
                'status' => $i <= 20 ? 'published' : 'draft',
            ]);
        }

        // Page 1 (default 20 per page)
        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/comics/{$this->comic->id}/chapters");

        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('comic.id', $this->comic->id)
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 25);

        // Page 2
        $resPage2 = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/comics/{$this->comic->id}/chapters?page=2");

        $resPage2->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);

        // Filter by status=draft
        $resDraft = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/comics/{$this->comic->id}/chapters?status=draft");

        $resDraft->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 5);

        // Search by chapter number
        $resSearch = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/comics/{$this->comic->id}/chapters?q=25");

        $resSearch->assertStatus(200)
            ->assertJsonCount(1, 'data');
        $this->assertEquals(25, (int) $resSearch->json('data.0.chapter_number'));
    }

    public function test_cannot_access_chapters_of_nonexistent_comic(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/comics/999999/chapters')
            ->assertStatus(404);
    }

    public function test_admin_can_view_chapter_detail_under_comic_context(): void
    {
        $chap = Chapter::create([
            'comic_id' => $this->comic->id,
            'chapter_number' => 1,
            'title' => 'Khởi Đầu Hành Trình',
            'slug' => 'khoi-dau-hanh-trinh',
            'status' => 'published',
        ]);

        ChapterImage::create([
            'chapter_id' => $chap->id,
            'page_number' => 1,
            'image_path' => 'chapters/1/p1.webp',
        ]);
        ChapterImage::create([
            'chapter_id' => $chap->id,
            'page_number' => 2,
            'image_path' => 'chapters/1/p2.webp',
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/comics/{$this->comic->id}/chapters/{$chap->id}");

        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('comic.id', $this->comic->id)
            ->assertJsonPath('data.id', $chap->id)
            ->assertJsonPath('data.images_count', 2)
            ->assertJsonCount(2, 'data.images');
    }

    public function test_admin_cannot_access_chapter_belonging_to_another_comic(): void
    {
        $otherComic = Comic::create([
            'title' => 'Truyện Khác',
            'slug' => 'truyen-khac',
            'author_name' => 'Tác giả khác',
            'user_id' => $this->creator->id,
            'cover_path' => 'covers/other.webp',
            'status' => 'ongoing',
            'approval_status' => 'approved',
        ]);

        $otherChap = Chapter::create([
            'comic_id' => $otherComic->id,
            'chapter_number' => 1,
            'title' => 'Chương của truyện khác',
            'slug' => 'chuong-khac',
            'status' => 'published',
        ]);

        // Trying to access otherChap through comic A's URL must return 404
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/comics/{$this->comic->id}/chapters/{$otherChap->id}")
            ->assertStatus(404);

        // Trying to update otherChap through comic A's URL must return 404
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/comics/{$this->comic->id}/chapters/{$otherChap->id}/status", [
                'status' => 'rejected',
            ])
            ->assertStatus(404);
    }

    public function test_admin_can_update_chapter_status_under_comic_context(): void
    {
        $chap = Chapter::create([
            'comic_id' => $this->comic->id,
            'chapter_number' => 3,
            'title' => 'Trận Chiến Quyết Định',
            'slug' => 'tran-chien-quyet-dinh',
            'status' => 'pending',
        ]);

        // Reject chapter
        $resReject = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/comics/{$this->comic->id}/chapters/{$chap->id}/status", [
                'status' => 'rejected',
                'rejection_reason' => 'Trang 5 bị lỗi hình ảnh, vui lòng đăng lại',
            ]);

        $resReject->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertEquals('rejected', $chap->fresh()->status);
        $this->assertEquals('Trang 5 bị lỗi hình ảnh, vui lòng đăng lại', $chap->fresh()->rejection_reason);

        // Publish chapter
        $resPublish = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/comics/{$this->comic->id}/chapters/{$chap->id}/status", [
                'status' => 'published',
            ]);

        $resPublish->assertStatus(200)
            ->assertJsonPath('data.status', 'published');

        $this->assertEquals('published', $chap->fresh()->status);
        $this->assertNull($chap->fresh()->rejection_reason);
        $this->assertEquals($this->admin->id, $chap->fresh()->approved_by);
    }

    public function test_unauthorized_or_non_admin_cannot_access_endpoints(): void
    {
        // Unauthenticated
        $this->getJson('/api/admin/comics')->assertStatus(401);
        $this->getJson("/api/admin/comics/{$this->comic->id}")->assertStatus(401);
        $this->getJson("/api/admin/comics/{$this->comic->id}/chapters")->assertStatus(401);

        // Regular reader
        $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/admin/comics')
            ->assertStatus(403);

        $this->actingAs($this->reader, 'sanctum')
            ->getJson("/api/admin/comics/{$this->comic->id}")
            ->assertStatus(403);

        $this->actingAs($this->reader, 'sanctum')
            ->getJson("/api/admin/comics/{$this->comic->id}/chapters")
            ->assertStatus(403);
    }

    public function test_admin_can_toggle_featured_status(): void
    {
        // Initially not featured
        $this->assertFalse((bool) $this->comic->is_featured);

        // Admin marks as featured
        $res = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/comics/{$this->comic->id}/featured", [
                'is_featured' => true,
            ]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.is_featured', true);
        $this->assertTrue((bool) $this->comic->fresh()->is_featured);

        // Admin unmarks featured
        $resUnmark = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/comics/{$this->comic->id}/featured", [
                'is_featured' => false,
            ]);

        $resUnmark->assertStatus(200);
        $resUnmark->assertJsonPath('data.is_featured', false);
        $this->assertFalse((bool) $this->comic->fresh()->is_featured);
    }

    public function test_non_admin_cannot_toggle_featured_status(): void
    {
        // Unauthenticated
        $this->patchJson("/api/admin/comics/{$this->comic->id}/featured", [
            'is_featured' => true,
        ])->assertStatus(401);

        // Regular reader
        $this->actingAs($this->reader, 'sanctum')
            ->patchJson("/api/admin/comics/{$this->comic->id}/featured", [
                'is_featured' => true,
            ])->assertStatus(403);
    }
}
