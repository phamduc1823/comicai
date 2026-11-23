<?php

namespace Tests\Feature;

use App\Jobs\GenerateComicEmbeddingJob;
use App\Models\Comic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminModerationEmbeddingTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->creator = User::factory()->create(['role' => 'creator']);
    }

    public function test_approving_new_comic_dispatches_embedding_job_after_commit(): void
    {
        Queue::fake();

        $comic = Comic::forceCreate([
            'title' => 'Truyện Chờ Duyệt',
            'slug' => 'truyen-cho-duyet-' . uniqid(),
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/pending.jpg',
            'approval_status' => 'pending',
            'status' => 'ongoing',
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/approve");

        $response->assertStatus(200);

        Queue::assertPushed(GenerateComicEmbeddingJob::class, function ($job) use ($comic) {
            return $job->comicId === $comic->id;
        });
    }

    public function test_approving_comic_changes_dispatches_embedding_job_after_commit(): void
    {
        Queue::fake();

        $comic = Comic::forceCreate([
            'title' => 'Truyện Đã Duyệt',
            'slug' => 'truyen-da-duyet-' . uniqid(),
            'author_name' => 'Tác Giả Cũ',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/original.jpg',
            'approval_status' => 'approved',
            'status' => 'ongoing',
            'pending_changes' => [
                'title' => 'Truyện Sau Khi Cập Nhật',
                'description' => 'Mô tả mới đã chỉnh sửa.',
            ],
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/approve-changes");

        $response->assertStatus(200);

        Queue::assertPushed(GenerateComicEmbeddingJob::class, function ($job) use ($comic) {
            return $job->comicId === $comic->id;
        });
    }

    public function test_rejecting_comic_does_not_dispatch_embedding_job(): void
    {
        Queue::fake();

        $comic = Comic::forceCreate([
            'title' => 'Truyện Bị Từ Chối',
            'slug' => 'truyen-bi-tu-choi-' . uniqid(),
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/pending.jpg',
            'approval_status' => 'pending',
            'status' => 'ongoing',
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/reject", [
            'rejection_reason' => 'Nội dung không phù hợp tiêu chuẩn kiểm duyệt.',
        ]);

        $response->assertStatus(200);

        Queue::assertNotPushed(GenerateComicEmbeddingJob::class);
    }

    public function test_rejecting_comic_changes_does_not_dispatch_embedding_job(): void
    {
        Queue::fake();

        $comic = Comic::forceCreate([
            'title' => 'Truyện Giữ Nguyên',
            'slug' => 'truyen-giu-nguyen-' . uniqid(),
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/approved.jpg',
            'approval_status' => 'approved',
            'status' => 'ongoing',
            'pending_changes' => [
                'title' => 'Tên Vi Phạm',
            ],
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/reject-changes", [
            'rejection_reason' => 'Tên đề xuất vi phạm quy định.',
        ]);

        $response->assertStatus(200);

        Queue::assertNotPushed(GenerateComicEmbeddingJob::class);
    }

    public function test_creator_submitting_draft_does_not_dispatch_embedding_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->creator)->postJson('/api/creator/comics', [
            'title' => 'Truyện Mới Của Creator',
            'author_name' => 'Bút Danh Mới',
            'description' => 'Mô tả bản thảo.',
            'age_rating' => '13+',
            'status' => 'ongoing',
        ]);

        Queue::assertNotPushed(GenerateComicEmbeddingJob::class);
    }
}
