<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $creator;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->creator = User::factory()->create([
            'role' => 'creator',
            'is_active' => true,
        ]);

        $this->reader = User::factory()->create([
            'role' => 'reader',
            'is_active' => true,
        ]);
    }

    /**
     * 1. Guest cannot submit report.
     */
    public function test_guest_cannot_submit_report(): void
    {
        $post = Post::factory()->create();

        $response = $this->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
            'description' => 'Quảng cáo cờ bạc trái phép',
        ]);

        $response->assertStatus(401);
    }

    /**
     * 2. Authenticated users (reader, creator, admin) can submit valid reports on supported morph types.
     */
    public function test_authenticated_users_can_submit_valid_report(): void
    {
        $post = Post::factory()->create();
        $comment = Comment::factory()->create();
        $comic = Comic::create([
            'title' => 'Reportable Comic',
            'slug' => 'reportable-comic',
            'author_name' => 'Author X',
            'cover_path' => 'covers/test.webp',
        ]);
        $comic->approval_status = 'approved';
        $comic->save();

        $chapter = Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => 1.0,
            'title' => 'Chapter 1',
            'slug' => 'chapter-1',
            'status' => 'published',
        ]);
        $targetUser = User::factory()->create(['role' => 'reader', 'is_active' => true]);

        // Reader reports Post
        $resPost = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
            'description' => 'Nội dung chứa liên kết lừa đảo',
        ]);
        $resPost->assertStatus(201)
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('data.reportable_type', 'post')
                ->assertJsonPath('data.reportable_id', $post->id)
                ->assertJsonPath('data.status', 'pending')
                ->assertJsonPath('data.reporter.id', $this->reader->id)
                ->assertJsonPath('data.resolver', null);

        // Creator reports Comment
        $resComment = $this->actingAs($this->creator)->postJson('/api/reports', [
            'reportable_type' => 'comment',
            'reportable_id' => $comment->id,
            'reason' => 'harassment',
            'description' => 'Xúc phạm người đọc khác',
        ]);
        $resComment->assertStatus(201)
                   ->assertJsonPath('data.reportable_type', 'comment')
                   ->assertJsonPath('data.reporter.id', $this->creator->id);

        // Reader reports Comic
        $resComic = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'comic',
            'reportable_id' => $comic->id,
            'reason' => 'copyright',
        ]);
        $resComic->assertStatus(201)
                 ->assertJsonPath('data.reportable_type', 'comic');

        // Reader reports Chapter
        $resChapter = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'chapter',
            'reportable_id' => $chapter->id,
            'reason' => 'inappropriate_content',
        ]);
        $resChapter->assertStatus(201)
                   ->assertJsonPath('data.reportable_type', 'chapter');

        // Reader reports User
        $resUser = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'user',
            'reportable_id' => $targetUser->id,
            'reason' => 'impersonation',
        ]);
        $resUser->assertStatus(201)
                ->assertJsonPath('data.reportable_type', 'user');

        $this->assertDatabaseCount('reports', 5);
    }

    /**
     * 3. Input validation rejects missing, invalid or whitespace-only data.
     */
    public function test_report_input_validation_rules(): void
    {
        $post = Post::factory()->create();

        // Missing required fields
        $this->actingAs($this->reader)->postJson('/api/reports', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reportable_type', 'reportable_id', 'reason']);

        // Whitespace-only reason
        $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => '   ',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['reason']);

        // Reason too long (> 50 chars)
        $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => str_repeat('a', 51),
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['reason']);

        // Whitespace-only description
        $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
            'description' => '     ',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['description']);

        // Description too long (> 1000 chars)
        $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
            'description' => str_repeat('b', 1001),
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['description']);
    }

    /**
     * 4. Rejects invalid morph types, non-existent targets, or self-reporting.
     */
    public function test_cannot_report_invalid_morph_type_or_non_existent_target(): void
    {
        // Invalid morph type alias
        $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'App\\Models\\User',
            'reportable_id' => 1,
            'reason' => 'spam',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['reportable_type']);

        // Non-existent target ID
        $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => 999999,
            'reason' => 'spam',
        ])->assertStatus(404)
          ->assertJsonPath('status', 'error');

        // Cannot self-report
        $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'user',
            'reportable_id' => $this->reader->id,
            'reason' => 'other',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Bạn không thể tự báo cáo chính mình.');
    }

    /**
     * 5. Cannot create duplicate pending report for the same target and user.
     */
    public function test_cannot_create_duplicate_pending_report_for_same_target(): void
    {
        $post = Post::factory()->create();

        // First report succeeds
        $res1 = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
        ]);
        $res1->assertStatus(201);

        // Second report by same user for same post is rejected with 422
        $res2 = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'harassment',
        ]);
        $res2->assertStatus(422)
             ->assertJsonPath('status', 'error')
             ->assertJsonPath('message', 'Bạn đã có một báo cáo đang chờ xử lý cho nội dung này.');

        // Another user CAN report the same post
        $res3 = $this->actingAs($this->creator)->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
        ]);
        $res3->assertStatus(201);
    }

    /**
     * 6. Can submit new report after previous report is resolved or dismissed.
     */
    public function test_can_submit_new_report_after_previous_resolved_or_dismissed(): void
    {
        $post = Post::factory()->create();

        // 1. Submit first report
        $res1 = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
        ]);
        $res1->assertStatus(201);
        $report1Id = $res1->json('data.id');

        // 2. Admin resolves it
        $this->actingAs($this->admin)->patchJson("/api/admin/reports/{$report1Id}", [
            'status' => 'resolved',
            'admin_notes' => 'Đã xử lý cảnh cáo tác giả.',
        ])->assertStatus(200);

        // 3. User can now submit a new report on same post
        $res2 = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'harassment',
        ]);
        $res2->assertStatus(201);
        $report2Id = $res2->json('data.id');

        // 4. Admin dismisses the second report
        $this->actingAs($this->admin)->patchJson("/api/admin/reports/{$report2Id}", [
            'status' => 'dismissed',
            'admin_notes' => 'Không phát hiện vi phạm.',
        ])->assertStatus(200);

        // 5. User can submit a third report on same post
        $res3 = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'copyright',
        ]);
        $res3->assertStatus(201);
    }

    /**
     * 7. Reader and Creator cannot view or resolve admin reports.
     */
    public function test_reader_and_creator_cannot_view_or_resolve_admin_reports(): void
    {
        $report = Report::factory()->create();

        // Reader forbidden
        $this->actingAs($this->reader)->getJson('/api/admin/reports')->assertStatus(403);
        $this->actingAs($this->reader)->getJson("/api/admin/reports/{$report->id}")->assertStatus(403);
        $this->actingAs($this->reader)->patchJson("/api/admin/reports/{$report->id}", [
            'status' => 'resolved',
        ])->assertStatus(403);

        // Creator forbidden
        $this->actingAs($this->creator)->getJson('/api/admin/reports')->assertStatus(403);
        $this->actingAs($this->creator)->getJson("/api/admin/reports/{$report->id}")->assertStatus(403);
        $this->actingAs($this->creator)->patchJson("/api/admin/reports/{$report->id}", [
            'status' => 'resolved',
        ])->assertStatus(403);
    }

    /**
     * 7b. Guest cannot view or resolve admin reports.
     */
    public function test_guest_cannot_view_or_resolve_admin_reports(): void
    {
        $report = Report::factory()->create();

        $this->getJson('/api/admin/reports')->assertStatus(401);
        $this->getJson("/api/admin/reports/{$report->id}")->assertStatus(401);
        $this->patchJson("/api/admin/reports/{$report->id}", [
            'status' => 'resolved',
        ])->assertStatus(401);
    }

    /**
     * 8. Admin can list, paginate, and filter reports.
     */
    public function test_admin_can_list_paginate_and_filter_reports(): void
    {
        $post = Post::factory()->create();
        $comment = Comment::factory()->create();

        Report::factory()->count(3)->create([
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'status' => 'pending',
            'reason' => 'spam',
        ]);

        Report::factory()->count(2)->create([
            'reportable_type' => 'comment',
            'reportable_id' => $comment->id,
            'status' => 'resolved',
            'reason' => 'harassment',
            'resolved_by' => $this->admin->id,
            'resolved_at' => now(),
        ]);

        // List all
        $resAll = $this->actingAs($this->admin)->getJson('/api/admin/reports');
        $resAll->assertStatus(200)
               ->assertJsonCount(5, 'data');

        // Filter by status=pending
        $resPending = $this->actingAs($this->admin)->getJson('/api/admin/reports?status=pending');
        $resPending->assertStatus(200)
                   ->assertJsonCount(3, 'data');

        // Filter by status=resolved
        $resResolved = $this->actingAs($this->admin)->getJson('/api/admin/reports?status=resolved');
        $resResolved->assertStatus(200)
                    ->assertJsonCount(2, 'data');

        // Filter by reason=spam
        $resSpam = $this->actingAs($this->admin)->getJson('/api/admin/reports?reason=spam');
        $resSpam->assertStatus(200)
                ->assertJsonCount(3, 'data');

        // Filter by reportable_type=comment
        $resType = $this->actingAs($this->admin)->getJson('/api/admin/reports?reportable_type=comment');
        $resType->assertStatus(200)
                ->assertJsonCount(2, 'data');

        // Pagination
        $resPage = $this->actingAs($this->admin)->getJson('/api/admin/reports?per_page=2');
        $resPage->assertStatus(200)
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('meta.per_page', 2)
                ->assertJsonPath('meta.total', 5);
    }

    /**
     * 9. Admin can view report detail and resolve or dismiss report.
     */
    public function test_admin_can_view_report_detail_and_resolve_or_dismiss(): void
    {
        $post = Post::factory()->create(['content' => 'Nội dung bài viết vi phạm']);
        $report = Report::factory()->create([
            'reporter_id' => $this->reader->id,
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
            'description' => 'Spam liên kết',
            'status' => 'pending',
        ]);

        // View detail
        $resShow = $this->actingAs($this->admin)->getJson("/api/admin/reports/{$report->id}");
        $resShow->assertStatus(200)
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('data.id', $report->id)
                ->assertJsonPath('data.reporter.id', $this->reader->id)
                ->assertJsonPath('data.target.content', 'Nội dung bài viết vi phạm')
                ->assertJsonPath('data.status', 'pending');

        // Resolve report
        $resResolve = $this->actingAs($this->admin)->patchJson("/api/admin/reports/{$report->id}", [
            'status' => 'resolved',
            'admin_notes' => 'Đã xác minh và xử lý vi phạm.',
        ]);
        $resResolve->assertStatus(200)
                   ->assertJsonPath('status', 'success')
                   ->assertJsonPath('data.status', 'resolved')
                   ->assertJsonPath('data.resolver.id', $this->admin->id)
                   ->assertJsonPath('data.admin_notes', 'Đã xác minh và xử lý vi phạm.');

        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'status' => 'resolved',
            'resolved_by' => $this->admin->id,
            'admin_notes' => 'Đã xác minh và xử lý vi phạm.',
        ]);
    }

    /**
     * 10. Cannot arbitrarily modify system fields or transition to invalid status.
     */
    public function test_cannot_arbitrarily_modify_system_fields_or_transition_invalid_status(): void
    {
        $originalReporter = $this->reader;
        $post = Post::factory()->create();
        $report = Report::factory()->create([
            'reporter_id' => $originalReporter->id,
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'status' => 'pending',
        ]);

        // Cannot set status back to 'pending'
        $this->actingAs($this->admin)->patchJson("/api/admin/reports/{$report->id}", [
            'status' => 'pending',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['status']);

        // Cannot set invalid status
        $this->actingAs($this->admin)->patchJson("/api/admin/reports/{$report->id}", [
            'status' => 'closed',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['status']);

        // Attempting to inject system fields is ignored/not updated
        $anotherUser = User::factory()->create();
        $this->actingAs($this->admin)->patchJson("/api/admin/reports/{$report->id}", [
            'status' => 'resolved',
            'reporter_id' => $anotherUser->id,
            'reportable_type' => 'comic',
            'reportable_id' => 999,
        ])->assertStatus(200);

        $report->refresh();
        $this->assertEquals($originalReporter->id, $report->reporter_id);
        $this->assertEquals('post', $report->reportable_type);
        $this->assertEquals($post->id, $report->reportable_id);
        $this->assertEquals('resolved', $report->status);
    }

    /**
     * 11. Resolving report does not modify or delete original content.
     */
    public function test_resolving_report_does_not_modify_or_delete_original_content(): void
    {
        $post = Post::factory()->create([
            'content' => 'Nội dung nguyên bản',
            'likes_count' => 5,
            'comments_count' => 2,
        ]);

        $report = Report::factory()->create([
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)->patchJson("/api/admin/reports/{$report->id}", [
            'status' => 'resolved',
            'admin_notes' => 'Báo cáo hợp lệ.',
        ])->assertStatus(200);

        // Verify post still exists with exactly the same content
        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'content' => 'Nội dung nguyên bản',
            'likes_count' => 5,
            'comments_count' => 2,
        ]);
    }

    /**
     * 12. Reported entity that was deleted does not cause 500 error in API.
     */
    public function test_deleted_target_entity_does_not_cause_500_error(): void
    {
        $post = Post::factory()->create();
        $report = Report::factory()->create([
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'status' => 'pending',
        ]);

        // Delete post without firing deleting event so report stays in DB with null reportable
        Post::withoutEvents(function () use ($post) {
            $post->delete();
        });

        // Admin index listing should not fail with 500
        $resIndex = $this->actingAs($this->admin)->getJson('/api/admin/reports');
        $resIndex->assertStatus(200)
                 ->assertJsonPath('data.0.target.exists', false);

        // Admin detail view should not fail with 500
        $resShow = $this->actingAs($this->admin)->getJson("/api/admin/reports/{$report->id}");
        $resShow->assertStatus(200)
                ->assertJsonPath('data.target.exists', false);
    }

    /**
     * 13. Repeated processing on already resolved report is rejected with 422.
     */
    public function test_repeated_processing_on_resolved_report_is_rejected(): void
    {
        $report = Report::factory()->create([
            'status' => 'pending',
        ]);

        // First resolution
        $this->actingAs($this->admin)->patchJson("/api/admin/reports/{$report->id}", [
            'status' => 'resolved',
            'admin_notes' => 'Xử lý lần 1.',
        ])->assertStatus(200);

        // Second resolution attempt by another admin
        $admin2 = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $resSecond = $this->actingAs($admin2)->patchJson("/api/admin/reports/{$report->id}", [
            'status' => 'dismissed',
            'admin_notes' => 'Cố gắng ghi đè.',
        ]);

        $resSecond->assertStatus(422)
                  ->assertJsonPath('status', 'error')
                  ->assertJsonPath('message', 'Báo cáo này đã được xử lý trước đó và không thể thay đổi trạng thái.');

        // Verify resolver remains the first admin
        $report->refresh();
        $this->assertEquals('resolved', $report->status);
        $this->assertEquals($this->admin->id, $report->resolved_by);
        $this->assertEquals('Xử lý lần 1.', $report->admin_notes);
    }

    /**
     * 14. 404 returned for non-existent report ID.
     */
    public function test_404_for_non_existent_report(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/reports/999999')
            ->assertStatus(404)
            ->assertJsonPath('status', 'error');

        $this->actingAs($this->admin)->patchJson('/api/admin/reports/999999', [
            'status' => 'resolved',
        ])->assertStatus(404)
          ->assertJsonPath('status', 'error');
    }

    /**
     * 15. Regression: Reader cannot report draft or pending comic. Private content is not leaked.
     */
    public function test_reader_cannot_report_draft_or_pending_comic(): void
    {
        $creator2 = User::factory()->create(['role' => 'creator', 'is_active' => true]);
        $draftComic = Comic::create([
            'user_id' => $creator2->id,
            'title' => 'Top Secret Draft Comic',
            'slug' => 'top-secret-draft-comic',
            'author_name' => 'Creator Secret',
            'cover_path' => 'covers/draft.webp',
        ]);
        $draftComic->approval_status = 'draft';
        $draftComic->save();

        $pendingComic = Comic::create([
            'user_id' => $creator2->id,
            'title' => 'Pending Review Comic',
            'slug' => 'pending-review-comic',
            'author_name' => 'Creator Secret',
            'cover_path' => 'covers/pending.webp',
        ]);
        $pendingComic->approval_status = 'pending';
        $pendingComic->save();

        // 1. Reader cannot report draft comic
        $resDraft = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'comic',
            'reportable_id' => $draftComic->id,
            'reason' => 'inappropriate',
        ]);
        $resDraft->assertStatus(404)
                 ->assertJsonPath('status', 'error')
                 ->assertJsonPath('message', 'Đối tượng được báo cáo không tồn tại hoặc đã bị xóa.');
        // Verify private data is never leaked in response
        $this->assertStringNotContainsString('Top Secret Draft Comic', $resDraft->getContent());
        $this->assertStringNotContainsString('top-secret-draft-comic', $resDraft->getContent());

        // 2. Reader cannot report pending comic
        $resPending = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'comic',
            'reportable_id' => $pendingComic->id,
            'reason' => 'inappropriate',
        ]);
        $resPending->assertStatus(404)
                   ->assertJsonPath('status', 'error');
        $this->assertStringNotContainsString('Pending Review Comic', $resPending->getContent());

        $this->assertDatabaseMissing('reports', [
            'reporter_id' => $this->reader->id,
            'reportable_type' => 'comic',
            'reportable_id' => $draftComic->id,
        ]);
    }

    /**
     * 16. Regression: Reader cannot report draft, pending, or unpublished chapter or chapter under unapproved comic.
     */
    public function test_reader_cannot_report_unpublished_chapter_or_chapter_under_unapproved_comic(): void
    {
        $creator2 = User::factory()->create(['role' => 'creator', 'is_active' => true]);

        $approvedComic = Comic::create([
            'user_id' => $creator2->id,
            'title' => 'Approved Parent Comic',
            'slug' => 'approved-parent-comic',
            'author_name' => 'Creator 2',
            'cover_path' => 'covers/approved.webp',
        ]);
        $approvedComic->approval_status = 'approved';
        $approvedComic->save();

        $draftChapter = Chapter::create([
            'comic_id' => $approvedComic->id,
            'chapter_number' => 1.0,
            'title' => 'Private Draft Chapter',
            'slug' => 'private-draft-chapter',
            'status' => 'draft',
        ]);

        $pendingChapter = Chapter::create([
            'comic_id' => $approvedComic->id,
            'chapter_number' => 2.0,
            'title' => 'Pending Chapter',
            'slug' => 'pending-chapter',
            'status' => 'pending',
        ]);

        $unapprovedComic = Comic::create([
            'user_id' => $creator2->id,
            'title' => 'Unapproved Parent Comic',
            'slug' => 'unapproved-parent-comic',
            'author_name' => 'Creator 2',
            'cover_path' => 'covers/unapproved.webp',
        ]);
        $unapprovedComic->approval_status = 'draft';
        $unapprovedComic->save();

        $chapterUnderUnapprovedComic = Chapter::create([
            'comic_id' => $unapprovedComic->id,
            'chapter_number' => 1.0,
            'title' => 'Chapter Under Draft Comic',
            'slug' => 'chapter-under-draft-comic',
            'status' => 'published',
        ]);

        // 3. Reader cannot report draft chapter
        $resDraft = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'chapter',
            'reportable_id' => $draftChapter->id,
            'reason' => 'spam',
        ]);
        $resDraft->assertStatus(404)
                 ->assertJsonPath('status', 'error')
                 ->assertJsonPath('message', 'Đối tượng được báo cáo không tồn tại hoặc đã bị xóa.');
        $this->assertStringNotContainsString('Private Draft Chapter', $resDraft->getContent());

        // 4. Reader cannot report chapter not published (e.g. pending)
        $resPending = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'chapter',
            'reportable_id' => $pendingChapter->id,
            'reason' => 'spam',
        ]);
        $resPending->assertStatus(404)
                   ->assertJsonPath('status', 'error');
        $this->assertStringNotContainsString('Pending Chapter', $resPending->getContent());

        // 5. Reader cannot report published chapter whose parent comic is not approved
        $resUnapprovedComic = $this->actingAs($this->reader)->postJson('/api/reports', [
            'reportable_type' => 'chapter',
            'reportable_id' => $chapterUnderUnapprovedComic->id,
            'reason' => 'spam',
        ]);
        $resUnapprovedComic->assertStatus(404)
                           ->assertJsonPath('status', 'error');
        $this->assertStringNotContainsString('Chapter Under Draft Comic', $resUnapprovedComic->getContent());
    }

    /**
     * 17. Regression: Creator cannot access or report another creator's draft content.
     */
    public function test_creator_cannot_access_or_report_other_creators_draft_content(): void
    {
        $creator2 = User::factory()->create(['role' => 'creator', 'is_active' => true]);
        $draftComic = Comic::create([
            'user_id' => $creator2->id,
            'title' => 'Other Creator Secret Comic',
            'slug' => 'other-creator-secret-comic',
            'author_name' => 'Creator 2',
            'cover_path' => 'covers/creator2.webp',
        ]);
        $draftComic->approval_status = 'draft';
        $draftComic->save();

        $res = $this->actingAs($this->creator)->postJson('/api/reports', [
            'reportable_type' => 'comic',
            'reportable_id' => $draftComic->id,
            'reason' => 'copyright',
        ]);

        $res->assertStatus(404)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Đối tượng được báo cáo không tồn tại hoặc đã bị xóa.');
        $this->assertStringNotContainsString('Other Creator Secret Comic', $res->getContent());
    }

    /**
     * 18. Regression: Admin can report draft or pending content for administrative/moderation records.
     */
    public function test_admin_can_report_draft_or_pending_content(): void
    {
        $draftComic = Comic::create([
            'user_id' => $this->creator->id,
            'title' => 'Administrative Flagged Draft Comic',
            'slug' => 'administrative-flagged-draft-comic',
            'author_name' => 'Creator',
            'cover_path' => 'covers/draft.webp',
        ]);
        $draftComic->approval_status = 'draft';
        $draftComic->save();

        $res = $this->actingAs($this->admin)->postJson('/api/reports', [
            'reportable_type' => 'comic',
            'reportable_id' => $draftComic->id,
            'reason' => 'prohibited_content',
            'description' => 'Admin phát hiện bản thảo vi phạm chính sách trước khi kiểm duyệt.',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reportable_type', 'comic')
            ->assertJsonPath('data.reportable_id', $draftComic->id)
            ->assertJsonPath('data.target.title', 'Administrative Flagged Draft Comic')
            ->assertJsonPath('data.target.approval_status', 'draft');

        $this->assertDatabaseHas('reports', [
            'reporter_id' => $this->admin->id,
            'reportable_type' => 'comic',
            'reportable_id' => $draftComic->id,
            'status' => 'pending',
        ]);
    }
}
