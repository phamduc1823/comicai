<?php

namespace Tests\Feature;

use App\Models\Comic;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CreatorComicApiTest extends TestCase
{
    use RefreshDatabase;

    private User $creatorA;
    private User $creatorB;
    private User $admin;
    private User $reader;

    private Genre $actionGenre;
    private Genre $comedyGenre;
    private Tag $colorTag;

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

        $this->actionGenre = Genre::create([
            'name' => 'Hành Động',
            'slug' => 'hanh-dong',
            'is_active' => true,
        ]);

        $this->comedyGenre = Genre::create([
            'name' => 'Hài Hước',
            'slug' => 'hai-huoc',
            'is_active' => true,
        ]);

        $this->colorTag = Tag::create([
            'name' => 'Truyện Màu',
            'slug' => 'truyen-mau',
        ]);
    }

    /**
     * 1. Unauthenticated request cannot access creator endpoints.
     */
    public function test_unauthenticated_user_cannot_access_creator_api(): void
    {
        $response = $this->getJson('/api/creator/comics');
        $response->assertStatus(401);
    }

    /**
     * 2. Reader cannot access creator endpoints, receiving HTTP 403 Forbidden.
     */
    public function test_reader_cannot_access_creator_api(): void
    {
        $response = $this->actingAs($this->reader)->getJson('/api/creator/comics');
        $response->assertStatus(403);
    }

    /**
     * 3. Creator can successfully create a new comic in draft state with cover image.
     */
    public function test_creator_can_create_comic_in_draft_state(): void
    {
        $cover = UploadedFile::fake()->image('cover.jpg', 600, 800)->size(1500);

        $payload = [
            'title' => 'Võ Thần Tái Sinh',
            'author_name' => 'Minh Trí',
            'description' => 'Một câu chuyện tu tiên hấp dẫn.',
            'age_rating' => '16+',
            'status' => 'ongoing',
            'genres' => [$this->actionGenre->id],
            'tags' => [$this->colorTag->id],
            'cover' => $cover,
            // Malicious fields attempting privilege escalation / spoofing:
            'user_id' => $this->creatorB->id,
            'approval_status' => 'approved',
            'views_count' => 99999,
            'is_featured' => 1,
        ];

        $response = $this->actingAs($this->creatorA)->post('/api/creator/comics', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Tạo bộ truyện mới thành công.',
            'data' => [
                'title' => 'Võ Thần Tái Sinh',
                'slug' => 'vo-than-tai-sinh',
                'author_name' => 'Minh Trí',
                'approval_status' => 'draft', // Forced to draft
                'views_count' => 0,          // Protected from spoofing
                'is_featured' => false,      // Protected from spoofing
            ],
        ]);

        $comicId = $response->json('data.id');
        $comic = Comic::findOrFail($comicId);

        // Verify comic owner is authenticated Creator A, NOT spoofed Creator B
        $this->assertSame($this->creatorA->id, $comic->user_id);
        $this->assertSame('draft', $comic->approval_status);
        $this->assertNull($comic->approved_by);
        $this->assertNull($comic->approved_at);

        // Verify stored file on disk
        $this->assertTrue(str_starts_with($comic->cover_path, 'comics/covers/'));
        Storage::disk('public')->assertExists($comic->cover_path);

        // Verify synced relations
        $this->assertTrue($comic->genres->contains($this->actionGenre));
        $this->assertTrue($comic->tags->contains($this->colorTag));
    }

    /**
     * 4. Cover validation prevents invalid MIME types, extensions, and oversize files.
     */
    public function test_cover_upload_validation_rules(): void
    {
        // A. Disallowed extension/MIME (.php)
        $maliciousPhp = UploadedFile::fake()->create('shell.php', 100, 'application/x-php');
        $resPhp = $this->actingAs($this->creatorA)->postJson('/api/creator/comics', [
            'title' => 'Invalid File Comic',
            'author_name' => 'Author',
            'genres' => [$this->actionGenre->id],
            'cover' => $maliciousPhp,
        ]);
        $resPhp->assertStatus(422);
        $resPhp->assertJsonValidationErrors('cover');

        // B. Disallowed extension/MIME (.svg)
        $maliciousSvg = UploadedFile::fake()->create('vector.svg', 100, 'image/svg+xml');
        $resSvg = $this->actingAs($this->creatorA)->postJson('/api/creator/comics', [
            'title' => 'Invalid SVG Comic',
            'author_name' => 'Author',
            'genres' => [$this->actionGenre->id],
            'cover' => $maliciousSvg,
        ]);
        $resSvg->assertStatus(422);
        $resSvg->assertJsonValidationErrors('cover');

        // C. Oversize file (> 5MB)
        $oversizeFile = UploadedFile::fake()->image('huge.jpg', 3000, 3000)->size(6000);
        $resOversize = $this->actingAs($this->creatorA)->postJson('/api/creator/comics', [
            'title' => 'Oversize Comic',
            'author_name' => 'Author',
            'genres' => [$this->actionGenre->id],
            'cover' => $oversizeFile,
        ]);
        $resOversize->assertStatus(422);
        $resOversize->assertJsonValidationErrors('cover');
    }

    /**
     * 5. GET /api/creator/comics scopes listing to the authenticated creator's comics only.
     */
    public function test_creator_comics_index_is_strictly_scoped_to_owner(): void
    {
        // Comic A for Creator A
        Comic::create([
            'title' => 'Comic of A',
            'slug' => 'comic-of-a',
            'author_name' => 'Author A',
            'user_id' => $this->creatorA->id,
            'cover_path' => 'comics/covers/a.webp',
            'approval_status' => 'draft',
        ]);

        // Comic B for Creator B
        Comic::create([
            'title' => 'Comic of B',
            'slug' => 'comic-of-b',
            'author_name' => 'Author B',
            'user_id' => $this->creatorB->id,
            'cover_path' => 'comics/covers/b.webp',
            'approval_status' => 'draft',
        ]);

        $responseA = $this->actingAs($this->creatorA)->getJson('/api/creator/comics');
        $responseA->assertStatus(200);
        $responseA->assertJsonCount(1, 'data');
        $this->assertSame('Comic of A', $responseA->json('data.0.title'));

        $responseB = $this->actingAs($this->creatorB)->getJson('/api/creator/comics');
        $responseB->assertStatus(200);
        $responseB->assertJsonCount(1, 'data');
        $this->assertSame('Comic of B', $responseB->json('data.0.title'));

        // Admin can view all comics
        $responseAdmin = $this->actingAs($this->admin)->getJson('/api/creator/comics');
        $responseAdmin->assertStatus(200);
        $responseAdmin->assertJsonCount(2, 'data');
    }

    /**
     * 6. Creator A cannot view or update Creator B's comic (HTTP 403 Forbidden).
     */
    public function test_creator_cannot_view_or_update_other_creators_comic(): void
    {
        $comicB = Comic::create([
            'title' => 'Comic of Creator B',
            'slug' => 'comic-of-creator-b',
            'author_name' => 'Author B',
            'user_id' => $this->creatorB->id,
            'cover_path' => 'comics/covers/b.webp',
            'approval_status' => 'draft',
        ]);

        // Creator A tries to view B's comic
        $viewRes = $this->actingAs($this->creatorA)->getJson("/api/creator/comics/{$comicB->id}");
        $viewRes->assertStatus(403);

        // Creator A tries to update B's comic
        $updateRes = $this->actingAs($this->creatorA)->putJson("/api/creator/comics/{$comicB->id}", [
            'title' => 'Hacked Title',
        ]);
        $updateRes->assertStatus(403);

        // Creator A tries to delete B's comic
        $deleteRes = $this->actingAs($this->creatorA)->deleteJson("/api/creator/comics/{$comicB->id}");
        $deleteRes->assertStatus(403);
    }

    /**
     * 7. Submit review workflow:
     * - Fails if required fields are missing (e.g. no genres).
     * - Succeeds when all prerequisites met, transitioning draft -> pending.
     */
    public function test_submit_review_workflow(): void
    {
        $comic = Comic::create([
            'title' => 'Truyện Chờ Duyệt',
            'slug' => 'truyen-cho-duyet',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creatorA->id,
            'cover_path' => 'comics/covers/pending.webp',
            'approval_status' => 'draft',
        ]);
        // Initially no genres attached

        // 1. Attempt to submit without genres -> HTTP 422
        $resFail = $this->actingAs($this->creatorA)->postJson("/api/creator/comics/{$comic->id}/submit-review");
        $resFail->assertStatus(422);
        $resFail->assertJson([
            'status' => 'error',
            'message' => 'Bộ truyện phải có đầy đủ tiêu đề, tên tác giả, ảnh bìa và ít nhất một thể loại trước khi gửi duyệt.',
        ]);

        // 2. Attach genre and retry
        $comic->genres()->sync([$this->actionGenre->id]);
        $resSuccess = $this->actingAs($this->creatorA)->postJson("/api/creator/comics/{$comic->id}/submit-review");
        $resSuccess->assertStatus(200);
        $resSuccess->assertJson([
            'status' => 'success',
            'message' => 'Gửi duyệt bộ truyện thành công.',
            'data' => [
                'approval_status' => 'pending',
            ],
        ]);

        $this->assertSame('pending', $comic->fresh()->approval_status);

        // 3. Attempting to submit again while pending is denied by Policy (HTTP 403)
        $resDoubleSubmit = $this->actingAs($this->creatorA)->postJson("/api/creator/comics/{$comic->id}/submit-review");
        $resDoubleSubmit->assertStatus(403);
    }

    /**
     * 8. Cancel review workflow:
     * - Succeeds when status is pending, transitioning pending -> draft.
     * - Fails when status is not pending.
     */
    public function test_cancel_review_workflow(): void
    {
        $comic = Comic::create([
            'title' => 'Truyện Sắp Hủy Duyệt',
            'slug' => 'truyen-sap-huy-duyet',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creatorA->id,
            'cover_path' => 'comics/covers/test.webp',
        ]);
        $comic->approval_status = 'pending';
        $comic->save();

        // Cancel review while pending -> draft
        $resCancel = $this->actingAs($this->creatorA)->postJson("/api/creator/comics/{$comic->id}/cancel-review");
        $resCancel->assertStatus(200);
        $resCancel->assertJson([
            'status' => 'success',
            'message' => 'Rút yêu cầu duyệt bộ truyện thành công.',
            'data' => [
                'approval_status' => 'draft',
            ],
        ]);

        $this->assertSame('draft', $comic->fresh()->approval_status);

        // Cancelling again while in draft is denied by Policy (HTTP 403)
        $resCancelAgain = $this->actingAs($this->creatorA)->postJson("/api/creator/comics/{$comic->id}/cancel-review");
        $resCancelAgain->assertStatus(403);
    }

    /**
     * 9. Update workflow for APPROVED comic:
     * - Important changes are queued into pending_changes.
     * - Public fields on comics table remain unchanged.
     * - Rejects with HTTP 422 if pending_changes is already present (prevent silent overwrite).
     */
    public function test_approved_comic_update_routes_to_pending_changes(): void
    {
        $approvedComic = Comic::create([
            'title' => 'Truyện Đã Xuất Bản',
            'slug' => 'truyen-da-xuat-ban',
            'author_name' => 'Tác Giả Gốc',
            'description' => 'Mô tả gốc công khai.',
            'user_id' => $this->creatorA->id,
            'cover_path' => 'comics/covers/original_cover.webp',
            'age_rating' => '13+',
            'status' => 'ongoing',
        ]);
        $approvedComic->approval_status = 'approved';
        $approvedComic->save();
        $approvedComic->genres()->sync([$this->actionGenre->id]);
        $approvedComic->tags()->sync([$this->colorTag->id]);

        $newCover = UploadedFile::fake()->image('new_cover.webp', 600, 800);

        $payload = [
            'title' => 'Tên Mới Đề Xuất',
            'description' => 'Mô tả mới sửa đang chờ duyệt.',
            'age_rating' => '16+',
            'status' => 'completed', // Non-critical field (updates directly)
            'cover' => $newCover,
        ];

        $response = $this->actingAs($this->creatorA)->postJson("/api/creator/comics/{$approvedComic->id}", $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Bản chỉnh sửa quan trọng đã được gửi vào hàng đợi duyệt của Quản trị viên.',
        ]);

        $fresh = $approvedComic->fresh();

        // 1. Public fields MUST remain the original approved values:
        $this->assertSame('Truyện Đã Xuất Bản', $fresh->title);
        $this->assertSame('Mô tả gốc công khai.', $fresh->description);
        $this->assertSame('comics/covers/original_cover.webp', $fresh->cover_path);
        $this->assertSame('13+', $fresh->age_rating);
        $this->assertSame('approved', $fresh->approval_status);

        // 2. Non-critical status is updated directly:
        $this->assertSame('completed', $fresh->status);

        // 3. pending_changes contains the proposed changes:
        $this->assertNotNull($fresh->pending_changes);
        $this->assertSame('Tên Mới Đề Xuất', $fresh->pending_changes['title']);
        $this->assertSame('Mô tả mới sửa đang chờ duyệt.', $fresh->pending_changes['description']);
        $this->assertSame('16+', $fresh->pending_changes['age_rating']);
        $this->assertTrue(str_starts_with($fresh->pending_changes['cover_path'], 'comics/covers/'));

        // 4. Submitting another revision while pending_changes is active returns HTTP 422
        $resOverwrite = $this->actingAs($this->creatorA)->postJson("/api/creator/comics/{$approvedComic->id}", [
            'title' => 'Tên Sửa Lần Hai',
        ]);
        $resOverwrite->assertStatus(422);
        $resOverwrite->assertJson([
            'status' => 'error',
            'message' => 'Bộ truyện đang có bản chỉnh sửa chờ duyệt. Bạn không thể gửi bản sửa đổi mới cho đến khi bản hiện tại được xử lý hoặc bị hủy.',
        ]);
    }

    /**
     * 10. Update workflow for DRAFT/REJECTED comic updates fields directly and cleans up old cover.
     */
    public function test_draft_comic_update_applies_directly(): void
    {
        $oldCover = UploadedFile::fake()->image('old.jpg', 600, 800);
        $oldPath = $oldCover->storeAs('comics/covers', 'old_file.jpg', 'public');

        $draftComic = Comic::create([
            'title' => 'Bản Nháp Cũ',
            'slug' => 'ban-nhap-cu',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creatorA->id,
            'cover_path' => $oldPath,
            'approval_status' => 'draft',
        ]);

        $newCover = UploadedFile::fake()->image('new.jpg', 600, 800);

        $payload = [
            'title' => 'Bản Nháp Mới',
            'author_name' => 'Tác Giả Cập Nhật',
            'description' => 'Mô tả mới.',
            'cover' => $newCover,
            'genres' => [$this->comedyGenre->id],
        ];

        $response = $this->actingAs($this->creatorA)->postJson("/api/creator/comics/{$draftComic->id}", $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Cập nhật bộ truyện thành công.',
            'data' => [
                'title' => 'Bản Nháp Mới',
                'author_name' => 'Tác Giả Cập Nhật',
            ],
        ]);

        $fresh = $draftComic->fresh();
        $this->assertSame('Bản Nháp Mới', $fresh->title);
        $this->assertNotSame($oldPath, $fresh->cover_path);

        // Old cover was deleted, new cover exists
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($fresh->cover_path);
    }
}
