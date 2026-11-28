<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityRankingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }
    }

    public function test_rankings_return_empty_array_when_no_data(): void
    {
        $responseLiked = $this->getJson('/api/community/rankings/top-liked');
        $responseLiked->assertOk()
            ->assertJsonPath('data', []);

        $responseCommented = $this->getJson('/api/community/rankings/top-commented');
        $responseCommented->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_top_liked_calculates_likes_in_last_7_days_and_limits_to_5(): void
    {
        $author = User::factory()->create(['name' => 'Author One']);
        $likers = User::factory()->count(10)->create();

        // Create 6 posts
        $posts = [];
        for ($i = 0; $i < 6; $i++) {
            $posts[$i] = Post::create([
                'user_id' => $author->id,
                'title' => "Post Number {$i}",
                'content' => "Content for post {$i}",
            ]);
        }

        // Post 0 gets 6 recent likes (within 7 days)
        for ($k = 0; $k < 6; $k++) {
            DB::table('likes')->insert([
                'user_id' => $likers[$k]->id,
                'likeable_type' => 'post',
                'likeable_id' => $posts[0]->id,
                'created_at' => now()->subDays(2),
            ]);
        }

        // Post 1 gets 4 recent likes + 5 OLD likes (older than 7 days)
        for ($k = 0; $k < 4; $k++) {
            DB::table('likes')->insert([
                'user_id' => $likers[$k]->id,
                'likeable_type' => 'post',
                'likeable_id' => $posts[1]->id,
                'created_at' => now()->subDays(1),
            ]);
        }
        for ($k = 4; $k < 9; $k++) {
            DB::table('likes')->insert([
                'user_id' => $likers[$k]->id,
                'likeable_type' => 'post',
                'likeable_id' => $posts[1]->id,
                'created_at' => now()->subDays(10), // Should NOT count
            ]);
        }

        // Post 2 gets 2 recent likes
        for ($k = 0; $k < 2; $k++) {
            DB::table('likes')->insert([
                'user_id' => $likers[$k]->id,
                'likeable_type' => 'post',
                'likeable_id' => $posts[2]->id,
                'created_at' => now()->subDays(3),
            ]);
        }

        $res = $this->getJson('/api/community/rankings/top-liked');
        $res->assertOk();

        $data = $res->json('data');
        $this->assertCount(3, $data);

        // Rank 1 should be Post 0 with 6 likes
        $this->assertEquals(1, $data[0]['rank']);
        $this->assertEquals($posts[0]->id, $data[0]['id']);
        $this->assertEquals(6, $data[0]['weekly_count']);

        // Rank 2 should be Post 1 with 4 recent likes (NOT 9)
        $this->assertEquals(2, $data[1]['rank']);
        $this->assertEquals($posts[1]->id, $data[1]['id']);
        $this->assertEquals(4, $data[1]['weekly_count']);

        // Rank 3 should be Post 2 with 2 likes
        $this->assertEquals(3, $data[2]['rank']);
        $this->assertEquals($posts[2]->id, $data[2]['id']);
        $this->assertEquals(2, $data[2]['weekly_count']);
    }

    public function test_top_commented_calculates_comments_in_last_7_days(): void
    {
        $author = User::factory()->create(['name' => 'Author One']);
        $commenters = User::factory()->count(10)->create();

        $postA = Post::create([
            'user_id' => $author->id,
            'title' => 'Post A',
            'content' => 'Content A',
        ]);
        $postB = Post::create([
            'user_id' => $author->id,
            'title' => 'Post B',
            'content' => 'Content B',
        ]);

        // Post A has 3 recent comments
        for ($i = 0; $i < 3; $i++) {
            DB::table('comments')->insert([
                'user_id' => $commenters[$i]->id,
                'commentable_type' => 'post',
                'commentable_id' => $postA->id,
                'content' => "Recent comment {$i}",
                'created_at' => now()->subDays(1),
            ]);
        }

        // Post B has 1 recent comment + 5 old comments
        DB::table('comments')->insert([
            'user_id' => $commenters[0]->id,
            'commentable_type' => 'post',
            'commentable_id' => $postB->id,
            'content' => 'Recent comment',
            'created_at' => now()->subDays(2),
        ]);
        for ($i = 1; $i <= 5; $i++) {
            DB::table('comments')->insert([
                'user_id' => $commenters[$i]->id,
                'commentable_type' => 'post',
                'commentable_id' => $postB->id,
                'content' => "Old comment {$i}",
                'created_at' => now()->subDays(15),
            ]);
        }

        $res = $this->getJson('/api/community/rankings/top-commented');
        $res->assertOk();

        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals($postA->id, $data[0]['id']);
        $this->assertEquals(3, $data[0]['weekly_count']);
        $this->assertEquals($postB->id, $data[1]['id']);
        $this->assertEquals(1, $data[1]['weekly_count']);
    }
}
