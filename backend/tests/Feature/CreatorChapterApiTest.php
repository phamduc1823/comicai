<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterImage;
use App\Models\Comic;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CreatorChapterApiTest extends TestCase
{
    use RefreshDatabase;

    private User $creatorA;
    private User $creatorB;
    private User $admin;
    private User $reader;

    private Comic $comicA;
    private Comic $comicB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->creatorA = User::create([
            'name' => 'Creator A',
            'email' => 'creatorA@example.com',
            'password' => 'Password123!',
        ]);
        $this->creatorA->role = 'creator';
        $this->creatorA->save();

        $this->creatorB = User::create([
            'name' => 'Creator B',
            'email' => 'creatorB@example.com',
            'password' => 'Password123!',
        ]);
        $this->creatorB->role = 'creator';
        $this->creatorB->save();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'Password123!',
        ]);
        $this->admin->role = 'admin';
        $this->admin->save();

        $this->reader = User::create([
            'name' => 'Reader User',
            'email' => 'reader@example.com',
            'password' => 'Password123!',
        ]);

        $genre = Genre::create([
            'name' => 'Hành Động',
            'slug' => 'hanh-dong',
            'is_active' => true,
        ]);

        // Comic of Creator A (Approved)
        $this->comicA = Comic::create([
            'title' => 'Comic A',
            'slug' => 'comic-a',
            'author_name' => 'Author A',
            'user_id' => $this->creatorA->id,
            'cover_path' => 'comics/covers/a.webp',
        ]);
        $this->comicA->approval_status = 'approved';
        $this->comicA->save();
        $this->comicA->genres()->sync([$genre->id]);

        // Comic of Creator B (Draft)
        $this->comicB = Comic::create([
            'title' => 'Comic B',
            'slug' => 'comic-b',
            'author_name' => 'Author B',
            'user_id' => $this->creatorB->id,
            'cover_path' => 'comics/covers/b.webp',
        ]);
        $this->comicB->approval_status = 'draft';
        $this->comicB->save();
    }

    /**
     * 1. Unauthenticated user cannot access creator chapter endpoints.
     */
    public function test_unauthenticated_user_cannot_access_creator_chapter_api(): void
    {
        $this->getJson("/api/creator/comics/{$this->comicA->id}/chapters")->assertStatus(401);
        $this->getJson("/api/creator/chapters/1")->assertStatus(401);
    }

    /**
     * 2. Reader role cannot access creator chapter endpoints (HTTP 403 Forbidden).
     */
    public function test_reader_cannot_access_creator_chapter_api(): void
    {
        $this->actingAs($this->reader)->getJson("/api/creator/comics/{$this->comicA->id}/chapters")->assertStatus(403);
        $this->actingAs($this->reader)->postJson("/api/creator/comics/{$this->comicA->id}/chapters", [
            'chapter_number' => 1,
        ])->assertStatus(403);
    }

    /**
     * 3. Creator A cannot view or manage chapters belonging to Creator B's comic.
     */
    public function test_creator_cannot_view_or_manage_chapters_of_other_creators_comic(): void
    {
        $chapterB = Chapter::create([
            'comic_id' => $this->comicB->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1 của B',
            'slug' => 'chuong-1-cua-b',
            'status' => 'draft',
        ]);

        $imageB = ChapterImage::create([
            'chapter_id' => $chapterB->id,
            'page_number' => 1,
            'image_path' => 'chapters/' . $chapterB->id . '/page_1.webp',
        ]);

        // Creator A tries to list B's chapters
        $this->actingAs($this->creatorA)->getJson("/api/creator/comics/{$this->comicB->id}/chapters")->assertStatus(403);

        // Creator A tries to create a chapter under B's comic
        $this->actingAs($this->creatorA)->postJson("/api/creator/comics/{$this->comicB->id}/chapters", [
            'chapter_number' => 2,
        ])->assertStatus(403);

        // Creator A tries to view B's chapter
        $this->actingAs($this->creatorA)->getJson("/api/creator/chapters/{$chapterB->id}")->assertStatus(403);

        // Creator A tries to update B's chapter
        $this->actingAs($this->creatorA)->putJson("/api/creator/chapters/{$chapterB->id}", [
            'title' => 'Hacked Chapter',
        ])->assertStatus(403);

        // Creator A tries to upload an image to B's chapter
        $file = UploadedFile::fake()->image('page.webp', 600, 800);
        $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapterB->id}/images", [
            'image' => $file,
        ])->assertStatus(403);

        // Creator A tries to delete B's chapter image
        $this->actingAs($this->creatorA)->deleteJson("/api/creator/chapters/{$chapterB->id}/images/{$imageB->id}")->assertStatus(403);
    }

    /**
     * 4. Admin has system-wide management access across all comics and chapters.
     */
    public function test_admin_has_system_wide_access_to_creator_chapters(): void
    {
        $chapter = Chapter::create([
            'comic_id' => $this->comicB->id,
            'chapter_number' => '1.00',
            'title' => 'Chương của B',
            'slug' => 'chuong-cua-b',
            'status' => 'draft',
        ]);

        $res = $this->actingAs($this->admin)->getJson("/api/creator/comics/{$this->comicB->id}/chapters");
        $res->assertStatus(200);
        $res->assertJsonCount(1, 'data');

        $resShow = $this->actingAs($this->admin)->getJson("/api/creator/chapters/{$chapter->id}");
        $resShow->assertStatus(200);
        $resShow->assertJsonPath('data.title', 'Chương của B');
    }

    /**
     * 5. Creator can create a new chapter in draft state with decimal chapter number.
     */
    public function test_creator_can_create_chapter_in_draft_state(): void
    {
        $payload = [
            'chapter_number' => 1.5,
            'title' => 'Khởi Đầu Gian Nan',
            // Malicious fields attempting state bypass or spoofing:
            'status' => 'published',
            'views_count' => 9999,
            'rejection_reason' => 'none',
        ];

        $response = $this->actingAs($this->creatorA)->postJson("/api/creator/comics/{$this->comicA->id}/chapters", $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Tạo chương mới thành công.',
            'data' => [
                'chapter_number' => '1.50',
                'title' => 'Khởi Đầu Gian Nan',
                'status' => 'draft', // Strictly forced to draft
                'views_count' => 0,  // Protected
            ],
        ]);

        $chapterId = $response->json('data.id');
        $chapter = Chapter::findOrFail($chapterId);

        $this->assertSame('1.50', (string) $chapter->chapter_number);
        $this->assertSame('draft', $chapter->status);
        $this->assertSame(0, $chapter->views_count);
        $this->assertNull($chapter->rejection_reason);
        $this->assertTrue(str_starts_with($chapter->slug, 'chuong-1.5-'));
    }

    /**
     * 6. Duplicate chapter number in the same comic is rejected with HTTP 422.
     */
    public function test_duplicate_chapter_number_in_same_comic_is_rejected(): void
    {
        Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
            'status' => 'draft',
        ]);

        // Attempt to create chapter 1 or 1.00 again in Comic A -> HTTP 422
        $resDuplicate = $this->actingAs($this->creatorA)->postJson("/api/creator/comics/{$this->comicA->id}/chapters", [
            'chapter_number' => 1,
            'title' => 'Chương 1 Trùng',
        ]);
        $resDuplicate->assertStatus(422);
        $resDuplicate->assertJsonValidationErrors('chapter_number');

        // Creating chapter 1 in Comic B by Creator B is allowed
        $resComicB = $this->actingAs($this->creatorB)->postJson("/api/creator/comics/{$this->comicB->id}/chapters", [
            'chapter_number' => 1,
            'title' => 'Chương 1 Comic B',
        ]);
        $resComicB->assertStatus(201);
    }

    /**
     * 7. Uploading Webtoon images: validates MIME, extension, size, and cleans up on failure.
     */
    public function test_upload_webtoon_images_validation(): void
    {
        $chapter = Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
            'status' => 'draft',
        ]);

        // A. Reject .php
        $maliciousPhp = UploadedFile::fake()->create('shell.php', 100, 'application/x-php');
        $resPhp = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/images", [
            'images' => [$maliciousPhp],
        ]);
        $resPhp->assertStatus(422);
        $resPhp->assertJsonValidationErrors('images.0');

        // B. Reject .svg
        $maliciousSvg = UploadedFile::fake()->create('vector.svg', 100, 'image/svg+xml');
        $resSvg = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/images", [
            'images' => [$maliciousSvg],
        ]);
        $resSvg->assertStatus(422);
        $resSvg->assertJsonValidationErrors('images.0');

        // C. Reject oversize file (> 10MB)
        $oversize = UploadedFile::fake()->image('huge.jpg', 3000, 3000)->size(11000);
        $resOversize = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/images", [
            'images' => [$oversize],
        ]);
        $resOversize->assertStatus(422);
        $resOversize->assertJsonValidationErrors('images.0');

        // D. Valid multi-image upload
        $page1 = UploadedFile::fake()->image('p1.webp', 800, 1200)->size(1500);
        $page2 = UploadedFile::fake()->image('p2.webp', 800, 1200)->size(1800);

        $resSuccess = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/images", [
            'images' => [$page1, $page2],
        ]);

        $resSuccess->assertStatus(201);
        $resSuccess->assertJsonCount(2, 'data');
        $resSuccess->assertJsonPath('data.0.page_number', 1);
        $resSuccess->assertJsonPath('data.1.page_number', 2);

        $images = ChapterImage::where('chapter_id', $chapter->id)->orderBy('page_number')->get();
        $this->assertCount(2, $images);
        $this->assertSame(1, $images[0]->page_number);
        $this->assertSame(2, $images[1]->page_number);

        // Verify stored files do not use client filenames
        $this->assertTrue(str_starts_with($images[0]->image_path, "chapters/{$chapter->id}/"));
        $this->assertStringNotContainsString('p1.webp', $images[0]->image_path);
        Storage::disk('public')->assertExists($images[0]->image_path);
        Storage::disk('public')->assertExists($images[1]->image_path);
    }

    /**
     * 8. Creator cannot modify images when chapter is in pending review or published.
     */
    public function test_cannot_modify_images_when_chapter_is_pending_or_published(): void
    {
        $chapter = Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
            'status' => 'pending',
        ]);

        $file = UploadedFile::fake()->image('page.webp', 600, 800);

        // Attempt upload while pending -> 403 Forbidden
        $resPending = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/images", [
            'images' => [$file],
        ]);
        $resPending->assertStatus(403);

        // Attempt upload while published -> 403 Forbidden
        $chapter->status = 'published';
        $chapter->save();

        $resPublished = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/images", [
            'images' => [$file],
        ]);
        $resPublished->assertStatus(403);
    }

    /**
     * 9. Reorder images workflow: safely updates page numbers and rejects foreign image IDs.
     */
    public function test_reorder_images_workflow(): void
    {
        $chapter = Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
            'status' => 'draft',
        ]);

        $img1 = ChapterImage::create([
            'chapter_id' => $chapter->id,
            'page_number' => 1,
            'image_path' => "chapters/{$chapter->id}/p1.webp",
        ]);

        $img2 = ChapterImage::create([
            'chapter_id' => $chapter->id,
            'page_number' => 2,
            'image_path' => "chapters/{$chapter->id}/p2.webp",
        ]);

        $img3 = ChapterImage::create([
            'chapter_id' => $chapter->id,
            'page_number' => 3,
            'image_path' => "chapters/{$chapter->id}/p3.webp",
        ]);

        // 1. Attempt to reorder with a foreign ID -> 422
        $resForeign = $this->actingAs($this->creatorA)->patchJson("/api/creator/chapters/{$chapter->id}/images/reorder", [
            'image_ids' => [$img1->id, $img2->id, 99999],
        ]);
        $resForeign->assertStatus(422);

        // 2. Reverse order: [3, 2, 1]
        $resReorder = $this->actingAs($this->creatorA)->patchJson("/api/creator/chapters/{$chapter->id}/images/reorder", [
            'image_ids' => [$img3->id, $img2->id, $img1->id],
        ]);
        $resReorder->assertStatus(200);

        $fresh3 = $img3->fresh();
        $fresh2 = $img2->fresh();
        $fresh1 = $img1->fresh();

        $this->assertSame(1, $fresh3->page_number);
        $this->assertSame(2, $fresh2->page_number);
        $this->assertSame(3, $fresh1->page_number);
    }

    /**
     * 10. Delete image workflow: removes record, deletes file on disk, and re-indexes remaining pages.
     */
    public function test_delete_image_workflow(): void
    {
        $file = UploadedFile::fake()->image('to_delete.webp', 600, 800);
        $path1 = $file->storeAs('chapters/1', 'del_p1.webp', 'public');
        $path2 = $file->storeAs('chapters/1', 'del_p2.webp', 'public');

        $chapter = Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
            'status' => 'draft',
        ]);

        $img1 = ChapterImage::create([
            'chapter_id' => $chapter->id,
            'page_number' => 1,
            'image_path' => $path1,
        ]);

        $img2 = ChapterImage::create([
            'chapter_id' => $chapter->id,
            'page_number' => 2,
            'image_path' => $path2,
        ]);

        $response = $this->actingAs($this->creatorA)->deleteJson("/api/creator/chapters/{$chapter->id}/images/{$img1->id}");
        $response->assertStatus(200);

        // Img 1 deleted, file missing from storage
        $this->assertDatabaseMissing('chapter_images', ['id' => $img1->id]);
        Storage::disk('public')->assertMissing($path1);

        // Img 2 remains and re-indexed to page 1
        $fresh2 = $img2->fresh();
        $this->assertNotNull($fresh2);
        $this->assertSame(1, $fresh2->page_number);
        Storage::disk('public')->assertExists($path2);
    }

    /**
     * 11. Submit review workflow: requires >= 1 image and transitions draft -> pending.
     */
    public function test_submit_review_workflow(): void
    {
        $chapter = Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => '1.00',
            'title' => 'Chương Chờ Duyệt',
            'slug' => 'chuong-cho-duyet',
            'status' => 'draft',
        ]);

        // A. Fails with 422 if 0 images
        $resNoImages = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/submit-review");
        $resNoImages->assertStatus(422);
        $resNoImages->assertJson([
            'status' => 'error',
            'message' => 'Chương truyện phải có ít nhất một ảnh trước khi gửi duyệt.',
        ]);

        // B. Add 1 image and succeed
        ChapterImage::create([
            'chapter_id' => $chapter->id,
            'page_number' => 1,
            'image_path' => "chapters/{$chapter->id}/p1.webp",
        ]);

        $resSuccess = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/submit-review");
        $resSuccess->assertStatus(200);
        $resSuccess->assertJsonPath('data.status', 'pending');

        $this->assertSame('pending', $chapter->fresh()->status);

        // C. Double submit denied by Policy (403)
        $resDouble = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/submit-review");
        $resDouble->assertStatus(403);
    }

    /**
     * 12. Cancel review workflow: allows pending -> draft transition.
     */
    public function test_cancel_review_workflow(): void
    {
        $chapter = Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => '1.00',
            'title' => 'Chương Hủy Duyệt',
            'slug' => 'chuong-huy-duyet',
            'status' => 'pending',
        ]);

        $resCancel = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/cancel-review");
        $resCancel->assertStatus(200);
        $resCancel->assertJsonPath('data.status', 'draft');

        $this->assertSame('draft', $chapter->fresh()->status);

        // Cancelling again while draft is denied by Policy (403)
        $resCancelAgain = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/cancel-review");
        $resCancelAgain->assertStatus(403);
    }

    /**
     * 13. Recall workflow: transitions published -> draft and immediately hides from public reader.
     */
    public function test_recall_workflow_and_reader_access(): void
    {
        $chapter = Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => '1.00',
            'title' => 'Chương Xuất Bản',
            'slug' => 'chuong-xuat-ban',
            'status' => 'published',
        ]);

        ChapterImage::create([
            'chapter_id' => $chapter->id,
            'page_number' => 1,
            'image_path' => "chapters/{$chapter->id}/p1.webp",
        ]);

        // Public reader can see published chapter
        $resReaderBefore = $this->getJson("/api/comics/{$this->comicA->slug}/chapters/1.00");
        $resReaderBefore->assertStatus(200);

        // Recall published chapter
        $resRecall = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/recall");
        $resRecall->assertStatus(200);
        $resRecall->assertJsonPath('data.status', 'draft');

        $this->assertSame('draft', $chapter->fresh()->status);

        // Public reader now receives 404 (chapter hidden)
        $resReaderAfter = $this->getJson("/api/comics/{$this->comicA->slug}/chapters/1.00");
        $resReaderAfter->assertStatus(404);

        // Recalling a draft chapter is denied by Policy (403)
        $resRecallDraft = $this->actingAs($this->creatorA)->postJson("/api/creator/chapters/{$chapter->id}/recall");
        $resRecallDraft->assertStatus(403);
    }

    /**
     * 14. Update aborts and leaves data intact if chapter transitions to pending before transaction lock.
     * Simulates race condition window where chapter was draft during pre-check, but shifted to pending in DB.
     */
    public function test_update_aborts_if_chapter_transitions_to_pending_before_lock_is_acquired(): void
    {
        $chapter = Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => '1.00',
            'title' => 'Tiêu Đề Ban Đầu',
            'slug' => 'chuong-1-tieu-de-ban-dau',
            'status' => 'draft',
        ]);

        // Intercept DB queries: mutate DB row to 'pending' after controller loads draft model, but before lockForUpdate
        $mutated = false;
        \Illuminate\Support\Facades\DB::listen(function ($query) use ($chapter, &$mutated) {
            if (! $mutated && str_contains(strtolower($query->sql), 'user_id') && str_contains(strtolower($query->sql), 'from "comics"')) {
                $mutated = true;
                \Illuminate\Support\Facades\DB::table('chapters')
                    ->where('id', $chapter->id)
                    ->update(['status' => 'pending']);
            }
        });

        $response = $this->actingAs($this->creatorA)->putJson("/api/creator/chapters/{$chapter->id}", [
            'title' => 'Tiêu Đề Bị Ghi Đè',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Không thể chỉnh sửa chương khi đang trong quá trình chờ duyệt.',
        ]);

        // Verify that the title in DB was NOT updated
        $this->assertSame('Tiêu Đề Ban Đầu', $chapter->fresh()->title);
        $this->assertSame('pending', $chapter->fresh()->status);
    }

    /**
     * 15. Creator update aborts if chapter was published before lock is acquired.
     */
    public function test_creator_update_aborts_if_chapter_is_published_when_locked(): void
    {
        $chapter = Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => '1.00',
            'title' => 'Chương Đã Xuất Bản',
            'slug' => 'chuong-1-xuat-ban',
            'status' => 'published',
        ]);

        $response = $this->actingAs($this->creatorA)->putJson("/api/creator/chapters/{$chapter->id}", [
            'title' => 'Sửa Lén',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Không thể chỉnh sửa chương đã xuất bản. Vui lòng thu hồi chương về bản nháp trước khi chỉnh sửa.',
        ]);

        $this->assertSame('Chương Đã Xuất Bản', $chapter->fresh()->title);
    }
}
