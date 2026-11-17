<?php

namespace Tests\Feature;

use App\Models\Comic;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostApiTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private User $creatorOther;
    private User $admin;
    private User $reader;
    private Comic $approvedComic;
    private Comic $draftComic;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->creator = User::create([
            'name' => 'Creator User',
            'email' => 'creator@example.com',
            'password' => 'Password123!',
        ]);
        $this->creator->role = 'creator';
        $this->creator->save();

        $this->creatorOther = User::create([
            'name' => 'Other Creator',
            'email' => 'other_creator@example.com',
            'password' => 'Password123!',
        ]);
        $this->creatorOther->role = 'creator';
        $this->creatorOther->save();

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
        $this->reader->role = 'reader';
        $this->reader->save();

        $this->approvedComic = Comic::create([
            'title' => 'Approved Comic',
            'slug' => 'approved-comic',
            'author_name' => 'Author A',
            'cover_path' => 'covers/approved.webp',
        ]);
        $this->approvedComic->approval_status = 'approved';
        $this->approvedComic->save();

        $this->draftComic = Comic::create([
            'title' => 'Draft Comic',
            'slug' => 'draft-comic',
            'author_name' => 'Author B',
            'cover_path' => 'covers/draft.webp',
        ]);
        $this->draftComic->approval_status = 'draft';
        $this->draftComic->save();
    }

    /**
     * Test guest can view public post feed and post detail.
     */
    public function test_guest_can_view_posts_index_and_post_detail(): void
    {
        $post = Post::factory()->create([
            'user_id' => $this->creator->id,
            'comic_id' => $this->approvedComic->id,
            'content' => 'Bài đăng công khai đầu tiên.',
        ]);

        $responseIndex = $this->getJson('/api/posts');
        $responseIndex->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'content',
                        'image_url',
                        'likes_count',
                        'comments_count',
                        'is_liked',
                        'user' => ['id', 'name', 'avatar_url', 'role'],
                        'comic' => ['id', 'title', 'slug', 'cover_url'],
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ]);

        $responseShow = $this->getJson("/api/posts/{$post->id}");
        $responseShow->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $post->id,
                    'content' => 'Bài đăng công khai đầu tiên.',
                    'is_liked' => false,
                    'user' => [
                        'id' => $this->creator->id,
                        'name' => 'Creator User',
                    ],
                    'comic' => [
                        'id' => $this->approvedComic->id,
                        'title' => 'Approved Comic',
                    ],
                ],
            ]);
    }

    /**
     * Test guest cannot create, update, delete, or like posts.
     */
    public function test_guest_cannot_create_update_delete_or_like_posts(): void
    {
        $post = Post::factory()->create(['user_id' => $this->creator->id]);

        $this->postJson('/api/posts', ['content' => 'Guest attempt'])->assertStatus(401);
        $this->putJson("/api/posts/{$post->id}", ['content' => 'Guest update'])->assertStatus(401);
        $this->deleteJson("/api/posts/{$post->id}")->assertStatus(401);
        $this->postJson("/api/posts/{$post->id}/like")->assertStatus(401);
    }

    /**
     * Test reader cannot create, update, or delete posts, but can like/unlike.
     */
    public function test_reader_cannot_create_update_or_delete_posts_but_can_like(): void
    {
        $post = Post::factory()->create(['user_id' => $this->creator->id]);

        // Reader cannot create
        $this->actingAs($this->reader)
            ->postJson('/api/posts', [
                'content' => 'Reader muốn đăng bài',
            ])
            ->assertStatus(403);

        // Reader cannot update
        $this->actingAs($this->reader)
            ->putJson("/api/posts/{$post->id}", [
                'content' => 'Reader muốn sửa bài',
            ])
            ->assertStatus(403);

        // Reader cannot delete
        $this->actingAs($this->reader)
            ->deleteJson("/api/posts/{$post->id}")
            ->assertStatus(403);

        // Reader CAN like
        $this->actingAs($this->reader)
            ->postJson("/api/posts/{$post->id}/like")
            ->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'id' => $post->id,
                    'liked' => true,
                    'likes_count' => 1,
                ],
            ]);
    }

    /**
     * Test creator can create post with image and approved comic reference.
     */
    public function test_creator_can_create_post_with_image_and_comic(): void
    {
        $imageFile = UploadedFile::fake()->image('my_art.png', 600, 600);

        $response = $this->actingAs($this->creator)
            ->postJson('/api/posts', [
                'content' => 'Hôm nay vừa vẽ xong tranh bìa mới!',
                'comic_id' => $this->approvedComic->id,
                'image' => $imageFile,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'content' => 'Hôm nay vừa vẽ xong tranh bìa mới!',
                    'likes_count' => 0,
                    'comments_count' => 0,
                    'comic' => [
                        'id' => $this->approvedComic->id,
                        'title' => 'Approved Comic',
                    ],
                ],
            ]);

        $post = Post::first();
        $this->assertNotNull($post);
        $this->assertNotNull($post->image_path);
        $this->assertStringStartsWith('posts/', $post->image_path);
        $this->assertTrue(Storage::disk('public')->exists($post->image_path));
    }

    /**
     * Test post validation rules for content, comic, and image.
     */
    public function test_post_validation_rules(): void
    {
        // 1. Content empty or whitespace-only
        $this->actingAs($this->creator)
            ->postJson('/api/posts', [
                'content' => '   ',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content']);

        // 2. Content exceeding 5000 chars
        $this->actingAs($this->creator)
            ->postJson('/api/posts', [
                'content' => str_repeat('a', 5001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content']);

        // 3. Comic not found or not approved (draft)
        $this->actingAs($this->creator)
            ->postJson('/api/posts', [
                'content' => 'Nội dung hợp lệ',
                'comic_id' => $this->draftComic->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['comic_id']);

        // 4. Invalid file type (e.g. text file instead of image)
        $fakeDoc = UploadedFile::fake()->create('document.pdf', 500);
        $this->actingAs($this->creator)
            ->postJson('/api/posts', [
                'content' => 'Nội dung hợp lệ',
                'image' => $fakeDoc,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);

        // 5. Image too large (> 2 MB)
        $largeImage = UploadedFile::fake()->image('large.png')->size(3000);
        $this->actingAs($this->creator)
            ->postJson('/api/posts', [
                'content' => 'Nội dung hợp lệ',
                'image' => $largeImage,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    /**
     * Test creator can update own post and manage image replacement/removal.
     */
    public function test_creator_can_update_own_post_and_image(): void
    {
        $oldImage = UploadedFile::fake()->image('old.webp');
        $oldImagePath = $oldImage->storeAs('posts', 'old_sample.webp', 'public');

        $post = Post::factory()->create([
            'user_id' => $this->creator->id,
            'content' => 'Nội dung cũ',
            'image_path' => $oldImagePath,
        ]);

        $this->assertTrue(Storage::disk('public')->exists($oldImagePath));

        // 1. Update text content
        $this->actingAs($this->creator)
            ->putJson("/api/posts/{$post->id}", [
                'content' => 'Nội dung đã được chỉnh sửa',
            ])
            ->assertStatus(200)
            ->assertJson([
                'data' => [
                    'content' => 'Nội dung đã được chỉnh sửa',
                ],
            ]);

        // 2. Replace image with a new one
        $newImage = UploadedFile::fake()->image('new.png');
        $this->actingAs($this->creator)
            ->postJson("/api/posts/{$post->id}", [
                '_method' => 'PUT',
                'content' => 'Nội dung với ảnh mới',
                'image' => $newImage,
            ])
            ->assertStatus(200);

        $post->refresh();
        $this->assertNotEquals($oldImagePath, $post->image_path);
        $this->assertFalse(Storage::disk('public')->exists($oldImagePath)); // Old image deleted
        $this->assertTrue(Storage::disk('public')->exists($post->image_path)); // New image saved

        // 3. Remove image with remove_image = true
        $currentImage = $post->image_path;
        $this->actingAs($this->creator)
            ->putJson("/api/posts/{$post->id}", [
                'remove_image' => true,
            ])
            ->assertStatus(200);

        $post->refresh();
        $this->assertNull($post->image_path);
        $this->assertFalse(Storage::disk('public')->exists($currentImage)); // File cleaned up
    }

    /**
     * Test cannot update someone else's post even as an admin (author-only editing).
     */
    public function test_cannot_update_someone_elses_post(): void
    {
        $post = Post::factory()->create([
            'user_id' => $this->creator->id,
            'content' => 'Bài của Creator A',
        ]);

        // Other creator cannot update
        $this->actingAs($this->creatorOther)
            ->putJson("/api/posts/{$post->id}", [
                'content' => 'Creator B cố sửa',
            ])
            ->assertStatus(403);

        // Admin cannot edit author's content
        $this->actingAs($this->admin)
            ->putJson("/api/posts/{$post->id}", [
                'content' => 'Admin cố sửa',
            ])
            ->assertStatus(403);
    }

    /**
     * Test author and admin can delete post, while others are forbidden.
     */
    public function test_author_and_admin_can_delete_post(): void
    {
        $postA = Post::factory()->create(['user_id' => $this->creator->id]);
        $postB = Post::factory()->create(['user_id' => $this->creatorOther->id]);

        // Other creator cannot delete postA
        $this->actingAs($this->creatorOther)
            ->deleteJson("/api/posts/{$postA->id}")
            ->assertStatus(403);

        // Author can delete own postA
        $this->actingAs($this->creator)
            ->deleteJson("/api/posts/{$postA->id}")
            ->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Bài đăng đã được xóa thành công.',
            ]);
        $this->assertDatabaseMissing('posts', ['id' => $postA->id]);

        // Admin can delete postB (moderation)
        $this->actingAs($this->admin)
            ->deleteJson("/api/posts/{$postB->id}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('posts', ['id' => $postB->id]);
    }

    /**
     * Test deleting post cleans up image and polymorphic relations.
     */
    public function test_deleting_post_cleans_up_image_and_polymorphic_relations(): void
    {
        $imagePath = 'posts/cascade_test.webp';
        Storage::disk('public')->put($imagePath, 'dummy-data');

        $post = Post::factory()->create([
            'user_id' => $this->creator->id,
            'image_path' => $imagePath,
        ]);

        $comment = Comment::create([
            'user_id' => $this->reader->id,
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
            'content' => 'Bình luận trên bài',
        ]);

        $like = Like::create([
            'user_id' => $this->reader->id,
            'likeable_type' => 'post',
            'likeable_id' => $post->id,
        ]);

        $report = Report::create([
            'reporter_id' => $this->reader->id,
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
        ]);

        $this->actingAs($this->creator)
            ->deleteJson("/api/posts/{$post->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
        $this->assertDatabaseMissing('likes', ['id' => $like->id]);
        $this->assertDatabaseMissing('reports', ['id' => $report->id]);
        $this->assertFalse(Storage::disk('public')->exists($imagePath));
    }

    /**
     * Test feed sorting by latest and popular, and filtering by comic/author.
     */
    public function test_feed_sorting_and_filtering(): void
    {
        $post1 = Post::factory()->create([
            'user_id' => $this->creator->id,
            'comic_id' => $this->approvedComic->id,
            'likes_count' => 5,
            'created_at' => now()->subDays(2),
        ]);

        $post2 = Post::factory()->create([
            'user_id' => $this->creatorOther->id,
            'comic_id' => null,
            'likes_count' => 10,
            'created_at' => now()->subDay(),
        ]);

        $post3 = Post::factory()->create([
            'user_id' => $this->creator->id,
            'comic_id' => $this->approvedComic->id,
            'likes_count' => 1,
            'created_at' => now(),
        ]);

        // Default sort: latest (post3, post2, post1)
        $latest = $this->getJson('/api/posts?sort=latest');
        $latest->assertStatus(200);
        $latestIds = collect($latest->json('data'))->pluck('id')->all();
        $this->assertEquals([$post3->id, $post2->id, $post1->id], $latestIds);

        // Popular sort: highest likes_count first (post2 with 10, post1 with 5, post3 with 1)
        $popular = $this->getJson('/api/posts?sort=popular');
        $popular->assertStatus(200);
        $popularIds = collect($popular->json('data'))->pluck('id')->all();
        $this->assertEquals([$post2->id, $post1->id, $post3->id], $popularIds);

        // Filter by comic_id
        $comicFilter = $this->getJson("/api/posts?comic_id={$this->approvedComic->id}");
        $comicFilter->assertStatus(200);
        $comicIds = collect($comicFilter->json('data'))->pluck('id')->all();
        $this->assertEquals([$post3->id, $post1->id], $comicIds);

        // Filter by user_id
        $userFilter = $this->getJson("/api/posts?user_id={$this->creatorOther->id}");
        $userFilter->assertStatus(200);
        $userIds = collect($userFilter->json('data'))->pluck('id')->all();
        $this->assertEquals([$post2->id], $userIds);
    }

    /**
     * Test toggle like and explicit unlike flow.
     */
    public function test_toggle_like_and_unlike_flow(): void
    {
        $post = Post::factory()->create([
            'user_id' => $this->creator->id,
            'likes_count' => 0,
        ]);

        // 1. Toggle like -> liked: true, count: 1
        $this->actingAs($this->reader)
            ->postJson("/api/posts/{$post->id}/like")
            ->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'id' => $post->id,
                    'liked' => true,
                    'likes_count' => 1,
                ],
            ]);

        $post->refresh();
        $this->assertEquals(1, $post->likes_count);
        $this->assertDatabaseHas('likes', [
            'user_id' => $this->reader->id,
            'likeable_type' => 'post',
            'likeable_id' => $post->id,
        ]);

        // 2. Toggle like again -> liked: false, count: 0
        $this->actingAs($this->reader)
            ->postJson("/api/posts/{$post->id}/like")
            ->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'id' => $post->id,
                    'liked' => false,
                    'likes_count' => 0,
                ],
            ]);

        $post->refresh();
        $this->assertEquals(0, $post->likes_count);
        $this->assertDatabaseMissing('likes', [
            'user_id' => $this->reader->id,
            'likeable_type' => 'post',
            'likeable_id' => $post->id,
        ]);

        // 3. Like again, then explicit DELETE /unlike
        $this->actingAs($this->reader)->postJson("/api/posts/{$post->id}/like");
        $post->refresh();
        $this->assertEquals(1, $post->likes_count);

        $this->actingAs($this->reader)
            ->deleteJson("/api/posts/{$post->id}/like")
            ->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'liked' => false,
                    'likes_count' => 0,
                ],
            ]);

        // 4. Repeated DELETE /unlike is idempotent
        $this->actingAs($this->reader)
            ->deleteJson("/api/posts/{$post->id}/like")
            ->assertStatus(200)
            ->assertJson([
                'data' => [
                    'liked' => false,
                    'likes_count' => 0,
                ],
            ]);
    }

    /**
     * Test is_liked state is computed accurately for authenticated user vs guest.
     */
    public function test_is_liked_state_computed_accurately(): void
    {
        $postA = Post::factory()->create(['user_id' => $this->creator->id]);
        $postB = Post::factory()->create(['user_id' => $this->creator->id]);

        Like::create([
            'user_id' => $this->reader->id,
            'likeable_type' => 'post',
            'likeable_id' => $postA->id,
        ]);

        // Reader sees postA is_liked: true, postB is_liked: false
        $responseReader = $this->actingAs($this->reader)->getJson('/api/posts');
        $responseReader->assertStatus(200);
        $data = collect($responseReader->json('data'))->keyBy('id');
        $this->assertTrue($data[$postA->id]['is_liked']);
        $this->assertFalse($data[$postB->id]['is_liked']);
    }

    /**
     * Test guest always sees is_liked as false even if other users liked the post.
     */
    public function test_guest_always_sees_is_liked_false(): void
    {
        $post = Post::factory()->create(['user_id' => $this->creator->id]);

        Like::create([
            'user_id' => $this->reader->id,
            'likeable_type' => 'post',
            'likeable_id' => $post->id,
        ]);

        $response = $this->getJson('/api/posts');
        $response->assertStatus(200);
        $data = collect($response->json('data'))->keyBy('id');
        $this->assertFalse($data[$post->id]['is_liked']);
    }

    /**
     * Test 404 response for non-existent post across endpoints.
     */
    public function test_404_returned_for_non_existent_post(): void
    {
        $nonExistentId = 999999;

        $this->getJson("/api/posts/{$nonExistentId}")
            ->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Bài đăng không tồn tại hoặc đã bị xóa.',
            ]);

        $this->actingAs($this->creator)
            ->putJson("/api/posts/{$nonExistentId}", ['content' => 'Update test'])
            ->assertStatus(404);

        $this->actingAs($this->creator)
            ->deleteJson("/api/posts/{$nonExistentId}")
            ->assertStatus(404);

        $this->actingAs($this->reader)
            ->postJson("/api/posts/{$nonExistentId}/like")
            ->assertStatus(404);
    }
}
