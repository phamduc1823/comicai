<?php

namespace Tests\Feature;

use App\Models\Comic;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProfileApiTest extends TestCase
{
    use RefreshDatabase;

    private function createComic(array $attributes = []): Comic
    {
        $comic = new Comic();
        $comic->title = $attributes['title'] ?? 'Truyện Mẫu ' . uniqid();
        $comic->slug = $attributes['slug'] ?? 'truyen-mau-' . uniqid();
        $comic->author_name = $attributes['author_name'] ?? 'Tác Giả Demo';
        $comic->user_id = $attributes['user_id'];
        $comic->description = $attributes['description'] ?? 'Mô tả truyện mẫu.';
        $comic->cover_path = $attributes['cover_path'] ?? 'placeholders/covers/sample.webp';
        $comic->age_rating = $attributes['age_rating'] ?? '13+';
        $comic->status = $attributes['status'] ?? 'ongoing';
        $comic->approval_status = $attributes['approval_status'] ?? 'approved';
        $comic->save();

        return $comic;
    }

    public function test_guest_can_get_creator_profile_with_stats(): void
    {
        $creator = User::factory()->create([
            'name' => 'Creator Author',
            'is_active' => true,
        ]);
        $creator->role = 'creator';
        $creator->save();

        // 2 approved comics, 1 draft comic
        $this->createComic([
            'user_id' => $creator->id,
            'approval_status' => 'approved',
        ]);
        $this->createComic([
            'user_id' => $creator->id,
            'approval_status' => 'approved',
        ]);
        $this->createComic([
            'user_id' => $creator->id,
            'approval_status' => 'draft',
        ]);

        // 3 posts
        Post::factory()->count(3)->create([
            'user_id' => $creator->id,
        ]);

        $response = $this->getJson("/api/users/{$creator->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'role',
                    'avatar_path',
                    'avatar_url',
                    'created_at',
                    'stats' => [
                        'posts_count',
                        'comics_count',
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertEquals($creator->id, $data['id']);
        $this->assertEquals('Creator Author', $data['name']);
        $this->assertEquals('creator', $data['role']);
        $this->assertEquals(2, $data['stats']['comics_count']); // Only approved counted
        $this->assertEquals(3, $data['stats']['posts_count']);

        // Assert strictly NO sensitive fields
        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('wallet', $data);
        $this->assertArrayNotHasKey('balance', $data);
    }

    public function test_guest_can_get_reader_profile_with_stats(): void
    {
        $reader = User::factory()->create([
            'name' => 'Regular Reader',
            'is_active' => true,
        ]);
        $reader->role = 'reader';
        $reader->save();

        Post::factory()->count(2)->create([
            'user_id' => $reader->id,
        ]);

        $response = $this->getJson("/api/users/{$reader->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals($reader->id, $data['id']);
        $this->assertEquals('Regular Reader', $data['name']);
        $this->assertEquals('reader', $data['role']);
        $this->assertEquals(0, $data['stats']['comics_count']); // Readers have 0 comics
        $this->assertEquals(2, $data['stats']['posts_count']);

        $this->assertArrayNotHasKey('email', $data);
    }

    public function test_profile_returns_404_for_nonexistent_or_inactive_user(): void
    {
        // Nonexistent
        $this->getJson('/api/users/999999')
            ->assertStatus(404);

        // Inactive user
        $inactiveUser = User::factory()->create([
            'is_active' => false,
        ]);

        $this->getJson("/api/users/{$inactiveUser->id}")
            ->assertStatus(404);
    }

    public function test_get_creator_public_comics_only_returns_approved(): void
    {
        $creator = User::factory()->create([
            'is_active' => true,
        ]);
        $creator->role = 'creator';
        $creator->save();

        $approvedComic = $this->createComic([
            'user_id' => $creator->id,
            'title' => 'Approved Masterpiece',
            'approval_status' => 'approved',
        ]);

        $draftComic = $this->createComic([
            'user_id' => $creator->id,
            'title' => 'Secret Draft Comic',
            'approval_status' => 'draft',
        ]);

        $pendingComic = $this->createComic([
            'user_id' => $creator->id,
            'title' => 'Pending Review Comic',
            'approval_status' => 'pending',
        ]);

        $response = $this->getJson("/api/users/{$creator->id}/comics");

        $response->assertStatus(200);
        $comics = $response->json('data');

        $this->assertCount(1, $comics);
        $this->assertEquals($approvedComic->id, $comics[0]['id']);
        $this->assertEquals('Approved Masterpiece', $comics[0]['title']);

        // Reader has no author comics
        $reader = User::factory()->create([
            'is_active' => true,
        ]);
        $reader->role = 'reader';
        $reader->save();

        $readerResponse = $this->getJson("/api/users/{$reader->id}/comics");
        $readerResponse->assertStatus(200);
        $this->assertCount(0, $readerResponse->json('data'));
    }

    public function test_get_user_public_posts_paginated(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);
        $user->role = 'reader';
        $user->save();

        $post1 = Post::factory()->create([
            'user_id' => $user->id,
            'content' => 'First post',
            'created_at' => now()->subDay(),
        ]);

        $post2 = Post::factory()->create([
            'user_id' => $user->id,
            'content' => 'Second post',
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/users/{$user->id}/posts");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'content',
                        'user',
                        'likes_count',
                        'comments_count',
                        'created_at',
                    ],
                ],
                'links',
                'meta',
            ]);

        $posts = $response->json('data');
        $this->assertCount(2, $posts);
        // Latest first
        $this->assertEquals($post2->id, $posts[0]['id']);
        $this->assertEquals($post1->id, $posts[1]['id']);
    }

    public function test_authenticated_user_sees_is_liked_on_profile_posts(): void
    {
        $author = User::factory()->create(['is_active' => true]);
        $viewer = User::factory()->create(['is_active' => true]);

        $post = Post::factory()->create(['user_id' => $author->id]);

        // Viewer likes the post
        Like::create([
            'user_id' => $viewer->id,
            'likeable_type' => 'post',
            'likeable_id' => $post->id,
        ]);

        // Guest viewer sees is_liked === false
        $guestRes = $this->getJson("/api/users/{$author->id}/posts");
        $guestRes->assertStatus(200);
        $this->assertFalse($guestRes->json('data.0.is_liked'));

        // Authenticated viewer sees is_liked === true
        $authRes = $this->actingAs($viewer, 'sanctum')->getJson("/api/users/{$author->id}/posts");
        $authRes->assertStatus(200);
        $this->assertTrue($authRes->json('data.0.is_liked'));
    }
}
