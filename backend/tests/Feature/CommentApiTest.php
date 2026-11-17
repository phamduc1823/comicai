<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;
    private User $otherReader;
    private User $creator;
    private User $admin;

    private Post $post;
    private Comic $approvedComic;
    private Comic $draftComic;
    private Chapter $publishedChapter;
    private Chapter $draftChapter;

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

        $this->otherReader = User::create([
            'name' => 'Other Reader',
            'email' => 'other_reader@example.com',
            'password' => 'Password123!',
        ]);
        $this->otherReader->role = 'reader';
        $this->otherReader->save();

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

        $this->publishedChapter = Chapter::create([
            'comic_id' => $this->approvedComic->id,
            'chapter_number' => 1.0,
            'title' => 'Chapter 1',
            'slug' => 'chapter-1',
            'status' => 'published',
        ]);

        $this->draftChapter = Chapter::create([
            'comic_id' => $this->approvedComic->id,
            'chapter_number' => 2.0,
            'title' => 'Chapter 2 Draft',
            'slug' => 'chapter-2-draft',
            'status' => 'draft',
        ]);

        $this->post = Post::factory()->create([
            'user_id' => $this->creator->id,
            'content' => 'Bài đăng mẫu',
        ]);
    }

    /**
     * Test guest can view comments on post, comic, and chapter.
     */
    public function test_guest_can_view_comments_on_targets(): void
    {
        $rootComment = Comment::create([
            'user_id' => $this->reader->id,
            'commentable_type' => 'post',
            'commentable_id' => $this->post->id,
            'content' => 'Bình luận gốc trên bài đăng',
        ]);

        $replyComment = Comment::create([
            'user_id' => $this->creator->id,
            'parent_id' => $rootComment->id,
            'content' => 'Phản hồi từ tác giả',
        ]);

        // 1. Generic endpoint with query params
        $resGeneric = $this->getJson("/api/comments?commentable_type=post&commentable_id={$this->post->id}");
        $resGeneric->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'parent_id',
                        'commentable_type',
                        'commentable_id',
                        'content',
                        'likes_count',
                        'replies_count',
                        'is_liked',
                        'user' => ['id', 'name', 'avatar_url', 'role'],
                        'replies' => [
                            '*' => [
                                'id',
                                'parent_id',
                                'content',
                                'user' => ['id', 'name', 'role'],
                            ],
                        ],
                        'created_at',
                    ],
                ],
                'links',
                'meta',
            ]);

        // 2. Dedicated post comments route
        $resPost = $this->getJson("/api/posts/{$this->post->id}/comments");
        $resPost->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rootComment->id)
            ->assertJsonPath('data.0.replies.0.id', $replyComment->id);

        // 3. Comic comments route
        $resComic = $this->getJson("/api/comics/{$this->approvedComic->id}/comments");
        $resComic->assertStatus(200)->assertJsonCount(0, 'data');

        // 4. Chapter comments route
        $resChapter = $this->getJson("/api/chapters/{$this->publishedChapter->id}/comments");
        $resChapter->assertStatus(200)->assertJsonCount(0, 'data');
    }

    /**
     * Test guest cannot create, reply, delete, or like comments.
     */
    public function test_guest_cannot_modify_comments(): void
    {
        $comment = Comment::create([
            'user_id' => $this->reader->id,
            'commentable_type' => 'post',
            'commentable_id' => $this->post->id,
            'content' => 'Gốc',
        ]);

        $this->postJson('/api/comments', ['content' => 'Guest comment'])->assertStatus(401);
        $this->postJson("/api/posts/{$this->post->id}/comments", ['content' => 'Guest comment'])->assertStatus(401);
        $this->postJson("/api/comments/{$comment->id}/reply", ['content' => 'Guest reply'])->assertStatus(401);
        $this->deleteJson("/api/comments/{$comment->id}")->assertStatus(401);
        $this->postJson("/api/comments/{$comment->id}/like")->assertStatus(401);
    }

    /**
     * Test authenticated users (Reader, Creator, Admin) can create root comments and replies.
     */
    public function test_authenticated_roles_can_create_comments_and_replies(): void
    {
        // 1. Reader creates root comment on post
        $resRoot = $this->actingAs($this->reader)
            ->postJson("/api/posts/{$this->post->id}/comments", [
                'content' => 'Reader bình luận bài viết',
            ]);
        $resRoot->assertStatus(201)
            ->assertJsonPath('data.content', 'Reader bình luận bài viết')
            ->assertJsonPath('data.user.role', 'reader');

        $rootId = $resRoot->json('data.id');
        $this->post->refresh();
        $this->assertEquals(1, $this->post->comments_count);

        // 2. Creator replies to root comment
        $resReply = $this->actingAs($this->creator)
            ->postJson("/api/comments/{$rootId}/reply", [
                'content' => 'Creator trả lời bình luận',
            ]);
        $resReply->assertStatus(201)
            ->assertJsonPath('data.parent_id', $rootId)
            ->assertJsonPath('data.user.role', 'creator');

        $this->post->refresh();
        $this->assertEquals(2, $this->post->comments_count);

        $rootComment = Comment::find($rootId);
        $this->assertEquals(1, $rootComment->replies_count);

        // 3. Admin creates root comment on approved comic
        $resComic = $this->actingAs($this->admin)
            ->postJson("/api/comics/{$this->approvedComic->id}/comments", [
                'content' => 'Admin nhận xét truyện',
            ]);
        $resComic->assertStatus(201)
            ->assertJsonPath('data.commentable_type', 'comic')
            ->assertJsonPath('data.user.role', 'admin');
    }

    /**
     * Test 2-level comment hierarchy rule strictly blocks 3-level nesting.
     */
    public function test_blocks_three_level_comment_nesting(): void
    {
        // Level 1: Root
        $root = Comment::create([
            'user_id' => $this->reader->id,
            'commentable_type' => 'post',
            'commentable_id' => $this->post->id,
            'content' => 'Cấp 1',
        ]);

        // Level 2: Reply
        $reply = Comment::create([
            'user_id' => $this->creator->id,
            'parent_id' => $root->id,
            'content' => 'Cấp 2',
        ]);

        // Level 3 attempt via /reply endpoint
        $res1 = $this->actingAs($this->otherReader)
            ->postJson("/api/comments/{$reply->id}/reply", [
                'content' => 'Cố gắng tạo cấp 3 qua /reply',
            ]);
        $res1->assertStatus(422)
            ->assertJsonFragment([
                'status' => 'error',
                'message' => 'Không thể trả lời một bình luận cấp 2. Chỉ hỗ trợ tối đa 2 cấp bình luận.',
            ]);

        // Level 3 attempt via POST /comments with parent_id
        $res2 = $this->actingAs($this->otherReader)
            ->postJson('/api/comments', [
                'parent_id' => $reply->id,
                'content' => 'Cố gắng tạo cấp 3 qua /comments',
            ]);
        $res2->assertStatus(422)
            ->assertJsonFragment([
                'status' => 'error',
                'message' => 'Không thể trả lời một bình luận cấp 2. Chỉ hỗ trợ tối đa 2 cấp bình luận.',
            ]);
    }

    /**
     * Test validation rules and target accessibility checks.
     */
    public function test_comment_validation_and_target_accessibility(): void
    {
        // 1. Empty or whitespace-only content
        $this->actingAs($this->reader)
            ->postJson("/api/posts/{$this->post->id}/comments", ['content' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content']);

        // 2. Content exceeding 2000 characters
        $this->actingAs($this->reader)
            ->postJson("/api/posts/{$this->post->id}/comments", ['content' => str_repeat('b', 2001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content']);

        // 3. Post does not exist
        $this->actingAs($this->reader)
            ->postJson('/api/posts/999999/comments', ['content' => 'Bình luận'])
            ->assertStatus(404);

        // 4. Comic is draft (unapproved)
        $this->actingAs($this->reader)
            ->postJson("/api/comics/{$this->draftComic->id}/comments", ['content' => 'Bình luận truyện nháp'])
            ->assertStatus(404);

        // 5. Chapter is draft (unpublished)
        $this->actingAs($this->reader)
            ->postJson("/api/chapters/{$this->draftChapter->id}/comments", ['content' => 'Bình luận chương nháp'])
            ->assertStatus(404);
    }

    /**
     * Test author and admin can delete comments, others are forbidden.
     */
    public function test_author_and_admin_can_delete_comment(): void
    {
        $commentA = Comment::create([
            'user_id' => $this->reader->id,
            'commentable_type' => 'post',
            'commentable_id' => $this->post->id,
            'content' => 'Bình luận của Reader A',
        ]);

        $commentB = Comment::create([
            'user_id' => $this->creator->id,
            'commentable_type' => 'post',
            'commentable_id' => $this->post->id,
            'content' => 'Bình luận của Creator',
        ]);

        // Other reader cannot delete commentA
        $this->actingAs($this->otherReader)
            ->deleteJson("/api/comments/{$commentA->id}")
            ->assertStatus(403);

        // Author can delete own commentA
        $this->actingAs($this->reader)
            ->deleteJson("/api/comments/{$commentA->id}")
            ->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Bình luận đã được xóa thành công.',
            ]);
        $this->assertDatabaseMissing('comments', ['id' => $commentA->id]);

        // Admin can delete commentB (moderation)
        $this->actingAs($this->admin)
            ->deleteJson("/api/comments/{$commentB->id}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('comments', ['id' => $commentB->id]);
    }

    /**
     * Test deleting a single reply decrements counters accurately.
     */
    public function test_deleting_single_reply_decrements_counters(): void
    {
        $root = Comment::create([
            'user_id' => $this->reader->id,
            'commentable_type' => 'post',
            'commentable_id' => $this->post->id,
            'content' => 'Bình luận gốc',
        ]);

        $reply = Comment::create([
            'user_id' => $this->otherReader->id,
            'parent_id' => $root->id,
            'content' => 'Phản hồi',
        ]);

        $this->post->refresh();
        $root->refresh();
        $this->assertEquals(2, $this->post->comments_count);
        $this->assertEquals(1, $root->replies_count);

        // Delete reply
        $this->actingAs($this->otherReader)
            ->deleteJson("/api/comments/{$reply->id}")
            ->assertStatus(200);

        $this->post->refresh();
        $root->refresh();
        $this->assertEquals(1, $this->post->comments_count);
        $this->assertEquals(0, $root->replies_count);
        $this->assertDatabaseMissing('comments', ['id' => $reply->id]);
    }

    /**
     * Test deleting root comment with multiple replies cleans relations and counters.
     */
    public function test_deleting_root_comment_with_replies_maintains_counters(): void
    {
        $root = Comment::create([
            'user_id' => $this->reader->id,
            'commentable_type' => 'post',
            'commentable_id' => $this->post->id,
            'content' => 'Gốc',
        ]);

        $reply1 = Comment::create(['user_id' => $this->reader->id, 'parent_id' => $root->id, 'content' => 'R1']);
        $reply2 = Comment::create(['user_id' => $this->reader->id, 'parent_id' => $root->id, 'content' => 'R2']);
        $reply3 = Comment::create(['user_id' => $this->reader->id, 'parent_id' => $root->id, 'content' => 'R3']);

        $this->post->refresh();
        $this->assertEquals(4, $this->post->comments_count);

        // Add like and report on root and reply
        Like::create(['user_id' => $this->admin->id, 'likeable_type' => 'comment', 'likeable_id' => $root->id]);
        Like::create(['user_id' => $this->admin->id, 'likeable_type' => 'comment', 'likeable_id' => $reply1->id]);
        Report::create(['reporter_id' => $this->admin->id, 'reportable_type' => 'comment', 'reportable_id' => $reply2->id, 'reason' => 'spam']);

        // Delete root comment
        $this->actingAs($this->reader)
            ->deleteJson("/api/comments/{$root->id}")
            ->assertStatus(200);

        $this->post->refresh();
        $this->assertEquals(0, $this->post->comments_count);

        $this->assertDatabaseMissing('comments', ['id' => $root->id]);
        $this->assertDatabaseMissing('comments', ['id' => $reply1->id]);
        $this->assertDatabaseMissing('comments', ['id' => $reply2->id]);
        $this->assertDatabaseMissing('comments', ['id' => $reply3->id]);
        $this->assertDatabaseMissing('likes', ['likeable_type' => 'comment', 'likeable_id' => $root->id]);
        $this->assertDatabaseMissing('likes', ['likeable_type' => 'comment', 'likeable_id' => $reply1->id]);
        $this->assertDatabaseMissing('reports', ['reportable_type' => 'comment', 'reportable_id' => $reply2->id]);
    }

    /**
     * Test pagination and eager loading on comments.
     */
    public function test_pagination_and_eager_loading(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            Comment::create([
                'user_id' => $this->reader->id,
                'commentable_type' => 'post',
                'commentable_id' => $this->post->id,
                'content' => "Gốc {$i}",
            ]);
        }

        $response = $this->getJson("/api/posts/{$this->post->id}/comments?per_page=4");
        $response->assertStatus(200)
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.total', 10)
            ->assertJsonPath('meta.per_page', 4)
            ->assertJsonPath('meta.last_page', 3);
    }

    /**
     * Test toggle like and explicit unlike on comment.
     */
    public function test_toggle_like_and_unlike_comment(): void
    {
        $comment = Comment::create([
            'user_id' => $this->reader->id,
            'commentable_type' => 'post',
            'commentable_id' => $this->post->id,
            'content' => 'Bình luận để thích',
        ]);

        // 1. Toggle like -> count: 1, liked: true
        $this->actingAs($this->creator)
            ->postJson("/api/comments/{$comment->id}/like")
            ->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'id' => $comment->id,
                    'liked' => true,
                    'likes_count' => 1,
                ],
            ]);

        $comment->refresh();
        $this->assertEquals(1, $comment->likes_count);

        // 2. Toggle like again -> count: 0, liked: false
        $this->actingAs($this->creator)
            ->postJson("/api/comments/{$comment->id}/like")
            ->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'liked' => false,
                    'likes_count' => 0,
                ],
            ]);

        $comment->refresh();
        $this->assertEquals(0, $comment->likes_count);

        // 3. Like then explicit unlike
        $this->actingAs($this->creator)->postJson("/api/comments/{$comment->id}/like");
        $comment->refresh();
        $this->assertEquals(1, $comment->likes_count);

        $this->actingAs($this->creator)
            ->deleteJson("/api/comments/{$comment->id}/like")
            ->assertStatus(200)
            ->assertJson([
                'data' => [
                    'liked' => false,
                    'likes_count' => 0,
                ],
            ]);
    }

    /**
     * Test is_liked state computed accurately for current user vs other users.
     */
    public function test_comment_is_liked_state(): void
    {
        $comment1 = Comment::create([
            'user_id' => $this->reader->id,
            'commentable_type' => 'post',
            'commentable_id' => $this->post->id,
            'content' => 'Bình luận 1',
        ]);

        $comment2 = Comment::create([
            'user_id' => $this->reader->id,
            'commentable_type' => 'post',
            'commentable_id' => $this->post->id,
            'content' => 'Bình luận 2',
        ]);

        Like::create([
            'user_id' => $this->reader->id,
            'likeable_type' => 'comment',
            'likeable_id' => $comment1->id,
        ]);

        // Reader sees comment1 is_liked: true, comment2 is_liked: false
        $resReader = $this->actingAs($this->reader)->getJson("/api/posts/{$this->post->id}/comments");
        $resReader->assertStatus(200);
        $data = collect($resReader->json('data'))->keyBy('id');
        $this->assertTrue($data[$comment1->id]['is_liked']);
        $this->assertFalse($data[$comment2->id]['is_liked']);
    }

    /**
     * Test 404 for non-existent comment.
     */
    public function test_404_for_non_existent_comment(): void
    {
        $nonExistentId = 999999;

        $this->actingAs($this->reader)
            ->postJson("/api/comments/{$nonExistentId}/reply", ['content' => 'Trả lời'])
            ->assertStatus(404);

        $this->actingAs($this->reader)
            ->deleteJson("/api/comments/{$nonExistentId}")
            ->assertStatus(404);

        $this->actingAs($this->reader)
            ->postJson("/api/comments/{$nonExistentId}/like")
            ->assertStatus(404);
    }
}
