<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterImage;
use App\Models\Comic;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminModerationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $creator;
    private User $reader;
    private Genre $actionGenre;
    private Tag $colorTag;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = User::create([
            'name' => 'Admin Moderator',
            'email' => 'admin@example.com',
            'password' => 'Password123!',
        ]);
        $this->admin->role = 'admin';
        $this->admin->save();

        $this->creator = User::create([
            'name' => 'Creator Author',
            'email' => 'creator@example.com',
            'password' => 'Password123!',
        ]);
        $this->creator->role = 'creator';
        $this->creator->save();

        $this->reader = User::create([
            'name' => 'Reader User',
            'email' => 'reader@example.com',
            'password' => 'Password123!',
        ]);

        $this->actionGenre = Genre::create([
            'name' => 'Hành Động',
            'slug' => 'hanh-dong',
            'is_active' => true,
        ]);

        $this->colorTag = Tag::create([
            'name' => 'Truyện Màu',
            'slug' => 'truyen-mau',
        ]);
    }

    /**
     * 1. Unauthenticated guest receives HTTP 401 Unauthenticated.
     */
    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/admin/moderation/comics')->assertStatus(401);
        $this->getJson('/api/admin/moderation/chapters')->assertStatus(401);
    }

    /**
     * 2. Reader receives HTTP 403 Forbidden.
     */
    public function test_reader_is_forbidden(): void
    {
        $this->actingAs($this->reader)->getJson('/api/admin/moderation/comics')->assertStatus(403);
        $this->actingAs($this->reader)->getJson('/api/admin/moderation/chapters')->assertStatus(403);
    }

    /**
     * 3. Creator receives HTTP 403 Forbidden (Admin moderation is strictly Admin-only).
     */
    public function test_creator_is_forbidden(): void
    {
        $this->actingAs($this->creator)->getJson('/api/admin/moderation/comics')->assertStatus(403);
        $this->actingAs($this->creator)->getJson('/api/admin/moderation/chapters')->assertStatus(403);
    }

    /**
     * 4. Admin can access moderation queue successfully.
     */
    public function test_admin_can_access_queue(): void
    {
        $resComics = $this->actingAs($this->admin)->getJson('/api/admin/moderation/comics');
        $resComics->assertStatus(200);

        $resChapters = $this->actingAs($this->admin)->getJson('/api/admin/moderation/chapters');
        $resChapters->assertStatus(200);
    }

    /**
     * 5. Comics moderation queue only returns pending comics or approved comics with pending_changes.
     * Draft and already approved comics without pending_changes are excluded.
     */
    public function test_comics_queue_filters_status_correctly(): void
    {
        // Comic 1: Draft (excluded)
        Comic::create([
            'title' => 'Draft Comic',
            'slug' => 'draft-comic',
            'author_name' => 'Author',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/1.webp',
        ]);

        // Comic 2: Pending (included)
        $pendingComic = Comic::create([
            'title' => 'Pending Comic',
            'slug' => 'pending-comic',
            'author_name' => 'Author',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/2.webp',
        ]);
        $pendingComic->approval_status = 'pending';
        $pendingComic->save();

        // Comic 3: Approved without pending changes (excluded)
        $approvedComic = Comic::create([
            'title' => 'Approved Comic',
            'slug' => 'approved-comic',
            'author_name' => 'Author',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/3.webp',
        ]);
        $approvedComic->approval_status = 'approved';
        $approvedComic->save();

        // Comic 4: Approved WITH pending changes (included)
        $approvedWithChanges = Comic::create([
            'title' => 'Approved With Changes',
            'slug' => 'approved-with-changes',
            'author_name' => 'Author',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/4.webp',
        ]);
        $approvedWithChanges->approval_status = 'approved';
        $approvedWithChanges->pending_changes = ['title' => 'New Title Proposed'];
        $approvedWithChanges->save();

        $response = $this->actingAs($this->admin)->getJson('/api/admin/moderation/comics');
        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Pending Comic', $titles);
        $this->assertContains('Approved With Changes', $titles);
        $this->assertNotContains('Draft Comic', $titles);
        $this->assertNotContains('Approved Comic', $titles);
    }

    /**
     * 6. Queue supports type filter (new, changes, all), search (q), and pagination.
     */
    public function test_comics_queue_filters_search_and_pagination(): void
    {
        $c1 = Comic::create([
            'title' => 'Tuyệt Thế Võ Thần',
            'slug' => 'tuyet-the-vo-than',
            'author_name' => 'Nguyễn Du',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/c1.webp',
        ]);
        $c1->approval_status = 'pending';
        $c1->save();

        $c2 = Comic::create([
            'title' => 'Đại Quản Gia',
            'slug' => 'dai-quan-gia',
            'author_name' => 'Lạc Lạc',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/c2.webp',
        ]);
        $c2->approval_status = 'approved';
        $c2->pending_changes = ['title' => 'Đại Quản Gia Ma Hoàng'];
        $c2->save();

        // Type: new
        $resNew = $this->actingAs($this->admin)->getJson('/api/admin/moderation/comics?type=new');
        $resNew->assertStatus(200);
        $resNew->assertJsonCount(1, 'data');
        $this->assertSame('Tuyệt Thế Võ Thần', $resNew->json('data.0.title'));

        // Type: changes
        $resChanges = $this->actingAs($this->admin)->getJson('/api/admin/moderation/comics?type=changes');
        $resChanges->assertStatus(200);
        $resChanges->assertJsonCount(1, 'data');
        $this->assertSame('Đại Quản Gia', $resChanges->json('data.0.title'));

        // Search: q=Tuyệt
        $resSearch = $this->actingAs($this->admin)->getJson('/api/admin/moderation/comics?q=Tuyệt');
        $resSearch->assertStatus(200);
        $resSearch->assertJsonCount(1, 'data');
        $this->assertSame('Tuyệt Thế Võ Thần', $resSearch->json('data.0.title'));

        // Pagination: per_page=1
        $resPage = $this->actingAs($this->admin)->getJson('/api/admin/moderation/comics?per_page=1');
        $resPage->assertStatus(200);
        $resPage->assertJsonCount(1, 'data');
        $this->assertSame(2, $resPage->json('meta.total'));
    }

    /**
     * 7. Approve pending comic sets approved_by, approved_at, status=approved, clears rejection_reason.
     */
    public function test_approve_pending_comic_successfully(): void
    {
        $comic = Comic::create([
            'title' => 'Truyện Chờ Duyệt Mới',
            'slug' => 'truyen-cho-duyet-moi',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/pending.webp',
        ]);
        $comic->approval_status = 'pending';
        $comic->rejection_reason = 'Lý do cũ';
        $comic->save();

        $response = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/approve");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Duyệt bộ truyện thành công.',
            'data' => [
                'id' => $comic->id,
                'approval_status' => 'approved',
                'rejection_reason' => null,
            ],
        ]);

        $fresh = $comic->fresh();
        $this->assertSame('approved', $fresh->approval_status);
        $this->assertSame($this->admin->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
        $this->assertNull($fresh->rejection_reason);
    }

    /**
     * 8. Approving a comic not in pending state returns HTTP 422.
     */
    public function test_approve_comic_wrong_status_returns_422(): void
    {
        $draftComic = Comic::create([
            'title' => 'Bản Nháp',
            'slug' => 'ban-nhap',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/draft.webp',
        ]);

        $resDraft = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$draftComic->id}/approve");
        $resDraft->assertStatus(422);
        $resDraft->assertJson([
            'status' => 'error',
            'message' => 'Chỉ có thể duyệt bộ truyện đang trong trạng thái chờ duyệt.',
        ]);
    }

    /**
     * 9. Reject Comic validation: rejection_reason required, min 5, max 1000 characters.
     */
    public function test_reject_comic_validation(): void
    {
        $comic = Comic::create([
            'title' => 'Truyện Sắp Bị Từ Chối',
            'slug' => 'truyen-sap-bi-tu-choi',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/test.webp',
        ]);
        $comic->approval_status = 'pending';
        $comic->save();

        // A. Missing reason
        $resMissing = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/reject", []);
        $resMissing->assertStatus(422);
        $resMissing->assertJsonValidationErrors('rejection_reason');

        // B. Reason too short (< 5 chars)
        $resShort = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/reject", [
            'rejection_reason' => 'bad',
        ]);
        $resShort->assertStatus(422);
        $resShort->assertJsonValidationErrors('rejection_reason');

        // C. Reason too long (> 1000 chars)
        $resLong = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/reject", [
            'rejection_reason' => str_repeat('a', 1001),
        ]);
        $resLong->assertStatus(422);
        $resLong->assertJsonValidationErrors('rejection_reason');
    }

    /**
     * 10. Reject pending comic successfully saves rejection_reason, approved_by, approved_at.
     */
    public function test_reject_comic_successfully(): void
    {
        $comic = Comic::create([
            'title' => 'Truyện Không Đạt Chuẩn',
            'slug' => 'truyen-khong-dat-chuan',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/bad.webp',
        ]);
        $comic->approval_status = 'pending';
        $comic->save();

        $response = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/reject", [
            'rejection_reason' => 'Ảnh bìa có chất lượng quá thấp, vui lòng cập nhật lại ảnh độ phân giải cao.',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Từ chối duyệt bộ truyện thành công.',
            'data' => [
                'approval_status' => 'rejected',
                'rejection_reason' => 'Ảnh bìa có chất lượng quá thấp, vui lòng cập nhật lại ảnh độ phân giải cao.',
            ],
        ]);

        $fresh = $comic->fresh();
        $this->assertSame('rejected', $fresh->approval_status);
        $this->assertSame('Ảnh bìa có chất lượng quá thấp, vui lòng cập nhật lại ảnh độ phân giải cao.', $fresh->rejection_reason);
        $this->assertSame($this->admin->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
    }

    /**
     * 11. Approve pending_changes updates allowed fields and relations, and resets pending_changes to null.
     */
    public function test_approve_comic_changes_successfully(): void
    {
        $oldCover = 'comics/covers/old_public_cover.webp';
        $newCover = 'comics/covers/new_proposed_cover.webp';
        Storage::disk('public')->put($oldCover, 'old');
        Storage::disk('public')->put($newCover, 'new');

        $comic = Comic::create([
            'title' => 'Tên Gốc',
            'slug' => 'ten-goc',
            'author_name' => 'Tác Giả Gốc',
            'description' => 'Mô tả gốc.',
            'user_id' => $this->creator->id,
            'cover_path' => $oldCover,
            'age_rating' => '13+',
        ]);
        $comic->approval_status = 'approved';
        $comic->pending_changes = [
            'title' => 'Tên Đã Chỉnh Sửa',
            'author_name' => 'Tác Giả Mới',
            'description' => 'Mô tả đã sửa.',
            'cover_path' => $newCover,
            'age_rating' => '16+',
            'tags' => [$this->colorTag->id],
            'genres' => [$this->actionGenre->id],
            // Malicious fields attempting spoofing:
            'user_id' => 999,
            'views_count' => 999999,
        ];
        $comic->save();

        $response = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/approve-changes");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Duyệt bản chỉnh sửa của bộ truyện thành công.',
        ]);

        $fresh = $comic->fresh();
        $this->assertSame('Tên Đã Chỉnh Sửa', $fresh->title);
        $this->assertSame('Tác Giả Mới', $fresh->author_name);
        $this->assertSame('Mô tả đã sửa.', $fresh->description);
        $this->assertSame($newCover, $fresh->cover_path);
        $this->assertSame('16+', $fresh->age_rating);
        $this->assertNull($fresh->pending_changes);

        // Security check: system fields are untouched
        $this->assertSame($this->creator->id, $fresh->user_id);
        $this->assertSame(0, $fresh->views_count);

        // Relations synced
        $this->assertTrue($fresh->tags->contains($this->colorTag));
        $this->assertTrue($fresh->genres->contains($this->actionGenre));

        // Old cover superseded and cleaned up from disk
        Storage::disk('public')->assertMissing($oldCover);
        Storage::disk('public')->assertExists($newCover);
    }

    /**
     * 12. Reject pending_changes keeps public fields intact, sets rejection_reason,
     * clears pending_changes, and cleans up proposed cover.
     */
    public function test_reject_comic_changes_successfully(): void
    {
        $publicCover = 'comics/covers/active_public.webp';
        $proposedCover = 'comics/covers/bad_proposed.webp';
        Storage::disk('public')->put($publicCover, 'public-data');
        Storage::disk('public')->put($proposedCover, 'proposed-data');

        $comic = Comic::create([
            'title' => 'Tên Công Khai',
            'slug' => 'ten-cong-khai',
            'author_name' => 'Tác Giả',
            'description' => 'Mô tả công khai.',
            'user_id' => $this->creator->id,
            'cover_path' => $publicCover,
        ]);
        $comic->approval_status = 'approved';
        $comic->save();
        $comic->genres()->sync([$this->actionGenre->id]);
        $otherGenre = Genre::create(['name' => 'Kinh Dị', 'slug' => 'kinh-di', 'is_active' => true]);
        $comic->pending_changes = [
            'title' => 'Tên Rác',
            'cover_path' => $proposedCover,
            'genres' => [$otherGenre->id],
        ];
        $comic->save();

        $response = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/reject-changes", [
            'rejection_reason' => 'Tên đề xuất chứa ký tự không phù hợp.',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Từ chối bản chỉnh sửa của bộ truyện thành công.',
        ]);

        $fresh = $comic->fresh(['genres']);
        // Public fields remain strictly untouched
        $this->assertSame('Tên Công Khai', $fresh->title);
        $this->assertSame($publicCover, $fresh->cover_path);
        $this->assertNull($fresh->pending_changes);
        $this->assertSame('Tên đề xuất chứa ký tự không phù hợp.', $fresh->rejection_reason);
        $this->assertTrue($fresh->genres->contains($this->actionGenre));
        $this->assertFalse($fresh->genres->contains($otherGenre));

        // Public cover is preserved, proposed cover is cleaned up
        Storage::disk('public')->assertExists($publicCover);
        Storage::disk('public')->assertMissing($proposedCover);
    }

    /**
     * 13. Public cover is never deleted when rejecting changes or approving changes where cover did not change.
     */
    public function test_public_cover_is_never_deleted_improperly(): void
    {
        $publicCover = 'comics/covers/shared_public.webp';
        Storage::disk('public')->put($publicCover, 'shared-data');

        $comic = Comic::create([
            'title' => 'Bộ Truyện',
            'slug' => 'bo-truyen',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => $publicCover,
        ]);
        $comic->approval_status = 'approved';
        $comic->pending_changes = [
            'title' => 'Tên Đổi Nhưng Cover Giữ Nguyên',
            'cover_path' => $publicCover, // Identical to public cover
        ];
        $comic->save();

        $response = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/approve-changes");
        $response->assertStatus(200);

        // Cover file must still exist
        Storage::disk('public')->assertExists($publicCover);
    }

    /**
     * 14. Approve Chapter when parent Comic is not approved returns HTTP 422.
     */
    public function test_approve_chapter_when_parent_comic_not_approved_returns_422(): void
    {
        $draftComic = Comic::create([
            'title' => 'Truyện Chưa Duyệt',
            'slug' => 'truyen-chua-duyet',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/test.webp',
            'approval_status' => 'draft',
        ]);

        $chapter = Chapter::create([
            'comic_id' => $draftComic->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/moderation/chapters/{$chapter->id}/approve");

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Không thể duyệt xuất bản chương khi bộ truyện chưa được duyệt (trạng thái hiện tại: draft).',
        ]);

        $this->assertSame('pending', $chapter->fresh()->status);
    }

    /**
     * 15. Approve Chapter when parent Comic is approved sets status=published, approved_by, approved_at.
     */
    public function test_approve_chapter_successfully(): void
    {
        $approvedComic = Comic::create([
            'title' => 'Truyện Đã Duyệt',
            'slug' => 'truyen-da-duyet',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/approved.webp',
        ]);
        $approvedComic->approval_status = 'approved';
        $approvedComic->save();

        $chapter = Chapter::create([
            'comic_id' => $approvedComic->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
            'status' => 'pending',
            'rejection_reason' => 'Lý do cũ',
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/moderation/chapters/{$chapter->id}/approve");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Duyệt xuất bản chương truyện thành công.',
            'data' => [
                'id' => $chapter->id,
                'status' => 'published',
                'rejection_reason' => null,
            ],
        ]);

        $fresh = $chapter->fresh();
        $this->assertSame('published', $fresh->status);
        $this->assertSame($this->admin->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
        $this->assertNull($fresh->rejection_reason);
    }

    /**
     * 16. Reject Chapter validation enforces rejection_reason length between 5 and 500 characters.
     */
    public function test_reject_chapter_validation(): void
    {
        $comic = Comic::create([
            'title' => 'Truyện Test',
            'slug' => 'truyen-test',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/test.webp',
        ]);

        $chapter = Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
            'status' => 'pending',
        ]);

        // Reason too long (> 500 characters)
        $resLong = $this->actingAs($this->admin)->postJson("/api/admin/moderation/chapters/{$chapter->id}/reject", [
            'rejection_reason' => str_repeat('b', 501),
        ]);
        $resLong->assertStatus(422);
        $resLong->assertJsonValidationErrors('rejection_reason');

        // Valid rejection
        $resSuccess = $this->actingAs($this->admin)->postJson("/api/admin/moderation/chapters/{$chapter->id}/reject", [
            'rejection_reason' => 'Ảnh chương bị mờ và đảo lộn thứ tự trang.',
        ]);
        $resSuccess->assertStatus(200);

        $fresh = $chapter->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Ảnh chương bị mờ và đảo lộn thứ tự trang.', $fresh->rejection_reason);
        $this->assertSame($this->admin->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
    }

    /**
     * 17. Re-moderating an already processed Comic or Chapter returns HTTP 422.
     */
    public function test_cannot_moderate_already_processed_items(): void
    {
        $comic = Comic::create([
            'title' => 'Truyện Đã Duyệt Sẵn',
            'slug' => 'truyen-da-duyet-san',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/c.webp',
        ]);
        $comic->approval_status = 'approved';
        $comic->save();

        // Attempt to approve again
        $resDoubleComic = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/approve");
        $resDoubleComic->assertStatus(422);

        // Attempt to reject already approved comic
        $resRejectApproved = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/reject", [
            'rejection_reason' => 'Lý do muộn màng.',
        ]);
        $resRejectApproved->assertStatus(422);

        // Chapter already published
        $chapter = Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
            'status' => 'published',
        ]);

        $resDoubleChapter = $this->actingAs($this->admin)->postJson("/api/admin/moderation/chapters/{$chapter->id}/approve");
        $resDoubleChapter->assertStatus(422);
    }

    /**
     * 18. Interleaving test: status mutated before lock in Comic approval.
     */
    public function test_comic_approval_aborts_if_status_shifts_before_lock(): void
    {
        $comic = Comic::create([
            'title' => 'Truyện Đua Khóa',
            'slug' => 'truyen-dua-khoa',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/race.webp',
        ]);
        $comic->approval_status = 'pending';
        $comic->save();

        // Mutate DB to 'draft' (e.g. creator recalled review) right after initial findOrFail
        $mutated = false;
        DB::listen(function ($query) use ($comic, &$mutated) {
            if (! $mutated && str_contains(strtolower($query->sql), 'from "comics"') && ! str_contains(strtolower($query->sql), 'for update')) {
                $mutated = true;
                DB::table('comics')->where('id', $comic->id)->update(['approval_status' => 'draft']);
            }
        });

        $response = $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/approve");

        $this->assertTrue($mutated);
        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Chỉ có thể duyệt bộ truyện đang trong trạng thái chờ duyệt.',
        ]);

        $this->assertSame('draft', $comic->fresh()->approval_status);
    }

    /**
     * 19. Public Catalog and Reader never expose non-approved comics or non-published chapters.
     */
    public function test_public_catalog_and_reader_exclude_moderated_content(): void
    {
        $pendingComic = Comic::create([
            'title' => 'Truyện Đang Chờ Duyệt Public',
            'slug' => 'truyen-cho-duyet-public',
            'author_name' => 'Tác Giả',
            'cover_path' => 'comics/covers/pending.webp',
        ]);
        $pendingComic->approval_status = 'pending';
        $pendingComic->save();

        // 1. Not in public comics list
        $resList = $this->getJson('/api/comics');
        $resList->assertStatus(200);
        $titles = collect($resList->json('data'))->pluck('title')->all();
        $this->assertNotContains('Truyện Đang Chờ Duyệt Public', $titles);

        // 2. Direct show returns 404
        $resShow = $this->getJson("/api/comics/{$pendingComic->slug}");
        $resShow->assertStatus(404);

        // 3. Approved comic with pending chapter
        $pendingComic->approval_status = 'approved';
        $pendingComic->save();

        $pendingChapter = Chapter::create([
            'comic_id' => $pendingComic->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
            'status' => 'pending',
        ]);

        // Reader returns 404 for pending chapter
        $resChapter = $this->getJson("/api/comics/{$pendingComic->slug}/chapters/1.00");
        $resChapter->assertStatus(404);
    }

    /**
     * 20. Transaction rollback ensures database consistency on unexpected failure.
     */
    public function test_transaction_rollback_preserves_state_on_failure(): void
    {
        $comic = Comic::create([
            'title' => 'Truyện Chờ Duyệt Rollback',
            'slug' => 'truyen-cho-duyet-rollback',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/rollback.webp',
        ]);
        $comic->approval_status = 'pending';
        $comic->save();

        // Simulate a database exception during comic save inside transaction
        Comic::saving(function ($model) {
            if ($model->title === 'Truyện Chờ Duyệt Rollback' && $model->approval_status === 'approved') {
                throw new \RuntimeException('Simulated database write failure');
            }
        });

        try {
            $this->actingAs($this->admin)->postJson("/api/admin/moderation/comics/{$comic->id}/approve");
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated database write failure', $e->getMessage());
        }

        // Verify comic state was rolled back and untouched
        $fresh = $comic->fresh();
        $this->assertSame('pending', $fresh->approval_status);
        $this->assertNull($fresh->approved_by);
        $this->assertNull($fresh->approved_at);
    }
}
