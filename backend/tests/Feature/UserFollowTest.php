<?php

namespace Tests\Feature;

use App\Models\Comic;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserFollowTest extends TestCase
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
     * 1. Guest cannot follow.
     */
    public function test_guest_cannot_follow(): void
    {
        $response = $this->postJson("/api/comics/{$this->approvedComic->id}/follow");
        $response->assertStatus(401);
    }

    /**
     * 2. Reader follows successfully.
     */
    public function test_reader_can_follow_approved_comic(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson("/api/comics/{$this->approvedComic->id}/follow");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Đã theo dõi truyện thành công.',
                'data' => [
                    'comic_id' => $this->approvedComic->id,
                    'is_followed' => true,
                    'follows_count' => 1,
                ],
            ]);

        $this->assertDatabaseHas('follows', [
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic->id,
        ]);

        $this->assertEquals(1, $this->approvedComic->fresh()->follows_count);
    }

    /**
     * 3. Creator can follow.
     */
    public function test_creator_can_follow(): void
    {
        Sanctum::actingAs($this->creator);

        $response = $this->postJson("/api/comics/{$this->approvedComic->id}/follow");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'is_followed' => true,
                    'follows_count' => 1,
                ],
            ]);
    }

    /**
     * 4. Admin can follow.
     */
    public function test_admin_can_follow(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/comics/{$this->approvedComic->id}/follow");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'is_followed' => true,
                    'follows_count' => 1,
                ],
            ]);
    }

    /**
     * 5 & 6. Toggle unfollows when called second time.
     */
    public function test_toggle_unfollows_when_already_followed(): void
    {
        Sanctum::actingAs($this->reader);

        // Follow
        $this->postJson("/api/comics/{$this->approvedComic->id}/follow")->assertStatus(200);
        $this->assertEquals(1, $this->approvedComic->fresh()->follows_count);

        // Second call toggles to unfollow
        $response = $this->postJson("/api/comics/{$this->approvedComic->id}/follow");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Đã bỏ theo dõi truyện thành công.',
                'data' => [
                    'comic_id' => $this->approvedComic->id,
                    'is_followed' => false,
                    'follows_count' => 0,
                ],
            ]);

        $this->assertDatabaseMissing('follows', [
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic->id,
        ]);
        $this->assertEquals(0, $this->approvedComic->fresh()->follows_count);
    }

    /**
     * 7. Follows count never drops below zero.
     */
    public function test_follows_count_never_drops_below_zero(): void
    {
        Sanctum::actingAs($this->reader);

        // Force comic follows_count = 0 in DB
        $this->approvedComic->follows_count = 0;
        $this->approvedComic->save();

        // Create follow record manually without incrementing count
        Follow::create([
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic->id,
        ]);

        // Toggle to unfollow
        $response = $this->postJson("/api/comics/{$this->approvedComic->id}/follow");
        $response->assertStatus(200);

        $this->assertEquals(0, $this->approvedComic->fresh()->follows_count);
    }

    /**
     * 8 & 9. Follows count increments and decrements by exactly one.
     */
    public function test_follows_count_increments_and_decrements_atomically(): void
    {
        $this->approvedComic->follows_count = 10;
        $this->approvedComic->save();

        Sanctum::actingAs($this->reader);

        // Follow -> 11
        $resFollow = $this->postJson("/api/comics/{$this->approvedComic->id}/follow");
        $resFollow->assertJsonPath('data.follows_count', 11);
        $this->assertEquals(11, $this->approvedComic->fresh()->follows_count);

        // Unfollow -> 10
        $resUnfollow = $this->postJson("/api/comics/{$this->approvedComic->id}/follow");
        $resUnfollow->assertJsonPath('data.follows_count', 10);
        $this->assertEquals(10, $this->approvedComic->fresh()->follows_count);
    }

    /**
     * 10. Cannot follow unapproved comic.
     */
    public function test_cannot_follow_unapproved_comic(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson("/api/comics/{$this->draftComic->id}/follow");
        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được phát hành công khai.',
            ]);

        $this->assertDatabaseMissing('follows', [
            'comic_id' => $this->draftComic->id,
        ]);
    }

    /**
     * 11. Cannot follow nonexistent comic.
     */
    public function test_cannot_follow_nonexistent_comic(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson('/api/comics/999999/follow');
        $response->assertStatus(404);
    }

    /**
     * 12. Follows list only returns data of current authenticated user.
     */
    public function test_follows_list_only_returns_current_user_follows(): void
    {
        // Reader follows approvedComic
        Follow::create([
            'user_id' => $this->reader->id,
            'comic_id' => $this->approvedComic->id,
            'created_at' => now(),
        ]);

        // OtherUser follows second comic
        $comic2 = Comic::create([
            'title' => 'Other Comic',
            'slug' => 'other-comic',
            'author_name' => 'Author C',
            'cover_path' => 'covers/other.webp',
        ]);
        $comic2->approval_status = 'approved';
        $comic2->save();
        Follow::create([
            'user_id' => $this->otherUser->id,
            'comic_id' => $comic2->id,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->reader);

        $response = $this->getJson('/api/user/follows');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->approvedComic->id);
    }

    /**
     * 13. Pagination works correctly on follows list.
     */
    public function test_follows_list_pagination(): void
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

            Follow::create([
                'user_id' => $this->reader->id,
                'comic_id' => $comic->id,
                'created_at' => now()->subMinutes(10 - $i),
            ]);
        }

        $response = $this->getJson('/api/user/follows?per_page=2&page=1');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }
}
