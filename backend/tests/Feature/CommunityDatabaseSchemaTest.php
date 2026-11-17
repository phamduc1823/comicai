<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class CommunityDatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verify all 4 community tables exist.
     */
    public function test_all_4_community_tables_exist(): void
    {
        $communityTables = [
            'posts',
            'comments',
            'likes',
            'reports',
        ];

        foreach ($communityTables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} should exist in database.");
        }
    }

    /**
     * Verify posts table business columns.
     */
    public function test_posts_table_has_business_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('posts', [
            'id', 'user_id', 'comic_id', 'content', 'image_path',
            'likes_count', 'comments_count', 'created_at', 'updated_at',
        ]));
    }

    /**
     * Verify comments table business columns.
     */
    public function test_comments_table_has_business_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('comments', [
            'id', 'user_id', 'parent_id', 'commentable_type', 'commentable_id',
            'content', 'likes_count', 'replies_count', 'created_at', 'updated_at',
        ]));
    }

    /**
     * Verify likes table business columns.
     */
    public function test_likes_table_has_business_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('likes', [
            'id', 'user_id', 'likeable_type', 'likeable_id', 'created_at',
        ]));
    }

    /**
     * Verify reports table business columns.
     */
    public function test_reports_table_has_business_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('reports', [
            'id', 'reporter_id', 'reportable_type', 'reportable_id',
            'reason', 'description', 'status', 'resolved_by',
            'resolved_at', 'admin_notes', 'created_at', 'updated_at',
        ]));
    }

    /**
     * Test unique constraint prevents duplicate likes on same target.
     */
    public function test_unique_constraint_prevents_duplicate_likes(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        Like::create([
            'user_id' => $user->id,
            'likeable_type' => 'post',
            'likeable_id' => $post->id,
        ]);

        $this->expectException(QueryException::class);

        Like::create([
            'user_id' => $user->id,
            'likeable_type' => 'post',
            'likeable_id' => $post->id,
        ]);
    }

    /**
     * Test business rule prevents duplicate pending reports by same reporter on same target.
     */
    public function test_cannot_create_duplicate_pending_report_for_same_target(): void
    {
        $reporter = User::factory()->create();
        $post = Post::factory()->create();

        Report::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
            'status' => 'pending',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bạn đã có một báo cáo đang chờ xử lý cho nội dung này.');

        Report::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'inappropriate',
            'status' => 'pending',
        ]);
    }

    /**
     * Test user can submit a new report after their previous report is resolved or dismissed.
     */
    public function test_can_submit_new_report_after_previous_resolved_or_dismissed(): void
    {
        $reporter = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $post = Post::factory()->create();

        // 1. Submit initial pending report
        $report1 = Report::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
            'status' => 'pending',
        ]);

        $this->assertEquals('pending', $report1->status);

        // 2. Admin resolves report
        $report1->update([
            'status' => 'resolved',
            'resolved_by' => $admin->id,
            'resolved_at' => now(),
            'admin_notes' => 'Nội dung vi phạm đã được xử lý.',
        ]);

        // 3. Reporter can now create a 2nd report for the same post
        $report2 = Report::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'harassment',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('reports', ['id' => $report2->id, 'status' => 'pending']);

        // 4. Admin dismisses the 2nd report
        $report2->update([
            'status' => 'dismissed',
            'resolved_by' => $admin->id,
            'resolved_at' => now(),
            'admin_notes' => 'Không đủ căn cứ.',
        ]);

        // 5. Reporter can now create a 3rd report
        $report3 = Report::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'copyright',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('reports', ['id' => $report3->id, 'status' => 'pending']);
        $this->assertCount(3, Report::where('reporter_id', $reporter->id)->where('reportable_id', $post->id)->get());
    }

    /**
     * Test relationships between User and Community models.
     */
    public function test_user_community_relationships(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $post = Post::factory()->create(['user_id' => $user->id]);
        $comment = Comment::factory()->create([
            'user_id' => $user->id,
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
        ]);
        $like = Like::create([
            'user_id' => $user->id,
            'likeable_type' => 'post',
            'likeable_id' => $post->id,
        ]);
        $report = Report::factory()->create([
            'reporter_id' => $user->id,
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'resolved_by' => $admin->id,
            'status' => 'resolved',
        ]);

        $this->assertTrue($user->posts->contains($post));
        $this->assertTrue($user->comments->contains($comment));
        $this->assertTrue($user->likes->contains($like));
        $this->assertTrue($user->reports->contains($report));
        $this->assertTrue($admin->resolvedReports->contains($report));
    }

    /**
     * Test polymorphic commenting on Comic and Chapter.
     */
    public function test_comments_on_comic_and_chapter(): void
    {
        $user = User::factory()->create();
        $comic = Comic::create([
            'title' => 'Truyện Test',
            'slug' => 'truyen-test-community',
            'author_name' => 'Tác giả A',
            'cover_path' => 'covers/test.webp',
        ]);

        $chapter = Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => 1.0,
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
        ]);

        $comicComment = Comment::create([
            'user_id' => $user->id,
            'commentable_type' => 'comic',
            'commentable_id' => $comic->id,
            'content' => 'Bình luận trên truyện',
        ]);

        $chapterComment = Comment::create([
            'user_id' => $user->id,
            'commentable_type' => 'chapter',
            'commentable_id' => $chapter->id,
            'content' => 'Bình luận trên chương',
        ]);

        $this->assertInstanceOf(Comic::class, $comicComment->commentable);
        $this->assertEquals($comic->id, $comicComment->commentable->id);
        $this->assertTrue($comic->comments->contains($comicComment));

        $this->assertInstanceOf(Chapter::class, $chapterComment->commentable);
        $this->assertEquals($chapter->id, $chapterComment->commentable->id);
        $this->assertTrue($chapter->comments->contains($chapterComment));
    }

    /**
     * Test 2-level comment nesting rule.
     */
    public function test_two_level_comment_nesting_rule(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        // Level 1: Root comment
        $rootComment = Comment::create([
            'user_id' => $user->id,
            'parent_id' => null,
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
            'content' => 'Bình luận gốc cấp 1',
        ]);

        $this->assertTrue($rootComment->isRoot());
        $this->assertFalse($rootComment->isReply());

        // Level 2: Reply to root comment
        $replyComment = Comment::create([
            'user_id' => $user->id,
            'parent_id' => $rootComment->id,
            'content' => 'Phản hồi cấp 2',
        ]);

        $this->assertFalse($replyComment->isRoot());
        $this->assertTrue($replyComment->isReply());
        $this->assertEquals($rootComment->id, $replyComment->parent->id);
        $this->assertTrue($rootComment->replies->contains($replyComment));
        $this->assertEquals('post', $replyComment->commentable_type);
        $this->assertEquals($post->id, $replyComment->commentable_id);

        // Level 3: Attempting to reply to a level-2 reply must fail
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Không thể trả lời một bình luận cấp 2. Chỉ hỗ trợ tối đa 2 cấp bình luận.');

        Comment::create([
            'user_id' => $user->id,
            'parent_id' => $replyComment->id,
            'content' => 'Cố gắng tạo bình luận cấp 3',
        ]);
    }

    /**
     * Test counter caches for likes and comments.
     */
    public function test_counter_caches_maintenance(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $post = Post::factory()->create();

        $this->assertEquals(0, $post->likes_count);
        $this->assertEquals(0, $post->comments_count);

        // Like post
        $like1 = Like::create([
            'user_id' => $user1->id,
            'likeable_type' => 'post',
            'likeable_id' => $post->id,
        ]);

        $post->refresh();
        $this->assertEquals(1, $post->likes_count);
        $this->assertTrue($post->isLikedBy($user1));
        $this->assertFalse($post->isLikedBy($user2));

        // Comment on post
        $comment = Comment::create([
            'user_id' => $user1->id,
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
            'content' => 'Bình luận mẫu',
        ]);

        $post->refresh();
        $this->assertEquals(1, $post->comments_count);
        $this->assertEquals(0, $comment->replies_count);

        // Reply to comment
        $reply = Comment::create([
            'user_id' => $user2->id,
            'parent_id' => $comment->id,
            'content' => 'Trả lời mẫu',
        ]);

        $comment->refresh();
        $post->refresh();
        $this->assertEquals(1, $comment->replies_count);
        $this->assertEquals(2, $post->comments_count); // 1 root + 1 reply

        // Delete reply
        $reply->delete();
        $comment->refresh();
        $post->refresh();
        $this->assertEquals(0, $comment->replies_count);
        $this->assertEquals(1, $post->comments_count);

        // Delete like
        $like1->delete();
        $post->refresh();
        $this->assertEquals(0, $post->likes_count);
    }

    /**
     * Test cascading cleanup of polymorphic relations when post is deleted.
     */
    public function test_cascading_cleanup_when_post_deleted(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $comment = Comment::create([
            'user_id' => $user->id,
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
            'content' => 'Bình luận trên bài đăng sẽ bị xóa',
        ]);

        $like = Like::create([
            'user_id' => $user->id,
            'likeable_type' => 'post',
            'likeable_id' => $post->id,
        ]);

        $report = Report::create([
            'reporter_id' => $user->id,
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'spam',
        ]);

        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
        $this->assertDatabaseHas('likes', ['id' => $like->id]);
        $this->assertDatabaseHas('reports', ['id' => $report->id]);

        $post->delete();

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
        $this->assertDatabaseMissing('likes', ['id' => $like->id]);
        $this->assertDatabaseMissing('reports', ['id' => $report->id]);
    }

    /**
     * Test deleting a root comment with 3 replies correctly decrements post comments_count.
     */
    public function test_deleting_root_comment_with_three_replies_maintains_counters(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->assertEquals(0, $post->comments_count);

        // Create 1 root comment
        $rootComment = Comment::create([
            'user_id' => $user->id,
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
            'content' => 'Bình luận gốc',
        ]);

        $post->refresh();
        $this->assertEquals(1, $post->comments_count);

        // Create 3 replies
        $reply1 = Comment::create([
            'user_id' => $user->id,
            'parent_id' => $rootComment->id,
            'content' => 'Phản hồi 1',
        ]);
        $reply2 = Comment::create([
            'user_id' => $user->id,
            'parent_id' => $rootComment->id,
            'content' => 'Phản hồi 2',
        ]);
        $reply3 = Comment::create([
            'user_id' => $user->id,
            'parent_id' => $rootComment->id,
            'content' => 'Phản hồi 3',
        ]);

        $post->refresh();
        $rootComment->refresh();
        $this->assertEquals(3, $rootComment->replies_count);
        $this->assertEquals(4, $post->comments_count);

        // Delete root comment
        $rootComment->delete();

        $post->refresh();
        $this->assertEquals(0, $post->comments_count);

        // Assert all 4 comments are removed
        $this->assertDatabaseMissing('comments', ['id' => $rootComment->id]);
        $this->assertDatabaseMissing('comments', ['id' => $reply1->id]);
        $this->assertDatabaseMissing('comments', ['id' => $reply2->id]);
        $this->assertDatabaseMissing('comments', ['id' => $reply3->id]);
    }

    /**
     * Test deleting a post with an attached image cleans up the image file from storage.
     */
    public function test_deleting_post_cleans_up_attached_image_file(): void
    {
        Storage::fake('public');

        $imagePath = 'posts/community_test_photo.webp';
        Storage::disk('public')->put($imagePath, 'fake-binary-image-data');

        $this->assertTrue(Storage::disk('public')->exists($imagePath));

        $post = Post::factory()->create([
            'image_path' => $imagePath,
        ]);

        $this->assertDatabaseHas('posts', ['id' => $post->id]);

        $post->delete();

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        $this->assertFalse(Storage::disk('public')->exists($imagePath));
    }

    /**
     * Test deleting a post does not delete files outside the posts/ directory.
     */
    public function test_deleting_post_does_not_delete_files_outside_posts_directory(): void
    {
        Storage::fake('public');

        $outsidePath = 'covers/comic_cover_test.webp';
        Storage::disk('public')->put($outsidePath, 'fake-cover-data');

        $this->assertTrue(Storage::disk('public')->exists($outsidePath));

        $post = Post::factory()->create([
            'image_path' => $outsidePath,
        ]);

        $post->delete();

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        // The file outside posts/ must remain untouched!
        $this->assertTrue(Storage::disk('public')->exists($outsidePath));
    }

    /**
     * Test updating parent_id on an existing comment is prohibited.
     */
    public function test_comment_parent_id_cannot_be_updated(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $root1 = Comment::create([
            'user_id' => $user->id,
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
            'content' => 'Gốc 1',
        ]);

        $root2 = Comment::create([
            'user_id' => $user->id,
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
            'content' => 'Gốc 2',
        ]);

        $reply = Comment::create([
            'user_id' => $user->id,
            'parent_id' => $root1->id,
            'content' => 'Trả lời gốc 1',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Không thể thay đổi cấp độ hoặc bình luận cha của bình luận đã tạo.');

        $reply->update(['parent_id' => $root2->id]);
    }
}
