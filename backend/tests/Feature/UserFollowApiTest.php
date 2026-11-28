<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserFollowApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }
    }

    public function test_guest_cannot_follow_or_view_following(): void
    {
        $target = User::factory()->create();

        $this->postJson("/api/users/{$target->id}/follow")
            ->assertUnauthorized();

        $this->deleteJson("/api/users/{$target->id}/follow")
            ->assertUnauthorized();

        $this->getJson('/api/user/following')
            ->assertUnauthorized();
    }

    public function test_user_cannot_follow_self(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/users/{$user->id}/follow")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Bạn không thể tự theo dõi chính mình.');
    }

    public function test_user_can_follow_and_unfollow_another_user(): void
    {
        $userA = User::factory()->create(['name' => 'Alice']);
        $userB = User::factory()->create(['name' => 'Bob']);

        // Follow
        $res = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/users/{$userB->id}/follow");
        $res->assertOk()
            ->assertJsonPath('is_following', true);

        $this->assertDatabaseHas('user_follows', [
            'follower_id' => $userA->id,
            'following_id' => $userB->id,
        ]);

        // Duplicate follow is idempotent
        $resDup = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/users/{$userB->id}/follow");
        $resDup->assertOk();
        $this->assertEquals(1, UserFollow::where('follower_id', $userA->id)->where('following_id', $userB->id)->count());

        // View following list
        $resList = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/user/following');
        $resList->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $userB->id)
            ->assertJsonPath('data.0.name', 'Bob')
            ->assertJsonPath('meta.total', 1);

        // Unfollow
        $resUnfollow = $this->actingAs($userA, 'sanctum')
            ->deleteJson("/api/users/{$userB->id}/follow");
        $resUnfollow->assertOk()
            ->assertJsonPath('is_following', false);

        $this->assertDatabaseMissing('user_follows', [
            'follower_id' => $userA->id,
            'following_id' => $userB->id,
        ]);
    }

    public function test_suggested_users_excludes_self_and_already_followed(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create(['name' => 'Already Followed']);
        $creatorC = User::factory()->create(['name' => 'Creator Suggestion', 'role' => 'creator']);
        $userD = User::factory()->create(['name' => 'Active Poster']);

        Post::create([
            'user_id' => $userD->id,
            'title' => 'Recent Post',
            'content' => 'Some text',
        ]);

        // User A already follows User B
        UserFollow::create([
            'follower_id' => $userA->id,
            'following_id' => $userB->id,
        ]);

        $res = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/community/suggested-users');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();

        // Must not include userA (self)
        $this->assertNotContains($userA->id, $ids);
        // Must not include userB (already followed)
        $this->assertNotContains($userB->id, $ids);
        // Must include creatorC and userD
        $this->assertContains($creatorC->id, $ids);
        $this->assertContains($userD->id, $ids);
    }

    public function test_search_users_for_community_follow(): void
    {
        $userA = User::factory()->create(['name' => 'Nguyen Van A']);
        $userB = User::factory()->create(['name' => 'Nguyen Thi B']);
        $userC = User::factory()->create(['name' => 'Tran Van C']);

        // User A follows User B
        UserFollow::create([
            'follower_id' => $userA->id,
            'following_id' => $userB->id,
        ]);

        // Search with empty query returns empty list
        $emptyRes = $this->actingAs($userA, 'sanctum')->getJson('/api/community/users/search?q=');
        $emptyRes->assertOk()->assertJsonCount(0, 'data');

        // Search for 'Nguyen' as authenticated user A
        $res = $this->actingAs($userA, 'sanctum')->getJson('/api/community/users/search?q=Nguyen');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        // Self is excluded
        $this->assertNotContains($userA->id, $ids);
        // User B is found and has is_following = true
        $this->assertContains($userB->id, $ids);
        $foundB = collect($res->json('data'))->firstWhere('id', $userB->id);
        $this->assertTrue($foundB['is_following']);

        // User C does not match 'Nguyen'
        $this->assertNotContains($userC->id, $ids);
    }

    public function test_user_can_view_followers_list(): void
    {
        $userA = User::factory()->create(['name' => 'Alice']);
        $userB = User::factory()->create(['name' => 'Bob']);

        // User B follows User A
        UserFollow::create([
            'follower_id' => $userB->id,
            'following_id' => $userA->id,
        ]);

        $res = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/user/followers');

        $res->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $userB->id)
            ->assertJsonPath('data.0.name', 'Bob')
            ->assertJsonPath('meta.total', 1);
    }
}
