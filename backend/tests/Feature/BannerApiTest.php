<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Comic;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BannerApiTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = User::create([
            'name' => 'Creator User',
            'email' => 'creator@example.com',
            'password' => 'secret123',
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

    private function createComic(string $slug, string $title, string $approvalStatus = 'approved'): Comic
    {
        $comic = Comic::create([
            'title' => $title,
            'slug' => $slug,
            'author_name' => 'Tác Giả Demo',
            'user_id' => $this->creator->id,
            'cover_path' => "placeholders/comics/{$slug}-cover.webp",
            'age_rating' => '13+',
            'status' => 'ongoing',
        ]);

        $comic->approval_status = $approvalStatus;
        $comic->approved_by = $this->admin->id;
        $comic->approved_at = now();
        $comic->save();

        return $comic;
    }

    /**
     * Test GET /api/banners returns active banners of approved comics ordered by sort_order.
     */
    public function test_banners_endpoint_returns_active_banners_of_approved_comics_sorted(): void
    {
        $comic1 = $this->createComic('truyen-mot', 'Truyện Một');
        $comic2 = $this->createComic('truyen-hai', 'Truyện Hai');

        Banner::create([
            'comic_id' => $comic1->id,
            'title_override' => 'Banner Truyện Một',
            'desktop_banner_path' => 'placeholders/banners/comic1-desktop.webp',
            'mobile_banner_path' => 'placeholders/banners/comic1-mobile.webp',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        Banner::create([
            'comic_id' => $comic2->id,
            'title_override' => 'Banner Truyện Hai',
            'desktop_banner_path' => 'placeholders/banners/comic2-desktop.webp',
            'mobile_banner_path' => null,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/banners');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        // Check exact whitelist structure
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title_override',
                    'desktop_banner_path',
                    'desktop_banner_url',
                    'mobile_banner_path',
                    'mobile_banner_url',
                    'sort_order',
                    'comic' => [
                        'id',
                        'title',
                        'slug',
                        'cover_path',
                        'cover_url',
                    ],
                ],
            ],
        ]);

        $data = $response->json('data');

        // Verify sorted by sort_order ASC (sort_order 1 before sort_order 2)
        $this->assertSame(1, $data[0]['sort_order']);
        $this->assertSame('Banner Truyện Hai', $data[0]['title_override']);
        $this->assertSame('truyen-hai', $data[0]['comic']['slug']);
        $this->assertNull($data[0]['mobile_banner_path']);
        $this->assertNull($data[0]['mobile_banner_url']);
        $this->assertStringContainsString('/storage/placeholders/banners/comic2-desktop.webp', $data[0]['desktop_banner_url']);

        $this->assertSame(2, $data[1]['sort_order']);
        $this->assertSame('Banner Truyện Một', $data[1]['title_override']);
        $this->assertSame('truyen-mot', $data[1]['comic']['slug']);
        $this->assertStringContainsString('/storage/placeholders/banners/comic1-mobile.webp', $data[1]['mobile_banner_url']);

        // Verify no sensitive internal fields leaked
        $this->assertArrayNotHasKey('approved_by', $data[0]);
        $this->assertArrayNotHasKey('approval_status', $data[0]);
        $this->assertArrayNotHasKey('approval_status', $data[0]['comic']);
        $this->assertArrayNotHasKey('rejection_reason', $data[0]['comic']);
        $this->assertArrayNotHasKey('pending_changes', $data[0]['comic']);
        $this->assertArrayNotHasKey('embedding', $data[0]['comic']);
        $this->assertArrayNotHasKey('is_active', $data[0]);
        $this->assertArrayNotHasKey('created_at', $data[0]);
    }

    /**
     * Test GET /api/banners excludes inactive banners.
     */
    public function test_banners_endpoint_excludes_inactive_banners(): void
    {
        $comic = $this->createComic('truyen-chuan', 'Truyện Chuẩn');

        Banner::create([
            'comic_id' => $comic->id,
            'title_override' => 'Banner Hoạt Động',
            'desktop_banner_path' => 'placeholders/banners/active.webp',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Banner::create([
            'comic_id' => $comic->id,
            'title_override' => 'Banner Đang Ẩn',
            'desktop_banner_path' => 'placeholders/banners/inactive.webp',
            'sort_order' => 2,
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/banners');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame('Banner Hoạt Động', $response->json('data.0.title_override'));
    }

    /**
     * Test GET /api/banners excludes banners linked to unapproved comics (draft, pending, rejected).
     */
    public function test_banners_endpoint_excludes_banners_for_unapproved_comics(): void
    {
        $draftComic = $this->createComic('truyen-nhap', 'Truyện Nháp', 'draft');
        $pendingComic = $this->createComic('truyen-cho', 'Truyện Đang Chờ Duyệt', 'pending');
        $rejectedComic = $this->createComic('truyen-tu-choi', 'Truyện Bị Từ Chối', 'rejected');

        Banner::create([
            'comic_id' => $draftComic->id,
            'title_override' => 'Banner Bản Nháp',
            'desktop_banner_path' => 'placeholders/banners/draft.webp',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Banner::create([
            'comic_id' => $pendingComic->id,
            'title_override' => 'Banner Đang Duyệt',
            'desktop_banner_path' => 'placeholders/banners/pending.webp',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        Banner::create([
            'comic_id' => $rejectedComic->id,
            'title_override' => 'Banner Bị Từ Chối',
            'desktop_banner_path' => 'placeholders/banners/rejected.webp',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/banners');

        $response->assertOk();
        $response->assertExactJson(['data' => []]);
    }

    /**
     * Test GET /api/banners with full database seeder dataset.
     */
    public function test_banners_endpoint_with_database_seeder(): void
    {
        $this->seed(DatabaseSeeder::class);

        $response = $this->getJson('/api/banners');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $data = $response->json('data');
        $this->assertSame(1, $data[0]['sort_order']);
        $this->assertSame('huyen-thoai-kiem-vuong', $data[0]['comic']['slug']);

        $this->assertSame(2, $data[1]['sort_order']);
        $this->assertSame('anh-sang-ban-mai', $data[1]['comic']['slug']);
    }
}
