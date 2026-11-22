<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\ReadingHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReadingHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;
    private User $creator;
    private User $admin;
    private User $otherUser;
    private Comic $approvedComic1;
    private Comic $approvedComic2;
    private Comic $unapprovedComic;
    private Chapter $publishedChapter1_1;
    private Chapter $publishedChapter1_2;
    private Chapter $draftChapter1_3;
    private Chapter $publishedChapter2_1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = User::create([
            'name' => 'Reader User',
            'email' => 'reader@example.com',
            'password' => 'Password123!',
        ]);
        $this->reader->role = 'reader';
        $this->reader->save();

        $this->creator = User::create([
            'name' => 'Creator User',
            'email' => 'creator@example.com',
            'password' => 'Password123!',
        ]);
        $this->creator->role = 'creator';
        $this->creator->save();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'Password123!',
        ]);
        $this->admin->role = 'admin';
        $this->admin->save();

        $this->otherUser = User::create([
            'name' => 'Other Reader',
            'email' => 'other@example.com',
            'password' => 'Password123!',
        ]);
        $this->otherUser->role = 'reader';
        $this->otherUser->save();

        // Comic 1 (approved)
        $this->approvedComic1 = Comic::create([
            'title' => 'Võ Luyện Đỉnh Phong',
            'slug' => 'vo-luyen-dinh-phong',
            'author_name' => 'Mạc Mặc',
            'cover_path' => 'comics/covers/cover1.webp',
            'status' => 'ongoing',
            'user_id' => $this->creator->id,
        ]);
        $this->approvedComic1->approval_status = 'approved';
        $this->approvedComic1->save();

        // Chapters for Comic 1
        $this->publishedChapter1_1 = Chapter::create([
            'comic_id' => $this->approvedComic1->id,
            'chapter_number' => 1.00,
            'title' => 'Khởi Đầu Tu Luyện',
            'slug' => 'vo-luyen-dinh-phong-c1',
            'status' => 'published',
        ]);

        $this->publishedChapter1_2 = Chapter::create([
            'comic_id' => $this->approvedComic1->id,
            'chapter_number' => 2.00,
            'title' => 'Đột Phá Cảnh Giới',
            'slug' => 'vo-luyen-dinh-phong-c2',
            'status' => 'published',
        ]);

        $this->draftChapter1_3 = Chapter::create([
            'comic_id' => $this->approvedComic1->id,
            'chapter_number' => 3.00,
            'title' => 'Chương Bản Thảo',
            'slug' => 'vo-luyen-dinh-phong-c3',
            'status' => 'draft',
        ]);

        // Comic 2 (approved)
        $this->approvedComic2 = Comic::create([
            'title' => 'Đại Quản Gia Là Ma Hoàng',
            'slug' => 'dai-quan-gia-la-ma-hoang',
            'author_name' => 'Dạ Kiêu',
            'cover_path' => 'comics/covers/cover2.webp',
            'status' => 'ongoing',
            'user_id' => $this->creator->id,
        ]);
        $this->approvedComic2->approval_status = 'approved';
        $this->approvedComic2->save();

        $this->publishedChapter2_1 = Chapter::create([
            'comic_id' => $this->approvedComic2->id,
            'chapter_number' => 1.00,
            'title' => 'Ma Hoàng Trọng Sinh',
            'slug' => 'dai-quan-gia-c1',
            'status' => 'published',
        ]);

        // Unapproved Comic
        $this->unapprovedComic = Comic::create([
            'title' => 'Truyện Chờ Duyệt',
            'slug' => 'truyen-cho-duyet',
            'author_name' => 'Tác giả X',
            'cover_path' => 'comics/covers/cover3.webp',
            'status' => 'ongoing',
            'user_id' => $this->creator->id,
        ]);
        $this->unapprovedComic->approval_status = 'pending';
        $this->unapprovedComic->save();
    }

    /* -------------------------------------------------------------------------- */
    /* 1. Ghi nhận lịch sử đọc (Record History)                                  */
    /* -------------------------------------------------------------------------- */

    public function test_guest_cannot_record_reading_history(): void
    {
        $response = $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        $response->assertStatus(401);
    }

    public function test_reader_can_record_reading_history_first_time(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Đã lưu lịch sử đọc thành công.',
                'data' => [
                    'comic_id' => $this->approvedComic1->id,
                    'chapter_id' => $this->publishedChapter1_1->id,
                    'chapter_number' => '1.00',
                ],
            ]);

        $this->assertNotNull($response->json('data.updated_at'));

        $this->assertDatabaseHas('reading_histories', [
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic1->id,
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);
    }

    public function test_creator_and_admin_can_record_reading_history(): void
    {
        Sanctum::actingAs($this->creator);
        $resCreator = $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);
        $resCreator->assertStatus(200);

        Sanctum::actingAs($this->admin);
        $resAdmin = $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);
        $resAdmin->assertStatus(200);
    }

    public function test_reading_new_chapter_updates_existing_history_record(): void
    {
        Sanctum::actingAs($this->reader);

        // First read Chapter 1
        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        // Later read Chapter 2 of same comic
        $response = $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_2->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'comic_id' => $this->approvedComic1->id,
                    'chapter_id' => $this->publishedChapter1_2->id,
                    'chapter_number' => '2.00',
                ],
            ]);

        // Still exactly 1 history row for this user and comic
        $this->assertEquals(
            1,
            ReadingHistory::where('user_id', $this->reader->id)
                ->where('comic_id', $this->approvedComic1->id)
                ->count()
        );

        // Chapter ID is updated to chapter 2
        $this->assertDatabaseHas('reading_histories', [
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic1->id,
            'chapter_id' => $this->publishedChapter1_2->id,
        ]);
    }

    public function test_reading_same_chapter_refreshes_updated_at(): void
    {
        Sanctum::actingAs($this->reader);

        Carbon::setTestNow('2026-11-17 10:00:00');
        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        $initialRecord = ReadingHistory::where('user_id', $this->reader->id)
            ->where('comic_id', $this->approvedComic1->id)
            ->first();
        $this->assertNotNull($initialRecord);
        $initialUpdatedAt = $initialRecord->updated_at;

        // Advance time and read chapter 1 again
        Carbon::setTestNow('2026-11-17 12:30:00');
        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        $refreshedRecord = ReadingHistory::where('user_id', $this->reader->id)
            ->where('comic_id', $this->approvedComic1->id)
            ->first();
        $this->assertNotNull($refreshedRecord);
        $this->assertTrue($refreshedRecord->updated_at->gt($initialUpdatedAt));

        Carbon::setTestNow();
    }

    public function test_no_duplicate_records_for_same_user_and_comic(): void
    {
        Sanctum::actingAs($this->reader);

        for ($i = 0; $i < 5; $i++) {
            $chapterId = ($i % 2 === 0) ? $this->publishedChapter1_1->id : $this->publishedChapter1_2->id;
            $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
                'chapter_id' => $chapterId,
            ]);
        }

        $this->assertEquals(
            1,
            ReadingHistory::where('user_id', $this->reader->id)
                ->where('comic_id', $this->approvedComic1->id)
                ->count()
        );
    }

    public function test_validation_fails_without_chapter_id(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['chapter_id']);
    }

    public function test_cannot_record_history_for_nonexistent_comic(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson('/api/comics/999999/reading-history', [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        $response->assertStatus(404);
    }

    public function test_cannot_record_history_for_unapproved_comic(): void
    {
        Sanctum::actingAs($this->reader);

        $chapter = Chapter::create([
            'comic_id' => $this->unapprovedComic->id,
            'chapter_number' => 1.00,
            'slug' => 'unapproved-c1',
            'status' => 'published',
        ]);

        $response = $this->postJson("/api/comics/{$this->unapprovedComic->id}/reading-history", [
            'chapter_id' => $chapter->id,
        ]);

        $response->assertStatus(404);
    }

    public function test_cannot_record_history_for_nonexistent_chapter(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => 999999,
        ]);

        $response->assertStatus(404);
    }

    public function test_cannot_record_history_for_unpublished_chapter(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->draftChapter1_3->id,
        ]);

        $response->assertStatus(404);
    }

    public function test_cannot_record_history_when_chapter_belongs_to_different_comic(): void
    {
        Sanctum::actingAs($this->reader);

        // publishedChapter2_1 belongs to Comic 2, but we submit against Comic 1
        $response = $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter2_1->id,
        ]);

        $response->assertStatus(404);
    }

    public function test_inactive_user_cannot_record_reading_history(): void
    {
        $this->reader->is_active = false;
        $this->reader->save();

        Sanctum::actingAs($this->reader);

        $response = $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        $response->assertStatus(403);
    }

    /* -------------------------------------------------------------------------- */
    /* 2. Lấy danh sách lịch sử đọc (Retrieve History)                           */
    /* -------------------------------------------------------------------------- */

    public function test_guest_cannot_view_reading_history(): void
    {
        $response = $this->getJson('/api/user/reading-history');
        $response->assertStatus(401);
    }

    public function test_reader_only_sees_own_reading_history(): void
    {
        // Reader reads Comic 1
        Sanctum::actingAs($this->reader);
        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        // Other user reads Comic 2
        Sanctum::actingAs($this->otherUser);
        $this->postJson("/api/comics/{$this->approvedComic2->id}/reading-history", [
            'chapter_id' => $this->publishedChapter2_1->id,
        ]);

        // Check Reader's history
        Sanctum::actingAs($this->reader);
        $response = $this->getJson('/api/user/reading-history');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.comic.id', $this->approvedComic1->id)
            ->assertJsonPath('data.0.last_read_chapter.id', $this->publishedChapter1_1->id);

        // Check Other user's history
        Sanctum::actingAs($this->otherUser);
        $responseOther = $this->getJson('/api/user/reading-history');

        $responseOther->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.comic.id', $this->approvedComic2->id);
    }

    public function test_reading_history_is_sorted_by_updated_at_desc(): void
    {
        Sanctum::actingAs($this->reader);

        // Read Comic 1 at 09:00
        Carbon::setTestNow('2026-11-17 09:00:00');
        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        // Read Comic 2 at 10:00
        Carbon::setTestNow('2026-11-17 10:00:00');
        $this->postJson("/api/comics/{$this->approvedComic2->id}/reading-history", [
            'chapter_id' => $this->publishedChapter2_1->id,
        ]);

        $response = $this->getJson('/api/user/reading-history');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // First item should be Comic 2 (most recently read)
        $this->assertEquals($this->approvedComic2->id, $response->json('data.0.comic.id'));
        $this->assertEquals($this->approvedComic1->id, $response->json('data.1.comic.id'));

        Carbon::setTestNow();
    }

    public function test_reading_history_pagination(): void
    {
        Sanctum::actingAs($this->reader);

        // Create 3 additional comics
        for ($i = 3; $i <= 5; $i++) {
            $comic = Comic::create([
                'title' => "Truyện {$i}",
                'slug' => "truyen-{$i}",
                'author_name' => "Tác giả {$i}",
                'cover_path' => "comics/covers/cover{$i}.webp",
                'status' => 'ongoing',
                'user_id' => $this->creator->id,
            ]);
            $comic->approval_status = 'approved';
            $comic->save();
            $chapter = Chapter::create([
                'comic_id' => $comic->id,
                'chapter_number' => 1.00,
                'slug' => "truyen-{$i}-c1",
                'status' => 'published',
            ]);
            $this->postJson("/api/comics/{$comic->id}/reading-history", [
                'chapter_id' => $chapter->id,
            ]);
        }

        // We now have 3 history records. Test per_page=2
        $page1 = $this->getJson('/api/user/reading-history?page=1&per_page=2');
        $page1->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $page2 = $this->getJson('/api/user/reading-history?page=2&per_page=2');
        $page2->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_reading_history_excludes_unapproved_comics(): void
    {
        Sanctum::actingAs($this->reader);

        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        // Unapprove comic
        $this->approvedComic1->approval_status = 'rejected';
        $this->approvedComic1->save();

        $response = $this->getJson('/api/user/reading-history');
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_reading_history_excludes_unpublished_chapters(): void
    {
        Sanctum::actingAs($this->reader);

        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        // Unpublish chapter
        $this->publishedChapter1_1->status = 'draft';
        $this->publishedChapter1_1->save();

        $response = $this->getJson('/api/user/reading-history');
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_reading_history_response_structure_is_complete(): void
    {
        Sanctum::actingAs($this->reader);

        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        $response = $this->getJson('/api/user/reading-history');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'comic' => [
                            'id',
                            'title',
                            'slug',
                            'cover_url',
                            'author_name',
                            'status',
                            'published_chapters_count',
                        ],
                        'last_read_chapter' => [
                            'id',
                            'chapter_number',
                            'title',
                            'slug',
                        ],
                        'updated_at',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
                'links',
            ]);
    }

    /* -------------------------------------------------------------------------- */
    /* 3. Xóa một mục lịch sử (Delete Single Comic History)                       */
    /* -------------------------------------------------------------------------- */

    public function test_reader_can_delete_single_comic_from_history(): void
    {
        Sanctum::actingAs($this->reader);

        // Read Comic 1 and Comic 2
        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);
        $this->postJson("/api/comics/{$this->approvedComic2->id}/reading-history", [
            'chapter_id' => $this->publishedChapter2_1->id,
        ]);

        $this->assertEquals(2, ReadingHistory::where('user_id', $this->reader->id)->count());

        // Delete Comic 1
        $response = $this->deleteJson("/api/comics/{$this->approvedComic1->id}/reading-history");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Đã xóa lịch sử đọc của bộ truyện.',
            ]);

        $this->assertEquals(1, ReadingHistory::where('user_id', $this->reader->id)->count());
        $this->assertDatabaseMissing('reading_histories', [
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic1->id,
        ]);
        $this->assertDatabaseHas('reading_histories', [
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic2->id,
        ]);
    }

    public function test_delete_single_does_not_affect_other_users_history(): void
    {
        // Both Reader and OtherUser read Comic 1
        Sanctum::actingAs($this->reader);
        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        Sanctum::actingAs($this->otherUser);
        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        // Reader deletes Comic 1
        Sanctum::actingAs($this->reader);
        $this->deleteJson("/api/comics/{$this->approvedComic1->id}/reading-history");

        // OtherUser must still have Comic 1
        $this->assertDatabaseHas('reading_histories', [
            'user_id' => $this->otherUser->id,
            'comic_id' => $this->approvedComic1->id,
        ]);
    }

    public function test_delete_single_is_idempotent_when_comic_not_in_history(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->deleteJson('/api/comics/999999/reading-history');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Đã xóa lịch sử đọc của bộ truyện.',
            ]);
    }

    /* -------------------------------------------------------------------------- */
    /* 4. Xóa toàn bộ lịch sử (Clear All History)                                 */
    /* -------------------------------------------------------------------------- */

    public function test_reader_can_clear_all_reading_history(): void
    {
        Sanctum::actingAs($this->reader);

        // Read Comic 1 and Comic 2
        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);
        $this->postJson("/api/comics/{$this->approvedComic2->id}/reading-history", [
            'chapter_id' => $this->publishedChapter2_1->id,
        ]);

        $this->assertEquals(2, ReadingHistory::where('user_id', $this->reader->id)->count());

        $response = $this->deleteJson('/api/user/reading-history');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Đã xóa toàn bộ lịch sử đọc.',
            ]);

        $this->assertEquals(0, ReadingHistory::where('user_id', $this->reader->id)->count());
    }

    public function test_clear_all_does_not_affect_other_users_history(): void
    {
        // Both Reader and OtherUser have history
        Sanctum::actingAs($this->reader);
        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        Sanctum::actingAs($this->otherUser);
        $this->postJson("/api/comics/{$this->approvedComic2->id}/reading-history", [
            'chapter_id' => $this->publishedChapter2_1->id,
        ]);

        // Reader clears all history
        Sanctum::actingAs($this->reader);
        $this->deleteJson('/api/user/reading-history');

        // OtherUser's history must remain intact
        $this->assertEquals(1, ReadingHistory::where('user_id', $this->otherUser->id)->count());
        $this->assertDatabaseHas('reading_histories', [
            'user_id' => $this->otherUser->id,
            'comic_id' => $this->approvedComic2->id,
        ]);
    }

    public function test_clear_all_is_idempotent_when_history_empty(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->deleteJson('/api/user/reading-history');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Đã xóa toàn bộ lịch sử đọc.',
            ]);
    }

    /* -------------------------------------------------------------------------- */
    /* 5. Lấy lịch sử đọc theo Comic ID (GET /api/comics/{comicId}/reading-history)*/
    /* -------------------------------------------------------------------------- */

    public function test_authenticated_user_can_get_reading_history_for_specific_comic(): void
    {
        Sanctum::actingAs($this->reader);

        // Record history for Comic 1
        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_2->id,
        ]);

        $response = $this->getJson("/api/comics/{$this->approvedComic1->id}/reading-history");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'comic_id' => $this->approvedComic1->id,
                    'chapter_id' => $this->publishedChapter1_2->id,
                    'chapter_number' => '2.00',
                    'title' => $this->publishedChapter1_2->title,
                    'slug' => $this->publishedChapter1_2->slug,
                ],
            ]);

        $this->assertIsString($response->json('data.chapter_number'));
        $this->assertNotNull($response->json('data.updated_at'));
    }

    public function test_user_b_cannot_see_user_a_reading_history_for_same_comic(): void
    {
        // Reader (User A) reads Comic 1
        Sanctum::actingAs($this->reader);
        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        // OtherUser (User B) queries Comic 1
        Sanctum::actingAs($this->otherUser);
        $response = $this->getJson("/api/comics/{$this->approvedComic1->id}/reading-history");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => null,
            ]);
    }

    public function test_comic_without_reading_history_returns_null_data(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->getJson("/api/comics/{$this->approvedComic2->id}/reading-history");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => null,
            ]);
    }

    public function test_unapproved_or_nonexistent_comic_returns_404_for_reading_history(): void
    {
        Sanctum::actingAs($this->reader);

        // Non-existent comic
        $response1 = $this->getJson('/api/comics/999999/reading-history');
        $response1->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được duyệt phát hành.',
            ]);

        // Unapproved comic
        $response2 = $this->getJson("/api/comics/{$this->unapprovedComic->id}/reading-history");
        $response2->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được duyệt phát hành.',
            ]);
    }

    public function test_comic_with_unpublished_or_deleted_chapter_returns_null_data(): void
    {
        Sanctum::actingAs($this->reader);

        // Create reading history directly
        $history = ReadingHistory::create([
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic1->id,
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        // Change chapter status to draft
        $this->publishedChapter1_1->status = 'draft';
        $this->publishedChapter1_1->save();

        $response = $this->getJson("/api/comics/{$this->approvedComic1->id}/reading-history");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => null,
            ]);
    }

    public function test_guest_cannot_access_comic_reading_history(): void
    {
        $response = $this->getJson("/api/comics/{$this->approvedComic1->id}/reading-history");

        $response->assertStatus(401);
    }

    public function test_comic_reading_history_chapter_number_is_strict_string(): void
    {
        Sanctum::actingAs($this->reader);

        $this->postJson("/api/comics/{$this->approvedComic1->id}/reading-history", [
            'chapter_id' => $this->publishedChapter1_1->id,
        ]);

        $response = $this->getJson("/api/comics/{$this->approvedComic1->id}/reading-history");

        $response->assertStatus(200);
        $this->assertSame('1.00', $response->json('data.chapter_number'));
        $this->assertIsString($response->json('data.chapter_number'));
    }
}
