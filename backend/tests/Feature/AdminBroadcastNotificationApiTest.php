<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SystemBroadcastNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminBroadcastNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $reader1;
    protected User $reader2;
    protected User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->reader1 = User::factory()->create(['role' => 'reader', 'is_active' => true]);
        $this->reader2 = User::factory()->create(['role' => 'reader', 'is_active' => true]);
        $this->creator = User::factory()->create(['role' => 'creator', 'is_active' => true]);
    }

    public function test_admin_can_broadcast_notification_to_all_users(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/notifications/broadcast', [
                'title' => 'Bảo trì hệ thống định kỳ',
                'message' => 'Hệ thống sẽ bảo trì từ 0h đến 2h sáng ngày mai.',
                'broadcast_type' => 'maintenance',
                'target_audience' => 'all',
                'action_url' => '/announcements',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.recipients_count', 4); // admin, reader1, reader2, creator

        // Verify notification stored in database for reader1
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->reader1->id,
            'type' => SystemBroadcastNotification::class,
        ]);
    }

    public function test_admin_can_broadcast_to_creators_only(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/notifications/broadcast', [
                'title' => 'Chính sách Creator mới',
                'message' => 'Cập nhật điều khoản tác quyền cho tác giả.',
                'broadcast_type' => 'announcement',
                'target_audience' => 'creators',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.recipients_count', 1);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->creator->id,
            'type' => SystemBroadcastNotification::class,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $this->reader1->id,
            'type' => SystemBroadcastNotification::class,
        ]);
    }

    public function test_admin_can_view_broadcasts_history(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/notifications/broadcasts')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }
}
