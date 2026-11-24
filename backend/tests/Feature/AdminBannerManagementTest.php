<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Comic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminBannerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $reader;
    protected User $creator;
    protected Comic $approvedComic;
    protected Comic $draftComic;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->reader = User::factory()->create(['role' => 'reader']);
        $this->creator = User::factory()->create(['role' => 'creator']);

        $this->approvedComic = Comic::create([
            'title' => 'Truyện Đã Duyệt',
            'slug' => 'truyen-da-duyet',
            'author_name' => 'Tác Giả A',
            'user_id' => $this->creator->id,
            'cover_path' => 'covers/approved.webp',
            'status' => 'ongoing',
        ]);
        $this->approvedComic->approval_status = 'approved';
        $this->approvedComic->save();

        $this->draftComic = Comic::create([
            'title' => 'Truyện Chưa Duyệt',
            'slug' => 'truyen-chua-duyet',
            'author_name' => 'Tác Giả B',
            'user_id' => $this->creator->id,
            'cover_path' => 'covers/draft.webp',
            'approval_status' => 'draft',
            'status' => 'ongoing',
        ]);
    }

    public function test_guest_cannot_access_admin_banners(): void
    {
        $response = $this->getJson('/api/admin/banners');
        $response->assertStatus(401);
    }

    public function test_non_admin_cannot_access_admin_banners(): void
    {
        $response = $this->actingAs($this->reader, 'sanctum')->getJson('/api/admin/banners');
        $response->assertStatus(403);

        $responseCreator = $this->actingAs($this->creator, 'sanctum')->getJson('/api/admin/banners');
        $responseCreator->assertStatus(403);
    }

    public function test_admin_can_list_all_banners_including_inactive(): void
    {
        Banner::create([
            'comic_id' => $this->approvedComic->id,
            'title_override' => 'Banner Active',
            'desktop_banner_path' => 'banners/desktop/sample1.webp',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Banner::create([
            'comic_id' => $this->approvedComic->id,
            'title_override' => 'Banner Inactive',
            'desktop_banner_path' => 'banners/desktop/sample2.webp',
            'is_active' => false,
            'sort_order' => 2,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/banners');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['title_override' => 'Banner Active', 'is_active' => true]);
        $response->assertJsonFragment(['title_override' => 'Banner Inactive', 'is_active' => false]);
    }

    public function test_admin_can_create_banner_with_valid_image(): void
    {
        $desktopImage = UploadedFile::fake()->image('desktop.webp', 1920, 600);
        $mobileImage = UploadedFile::fake()->image('mobile.webp', 640, 800);

        $payload = [
            'comic_id' => $this->approvedComic->id,
            'title_override' => 'Siêu Phẩm Mới',
            'desktop_banner' => $desktopImage,
            'mobile_banner' => $mobileImage,
            'sort_order' => 5,
            'is_active' => 1,
        ];

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/banners', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.title_override', 'Siêu Phẩm Mới');
        $response->assertJsonPath('data.sort_order', 5);
        $response->assertJsonPath('data.is_active', true);

        $created = Banner::where('title_override', 'Siêu Phẩm Mới')->first();
        $this->assertNotNull($created);
        $this->assertTrue(Storage::disk('public')->exists($created->desktop_banner_path));
        $this->assertTrue(Storage::disk('public')->exists($created->mobile_banner_path));
    }

    public function test_cannot_create_banner_for_unapproved_comic(): void
    {
        $desktopImage = UploadedFile::fake()->image('desktop.webp', 1920, 600);

        $payload = [
            'comic_id' => $this->draftComic->id,
            'desktop_banner' => $desktopImage,
        ];

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/banners', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['comic_id']);
    }

    public function test_cannot_create_banner_with_invalid_file_type(): void
    {
        $invalidFile = UploadedFile::fake()->create('malicious.pdf', 100, 'application/pdf');

        $payload = [
            'comic_id' => $this->approvedComic->id,
            'desktop_banner' => $invalidFile,
        ];

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/banners', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['desktop_banner']);
    }

    public function test_admin_can_update_banner_metadata_and_replace_image(): void
    {
        $oldFile = UploadedFile::fake()->image('old.webp');
        $oldPath = $oldFile->storeAs('banners/desktop', 'old_file.webp', 'public');

        $banner = Banner::create([
            'comic_id' => $this->approvedComic->id,
            'title_override' => 'Old Title',
            'desktop_banner_path' => $oldPath,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $newDesktop = UploadedFile::fake()->image('new.webp');

        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/banners/{$banner->id}", [
            'title_override' => 'Updated Title',
            'sort_order' => 10,
            'desktop_banner' => $newDesktop,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title_override', 'Updated Title');
        $response->assertJsonPath('data.sort_order', 10);

        $banner->refresh();
        $this->assertEquals('Updated Title', $banner->title_override);
        $this->assertEquals(10, $banner->sort_order);
        $this->assertTrue(Storage::disk('public')->exists($banner->desktop_banner_path));
        // Old image file should be cleaned up
        $this->assertFalse(Storage::disk('public')->exists($oldPath));
    }

    public function test_admin_can_toggle_banner_active_status(): void
    {
        $banner = Banner::create([
            'comic_id' => $this->approvedComic->id,
            'desktop_banner_path' => 'banners/desktop/active.webp',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->patchJson("/api/admin/banners/{$banner->id}/status");

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_active', false);
        $this->assertFalse($banner->fresh()->is_active);

        // Toggle back
        $response2 = $this->actingAs($this->admin, 'sanctum')->patchJson("/api/admin/banners/{$banner->id}/status", [
            'is_active' => true,
        ]);
        $response2->assertStatus(200);
        $response2->assertJsonPath('data.is_active', true);
        $this->assertTrue($banner->fresh()->is_active);
    }

    public function test_admin_can_delete_banner_and_cleanup_file(): void
    {
        $fakeFile = UploadedFile::fake()->image('delete_me.webp');
        $path = $fakeFile->storeAs('banners/desktop', 'to_delete.webp', 'public');

        $banner = Banner::create([
            'comic_id' => $this->approvedComic->id,
            'desktop_banner_path' => $path,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->assertTrue(Storage::disk('public')->exists($path));

        $response = $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/banners/{$banner->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('banners', ['id' => $banner->id]);
        $this->assertFalse(Storage::disk('public')->exists($path));
    }

    public function test_admin_can_reorder_banners(): void
    {
        $b1 = Banner::create([
            'comic_id' => $this->approvedComic->id,
            'desktop_banner_path' => 'banners/desktop/b1.webp',
            'sort_order' => 1,
        ]);
        $b2 = Banner::create([
            'comic_id' => $this->approvedComic->id,
            'desktop_banner_path' => 'banners/desktop/b2.webp',
            'sort_order' => 2,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->patchJson('/api/admin/banners/reorder', [
            'orders' => [
                ['id' => $b1->id, 'sort_order' => 5],
                ['id' => $b2->id, 'sort_order' => 0],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertEquals(5, $b1->fresh()->sort_order);
        $this->assertEquals(0, $b2->fresh()->sort_order);
    }

    public function test_cache_is_flushed_when_banner_is_modified(): void
    {
        Cache::put(Banner::CACHE_KEY, ['stale_data'], Banner::CACHE_TTL);
        $this->assertTrue(Cache::has(Banner::CACHE_KEY));

        $desktopImage = UploadedFile::fake()->image('cache_test.webp');
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/banners', [
            'comic_id' => $this->approvedComic->id,
            'desktop_banner' => $desktopImage,
        ]);

        // Cache should be flushed
        $this->assertFalse(Cache::has(Banner::CACHE_KEY));
    }
}
