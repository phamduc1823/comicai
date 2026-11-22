<?php

namespace Tests\Feature;

use App\Models\Bookmark;
use App\Models\Comic;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserBookmarkTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;
    private User $creator;
    private User $admin;
    private User $otherUser;
    private Comic $approvedComic;
    private Comic $draftComic;

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
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => 'Password123!',
        ]);
        $this->otherUser->role = 'reader';
        $this->otherUser->save();

        $this->approvedComic = Comic::create([
            'title' => 'Approved Comic',
            'slug' => 'approved-comic',
            'author_name' => 'Author A',
            'cover_path' => 'covers/approved.webp',
            'follows_count' => 0,
        ]);
        $this->approvedComic->approval_status = 'approved';
        $this->approvedComic->save();

        $this->draftComic = Comic::create([
            'title' => 'Draft Comic',
            'slug' => 'draft-comic',
            'author_name' => 'Author B',
            'cover_path' => 'covers/draft.webp',
            'follows_count' => 0,
        ]);
        $this->draftComic->approval_status = 'draft';
        $this->draftComic->save();
    }

    /**
     * 1. Guest cannot bookmark.
     */
    public function test_guest_cannot_bookmark(): void
    {
        $response = $this->postJson("/api/comics/{$this->approvedComic->id}/bookmark");
        $response->assertStatus(401);
    }

    /**
     * 2. Reader bookmarks successfully.
     */
    public function test_reader_can_bookmark_approved_comic(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson("/api/comics/{$this->approvedComic->id}/bookmark");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Đã lưu truyện vào tủ sách.',
                'data' => [
                    'comic_id' => $this->approvedComic->id,
                    'is_bookmarked' => true,
                ],
            ]);

        $this->assertDatabaseHas('bookmarks', [
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic->id,
        ]);
    }

    /**
     * 3. Creator can bookmark.
     */
    public function test_creator_can_bookmark(): void
    {
        Sanctum::actingAs($this->creator);

        $response = $this->postJson("/api/comics/{$this->approvedComic->id}/bookmark");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'is_bookmarked' => true,
                ],
            ]);
    }

    /**
     * 4. Admin can bookmark.
     */
    public function test_admin_can_bookmark(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/comics/{$this->approvedComic->id}/bookmark");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'is_bookmarked' => true,
                ],
            ]);
    }

    /**
     * 5 & 6. Toggle unbookmarks when already bookmarked.
     */
    public function test_toggle_unbookmarks_when_already_bookmarked(): void
    {
        Sanctum::actingAs($this->reader);

        // Bookmark
        $this->postJson("/api/comics/{$this->approvedComic->id}/bookmark")->assertStatus(200);

        // Second call toggles to unbookmark
        $response = $this->postJson("/api/comics/{$this->approvedComic->id}/bookmark");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Đã gỡ truyện khỏi tủ sách.',
                'data' => [
                    'comic_id' => $this->approvedComic->id,
                    'is_bookmarked' => false,
                ],
            ]);

        $this->assertDatabaseMissing('bookmarks', [
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic->id,
        ]);
    }

    /**
     * 7. Repeated unbookmark does not corrupt data.
     */
    public function test_repeated_bookmark_operations_do_not_corrupt_data(): void
    {
        Sanctum::actingAs($this->reader);

        // Bookmark -> true
        $this->postJson("/api/comics/{$this->approvedComic->id}/bookmark")->assertJsonPath('data.is_bookmarked', true);
        // Unbookmark -> false
        $this->postJson("/api/comics/{$this->approvedComic->id}/bookmark")->assertJsonPath('data.is_bookmarked', false);
        // Bookmark again -> true
        $this->postJson("/api/comics/{$this->approvedComic->id}/bookmark")->assertJsonPath('data.is_bookmarked', true);

        $this->assertEquals(1, Bookmark::where('user_id', $this->reader->id)->where('comic_id', $this->approvedComic->id)->count());
    }

    /**
     * 8 & 9. Bookmark does not change follows_count and does not create follow record.
     */
    public function test_bookmark_does_not_affect_follows_count_or_follow_status(): void
    {
        $this->approvedComic->follows_count = 5;
        $this->approvedComic->save();

        Sanctum::actingAs($this->reader);

        $this->postJson("/api/comics/{$this->approvedComic->id}/bookmark")->assertStatus(200);

        $this->assertEquals(5, $this->approvedComic->fresh()->follows_count);
        $this->assertDatabaseMissing('follows', [
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic->id,
        ]);
    }

    /**
     * 10. Cannot bookmark unapproved comic.
     */
    public function test_cannot_bookmark_unapproved_comic(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson("/api/comics/{$this->draftComic->id}/bookmark");
        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được phát hành công khai.',
            ]);

        $this->assertDatabaseMissing('bookmarks', [
            'comic_id' => $this->draftComic->id,
        ]);
    }

    /**
     * 11. Cannot bookmark nonexistent comic.
     */
    public function test_cannot_bookmark_nonexistent_comic(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson('/api/comics/999999/bookmark');
        $response->assertStatus(404);
    }

    /**
     * 12. Bookmarks list only returns data of current authenticated user.
     */
    public function test_bookmarks_list_only_returns_current_user_bookmarks(): void
    {
        Bookmark::create([
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic->id,
            'created_at' => now(),
        ]);

        $comic2 = Comic::create([
            'title' => 'Other Comic',
            'slug' => 'other-comic',
            'author_name' => 'Author C',
            'cover_path' => 'covers/other.webp',
        ]);
        $comic2->approval_status = 'approved';
        $comic2->save();
        Bookmark::create([
            'user_id' => $this->otherUser->id,
            'comic_id' => $comic2->id,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->reader);

        $response = $this->getJson('/api/user/bookmarks');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->approvedComic->id);
    }

    /**
     * 13. Pagination works correctly on bookmarks list.
     */
    public function test_bookmarks_list_pagination(): void
    {
        Sanctum::actingAs($this->reader);

        for ($i = 1; $i <= 5; $i++) {
            $comic = Comic::create([
                'title' => "Comic $i",
                'slug' => "comic-$i",
                'author_name' => 'Author',
                'cover_path' => "covers/$i.webp",
            ]);
            $comic->approval_status = 'approved';
            $comic->save();
            Bookmark::create([
                'user_id' => $this->reader->id,
                'comic_id' => $comic->id,
                'created_at' => now()->subMinutes(10 - $i),
            ]);
        }

        $response = $this->getJson('/api/user/bookmarks?per_page=2&page=1');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    /**
     * 14. Comic Detail endpoint returns is_followed and is_bookmarked accurately.
     */
    public function test_comic_detail_returns_personalized_follow_and_bookmark_flags(): void
    {
        // 14a. Guest: both false
        $guestResponse = $this->getJson("/api/comics/{$this->approvedComic->slug}");
        $guestResponse->assertStatus(200)
            ->assertJsonPath('data.is_followed', false)
            ->assertJsonPath('data.is_bookmarked', false);

        // 14b. Reader has followed and bookmarked
        Follow::create([
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic->id,
        ]);
        Bookmark::create([
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic->id,
        ]);

        Sanctum::actingAs($this->reader);
        $readerResponse = $this->getJson("/api/comics/{$this->approvedComic->slug}");
        $readerResponse->assertStatus(200)
            ->assertJsonPath('data.is_followed', true)
            ->assertJsonPath('data.is_bookmarked', true);

        // 14c. OtherUser has NOT followed, NOT bookmarked -> both false (no leak)
        Sanctum::actingAs($this->otherUser);
        $otherResponse = $this->getJson("/api/comics/{$this->approvedComic->slug}");
        $otherResponse->assertStatus(200)
            ->assertJsonPath('data.is_followed', false)
            ->assertJsonPath('data.is_bookmarked', false);

        // 14d. OtherUser only follows
        Follow::create([
            'user_id' => $this->otherUser->id,
            'comic_id' => $this->approvedComic->id,
        ]);
        $otherResponse2 = $this->getJson("/api/comics/{$this->approvedComic->slug}");
        $otherResponse2->assertStatus(200)
            ->assertJsonPath('data.is_followed', true)
            ->assertJsonPath('data.is_bookmarked', false);
    }
}
