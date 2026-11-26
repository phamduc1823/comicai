<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterImage;
use App\Models\Comic;
use App\Models\Genre;
use App\Models\User;
use App\Notifications\ComicReviewResultNotification;
use App\Notifications\ComicSubmittedForReviewNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminComicChapterReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $creator;
    protected User $otherCreator;
    protected Comic $comic;
    protected Genre $genre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->creator = User::factory()->create(['role' => 'creator', 'name' => 'Tác giả Minh', 'email' => 'minh@comicai.com', 'is_active' => true]);
        $this->otherCreator = User::factory()->create(['role' => 'creator', 'name' => 'Tác giả Khác', 'is_active' => true]);

        $this->genre = Genre::create(['name' => 'Hành Động', 'slug' => 'hanh-dong', 'is_active' => true]);

        $this->comic = Comic::create([
            'title' => 'Đại Chiến Võ Lâm',
            'slug' => 'dai-chien-vo-lam',
            'author_name' => 'Kim Dung',
            'user_id' => $this->creator->id,
            'cover_path' => 'covers/vo-lam.webp',
            'status' => 'ongoing',
            'approval_status' => 'draft',
            'is_vip' => false,
            'price' => 0,
        ]);
        $this->comic->genres()->attach($this->genre->id);
    }

    protected function createChapter(Comic $comic, string $number, string $status = 'draft', int $imagesCount = 2): Chapter
    {
        $chapter = Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => $number,
            'title' => "Hồi {$number}",
            'slug' => "hoi-{$number}",
            'status' => $status,
            'views_count' => 0,
        ]);

        for ($i = 1; $i <= $imagesCount; $i++) {
            ChapterImage::create([
                'chapter_id' => $chapter->id,
                'page_number' => $i,
                'image_path' => "chapters/{$chapter->id}/page_{$i}.webp",
            ]);
        }

        return $chapter;
    }

    /**
     * 1. Creator submits comic WITHOUT chapters -> comic pending, 1 consolidated notification to admin.
     */
    public function test_creator_can_submit_comic_without_chapters(): void
    {
        $response = $this->actingAs($this->creator, 'sanctum')
            ->postJson("/api/creator/comics/{$this->comic->id}/submit-review");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Gửi duyệt bộ truyện thành công.',
                'meta' => [
                    'submitted_chapters_count' => 0,
                ],
            ]);

        $this->assertEquals('pending', $this->comic->fresh()->approval_status);

        // Verify notification in Admin database
        $notif = $this->admin->notifications()->where('type', ComicSubmittedForReviewNotification::class)->first();
        $this->assertNotNull($notif);
        $this->assertEquals('comic_submitted_for_review', $notif->data['kind']);
        $this->assertEquals('Truyện "Đại Chiến Võ Lâm" cần được duyệt', $notif->data['body']);
    }

    /**
     * 2. Creator submits comic WITH multiple chapters -> comic & chapters pending, 1 consolidated notification.
     */
    public function test_creator_can_submit_comic_with_multiple_chapters_consolidated_notification(): void
    {
        $ch1 = $this->createChapter($this->comic, '1', 'draft', 3);
        $ch2 = $this->createChapter($this->comic, '2', 'rejected', 2);
        $ch3 = $this->createChapter($this->comic, '3', 'draft', 1);

        $response = $this->actingAs($this->creator, 'sanctum')
            ->postJson("/api/creator/comics/{$this->comic->id}/submit-review", [
                'chapter_ids' => [$ch1->id, $ch2->id],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Gửi duyệt bộ truyện cùng 2 chương thành công.',
                'meta' => [
                    'submitted_chapters_count' => 2,
                ],
            ]);

        $this->assertEquals('pending', $this->comic->fresh()->approval_status);
        $this->assertEquals('pending', $ch1->fresh()->status);
        $this->assertEquals('pending', $ch2->fresh()->status);
        $this->assertEquals('draft', $ch3->fresh()->status); // Not selected, remains draft

        // Single consolidated notification with count = 2
        $adminNotifications = $this->admin->notifications()->where('type', ComicSubmittedForReviewNotification::class)->get();
        $this->assertCount(1, $adminNotifications);
        $this->assertEquals('Truyện "Đại Chiến Võ Lâm" có 2 chương cần duyệt', $adminNotifications->first()->data['body']);
    }

    /**
     * 3. Cannot submit chapters that do not exist or belong to another comic.
     */
    public function test_creator_cannot_submit_invalid_or_alien_chapters(): void
    {
        // Alien comic & chapter
        $alienComic = Comic::create([
            'title' => 'Alien Comic',
            'slug' => 'alien-comic',
            'author_name' => 'Khác',
            'user_id' => $this->otherCreator->id,
            'cover_path' => 'covers/alien.webp',
            'status' => 'ongoing',
            'approval_status' => 'draft',
        ]);
        $alienChapter = $this->createChapter($alienComic, '1', 'draft', 2);

        // Case A: Nonexistent chapter ID
        $resA = $this->actingAs($this->creator, 'sanctum')
            ->postJson("/api/creator/comics/{$this->comic->id}/submit-review", [
                'chapter_ids' => [999999],
            ]);
        $resA->assertStatus(422)
            ->assertJsonFragment(['message' => 'Một hoặc nhiều chương được chọn không tồn tại.']);

        // Case B: Chapter belongs to another comic
        $resB = $this->actingAs($this->creator, 'sanctum')
            ->postJson("/api/creator/comics/{$this->comic->id}/submit-review", [
                'chapter_ids' => [$alienChapter->id],
            ]);
        $resB->assertStatus(422)
            ->assertJsonFragment(['message' => 'Một hoặc nhiều chương không thuộc bộ truyện này.']);
    }

    /**
     * 4. Cannot submit chapters in invalid status or with zero images.
     */
    public function test_creator_cannot_submit_published_or_imageless_chapters(): void
    {
        $publishedChap = $this->createChapter($this->comic, '1', 'published', 2);
        $emptyChap = $this->createChapter($this->comic, '2', 'draft', 0); // 0 images

        // Case A: already published
        $resA = $this->actingAs($this->creator, 'sanctum')
            ->postJson("/api/creator/comics/{$this->comic->id}/submit-review", [
                'chapter_ids' => [$publishedChap->id],
            ]);
        $resA->assertStatus(422);

        // Case B: no images
        $resB = $this->actingAs($this->creator, 'sanctum')
            ->postJson("/api/creator/comics/{$this->comic->id}/submit-review", [
                'chapter_ids' => [$emptyChap->id],
            ]);
        $resB->assertStatus(422)
            ->assertJsonFragment(['message' => 'Chương #2 phải có ít nhất một ảnh trước khi gửi duyệt.']);
    }

    /**
     * 5. Admin can approve comic WITHOUT chapters -> comic approved, creator receives notification.
     */
    public function test_admin_can_approve_comic_without_chapters(): void
    {
        $this->comic->approval_status = 'pending';
        $this->comic->save();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/moderation/comics/{$this->comic->id}/approve");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Duyệt bộ truyện thành công.',
            ]);

        $this->assertEquals('approved', $this->comic->fresh()->approval_status);

        // Creator receives notification
        $creatorNotif = $this->creator->notifications()->where('type', ComicReviewResultNotification::class)->first();
        $this->assertNotNull($creatorNotif);
        $this->assertEquals('comic_review_result', $creatorNotif->data['kind']);
        $this->assertEquals('Truyện "Đại Chiến Võ Lâm" đã được duyệt.', $creatorNotif->data['body']);
    }

    /**
     * 6. Admin can approve comic WITH selected chapters -> comic approved & chapters published.
     */
    public function test_admin_can_approve_comic_with_selected_chapters(): void
    {
        $this->comic->approval_status = 'pending';
        $this->comic->save();
        $ch1 = $this->createChapter($this->comic, '1', 'pending', 2);
        $ch2 = $this->createChapter($this->comic, '2', 'pending', 3);
        $ch3 = $this->createChapter($this->comic, '3', 'draft', 2);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/moderation/comics/{$this->comic->id}/approve", [
                'chapter_ids' => [$ch1->id, $ch2->id],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Duyệt bộ truyện và 2 chương thành công.',
                'meta' => [
                    'approved_chapters_count' => 2,
                ],
            ]);

        $this->assertEquals('approved', $this->comic->fresh()->approval_status);
        $this->assertEquals('published', $ch1->fresh()->status);
        $this->assertEquals('published', $ch2->fresh()->status);
        $this->assertEquals('draft', $ch3->fresh()->status);

        // Consolidated notification sent to creator
        $creatorNotif = $this->creator->notifications()->where('type', ComicReviewResultNotification::class)->first();
        $this->assertNotNull($creatorNotif);
        $this->assertEquals('Truyện "Đại Chiến Võ Lâm" cùng 2 chương đã được duyệt và xuất bản.', $creatorNotif->data['body']);
    }

    /**
     * 7. Admin cannot approve chapters belonging to another comic.
     */
    public function test_admin_cannot_approve_alien_chapter(): void
    {
        $this->comic->approval_status = 'pending';
        $this->comic->save();

        $alienComic = Comic::create([
            'title' => 'Alien Comic',
            'slug' => 'alien-comic-2',
            'author_name' => 'Khác',
            'user_id' => $this->otherCreator->id,
            'cover_path' => 'covers/alien2.webp',
            'status' => 'ongoing',
        ]);
        $alienComic->approval_status = 'pending';
        $alienComic->save();
        $alienChapter = $this->createChapter($alienComic, '1', 'pending', 2);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/moderation/comics/{$this->comic->id}/approve", [
                'chapter_ids' => [$alienChapter->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Một hoặc nhiều chương không thuộc bộ truyện này.']);
    }

    /**
     * 8. Admin rejects comic -> rejection notification sent to creator with reason.
     */
    public function test_admin_can_reject_comic_and_creator_is_notified(): void
    {
        $this->comic->approval_status = 'pending';
        $this->comic->save();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/moderation/comics/{$this->comic->id}/reject", [
                'rejection_reason' => 'Ảnh bìa vi phạm thuần phong mỹ tục.',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('rejected', $this->comic->fresh()->approval_status);

        $creatorNotif = $this->creator->notifications()->where('type', ComicReviewResultNotification::class)->first();
        $this->assertNotNull($creatorNotif);
        $this->assertEquals('Truyện "Đại Chiến Võ Lâm" đã bị từ chối: Ảnh bìa vi phạm thuần phong mỹ tục.', $creatorNotif->data['body']);
    }
}
