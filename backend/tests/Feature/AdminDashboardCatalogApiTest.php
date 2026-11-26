<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $creator;
    protected Comic $comic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->creator = User::factory()->create(['role' => 'creator']);

        $this->comic = Comic::create([
            'title' => 'Võ Thần Đại Lục',
            'slug' => 'vo-than-dai-luc',
            'author_name' => 'Tác giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'covers/test.webp',
            'status' => 'ongoing',
            'approval_status' => 'approved',
            'is_vip' => false,
            'price' => 0,
        ]);

        Chapter::create([
            'comic_id' => $this->comic->id,
            'chapter_number' => 1,
            'title' => 'Chương mở đầu',
            'slug' => 'chuong-1',
            'status' => 'published',
        ]);
    }

    public function test_admin_can_fetch_dashboard_stats(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/stats');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'data' => [
                    'users' => ['total', 'new_7_days', 'new_30_days', 'creators', 'active', 'locked'],
                    'queue' => ['pending_comics', 'pending_chapters', 'pending_creator_applications', 'pending_reports', 'total_pending'],
                    'catalog' => ['total_comics', 'approved_comics', 'draft_comics', 'rejected_comics', 'vip_comics', 'total_chapters', 'published_chapters'],
                    'finance' => ['coins_in_circulation', 'total_vip_purchases', 'active_vip_purchases', 'revoked_vip_purchases', 'vip_revenue_coins'],
                    'recent' => ['pending_comics', 'pending_reports', 'vip_purchases'],
                ],
            ]);
    }

    public function test_admin_can_manage_comic_catalog_and_update_vip_and_status(): void
    {
        // List comics
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/comics')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // Update status
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/comics/{$this->comic->id}/status", [
                'status' => 'completed',
                'is_featured' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.is_featured', true);

        // Update VIP
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/comics/{$this->comic->id}/vip", [
                'is_vip' => true,
                'price' => 200,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.is_vip', true)
            ->assertJsonPath('data.price', 200);

        $this->assertTrue((bool) $this->comic->fresh()->is_vip);
        $this->assertEquals(200, $this->comic->fresh()->price);
    }

    public function test_admin_can_list_and_unpublish_chapter(): void
    {
        $chapter = $this->comic->chapters()->first();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/chapters?comic_id={$this->comic->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/chapters/{$chapter->id}/status", [
                'status' => 'rejected',
                'rejection_reason' => 'Vi phạm bản quyền hình ảnh',
            ])
            ->assertStatus(200);

        $this->assertEquals('rejected', $chapter->fresh()->status);
        $this->assertEquals('Vi phạm bản quyền hình ảnh', $chapter->fresh()->rejection_reason);
    }
}
