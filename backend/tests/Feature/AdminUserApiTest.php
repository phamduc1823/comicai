<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin1;
    protected User $admin2;
    protected User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin1 = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->admin2 = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->reader = User::factory()->create(['role' => 'reader', 'is_active' => true]);
    }

    public function test_admin_can_list_users_and_filter(): void
    {
        $response = $this->actingAs($this->admin1, 'sanctum')
            ->getJson('/api/admin/users?role=reader');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $items = $response->json('data');
        $this->assertNotEmpty($items);
        $this->assertEquals('reader', $items[0]['role']);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $response = $this->actingAs($this->admin1, 'sanctum')
            ->patchJson("/api/admin/users/{$this->admin1->id}/toggle-active");

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Bạn không thể tự khóa tài khoản của chính mình.');
    }

    public function test_cannot_deactivate_last_active_admin(): void
    {
        // Deactivate admin2 first
        $this->actingAs($this->admin1, 'sanctum')
            ->patchJson("/api/admin/users/{$this->admin2->id}/toggle-active")
            ->assertStatus(200);

        // Now admin1 is the only active admin. Another admin cannot lock admin1.
        $this->actingAs($this->admin2, 'sanctum')
            ->patchJson("/api/admin/users/{$this->admin1->id}/toggle-active")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Không thể khóa tài khoản Administrator đang hoạt động cuối cùng của hệ thống.');
    }

    public function test_admin_can_toggle_reader_active_status(): void
    {
        $response = $this->actingAs($this->admin1, 'sanctum')
            ->patchJson("/api/admin/users/{$this->reader->id}/toggle-active");

        $response->assertStatus(200);
        $this->assertFalse((bool) $this->reader->fresh()->is_active);

        // Toggle back to active
        $this->actingAs($this->admin1, 'sanctum')
            ->patchJson("/api/admin/users/{$this->reader->id}/toggle-active")
            ->assertStatus(200);

        $this->assertTrue((bool) $this->reader->fresh()->is_active);
    }

    public function test_admin_cannot_demote_self_from_admin(): void
    {
        $response = $this->actingAs($this->admin1, 'sanctum')
            ->patchJson("/api/admin/users/{$this->admin1->id}/role", [
                'role' => 'reader',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Bạn không thể tự hạ quyền Administrator của chính mình.');
    }

    public function test_admin_can_promote_reader_to_creator(): void
    {
        $response = $this->actingAs($this->admin1, 'sanctum')
            ->patchJson("/api/admin/users/{$this->reader->id}/role", [
                'role' => 'creator',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertEquals('creator', $this->reader->fresh()->role);
        $this->assertNotNull($this->reader->fresh()->creatorProfile);
    }
}
