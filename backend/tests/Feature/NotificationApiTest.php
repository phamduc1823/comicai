<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Post;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1: Messages do NOT create bell notifications or dispatch NotificationCreated (CAI-MSG-04 separation).
     */
    public function test_1_messages_do_not_create_bell_notifications(): void
    {
        Event::fake([NotificationCreated::class]);

        $sender = User::factory()->create(['name' => 'Alice Sender', 'is_active' => true]);
        $recipient = User::factory()->create(['name' => 'Bob Recipient', 'is_active' => true]);

        // Send via lazy direct message endpoint (CAI-MSG-01 flow)
        $this->actingAs($sender, 'sanctum')
            ->postJson("/api/chat/direct/{$recipient->id}/messages", [
                'body' => 'Xin chào Bob, tin nhắn này không được làm tăng chuông thông báo',
            ])
            ->assertStatus(201);

        // Bell notifications remain strictly 0
        $this->assertEquals(0, $recipient->notifications()->count());
        $this->assertEquals(0, $recipient->unreadNotifications()->count());

        // NotificationCreated event is strictly NOT dispatched for messages
        Event::assertNotDispatched(NotificationCreated::class);
    }

    /**
     * Test 2: Message sending does not increment notification bell count.
     */
    public function test_2_message_sending_does_not_increment_bell_count(): void
    {
        $sender = User::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);

        $conversation = Conversation::factory()
            ->betweenUsers($sender->id, $recipient->id)
            ->withParticipants()
            ->create();

        $this->actingAs($sender, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Hello from sender',
            ])
            ->assertStatus(201);

        $this->assertEquals(0, $sender->notifications()->count());
        $this->assertEquals(0, $recipient->notifications()->count());
    }

    /**
     * Test 3: Failed message creation (validation error or transaction rollback) does NOT create any notification.
     */
    public function test_3_failed_message_does_not_create_notification(): void
    {
        $sender = User::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);

        // 3a. Validation failure on lazy direct endpoint
        $this->actingAs($sender, 'sanctum')
            ->postJson("/api/chat/direct/{$recipient->id}/messages", [
                'body' => '',
            ])
            ->assertStatus(422);

        $this->assertEquals(0, DatabaseNotification::count());

        // 3b. Validation failure on existing conversation
        $conversation = Conversation::factory()
            ->betweenUsers($sender->id, $recipient->id)
            ->withParticipants()
            ->create();

        $this->actingAs($sender, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => str_repeat('x', 2001),
            ])
            ->assertStatus(422);

        $this->assertEquals(0, DatabaseNotification::count());

        // 3c. Outer DB transaction rollback prevents afterCommit notification
        try {
            DB::transaction(function () use ($sender, $conversation) {
                app(\App\Services\MessageService::class)->sendMessage($sender, $conversation, [
                    'body' => 'Will be rolled back',
                ]);
                throw new \RuntimeException('Simulated rollback');
            });
        } catch (\RuntimeException) {
            // Expected rollback
        }

        $this->assertEquals(0, Message::count());
        $this->assertEquals(0, DatabaseNotification::count());
    }

    /**
     * Test 4: Duplicate notification is NEVER created for the same post like (idempotent dedupe).
     */
    public function test_4_no_duplicate_notification_for_same_post_like(): void
    {
        $author = User::factory()->create(['is_active' => true]);
        $liker = User::factory()->create(['is_active' => true]);
        $post = Post::factory()->create(['user_id' => $author->id]);

        // First like -> 200 OK + 1 notification
        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk();

        $this->assertEquals(1, $author->notifications()->count());

        // Unlike -> 200 OK
        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk();

        // Relike -> DB dedupe prevents duplicate notification
        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk();

        $this->assertEquals(1, $author->notifications()->count());
    }

    /**
     * Test 5: Post author receives notification when another user likes their community post.
     */
    public function test_5_user_receives_notification_when_post_is_liked(): void
    {
        Event::fake([NotificationCreated::class]);

        $author = User::factory()->create(['name' => 'Author One', 'is_active' => true]);
        $liker = User::factory()->create(['name' => 'Liker Two', 'is_active' => true]);

        $post = Post::factory()->create([
            'user_id' => $author->id,
            'content' => 'Review bộ truyện tranh cực hay tuần này!',
        ]);

        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked', true);

        $this->assertEquals(1, $author->notifications()->count());
        $this->assertEquals(0, $liker->notifications()->count());

        $notif = $author->notifications()->first();
        $this->assertEquals('post_liked', $notif->data['kind']);
        $this->assertEquals($liker->id, $notif->data['actor']['id']);
        $this->assertEquals('Liker Two', $notif->data['actor']['name']);
        $this->assertEquals('post', $notif->data['target']['type']);
        $this->assertEquals($post->id, $notif->data['target']['id']);
        $this->assertEquals("/posts/{$post->id}", $notif->data['target']['url']);

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($author) {
            return $event->recipientId === $author->id && $event->unreadCount === 1;
        });
    }

    /**
     * Test 6: No notification is created when a user likes their own community post.
     */
    public function test_6_no_notification_when_user_likes_own_post(): void
    {
        $author = User::factory()->create(['is_active' => true]);
        $post = Post::factory()->create([
            'user_id' => $author->id,
            'content' => 'My own community post',
        ]);

        $this->actingAs($author, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked', true);

        $this->assertEquals(0, $author->notifications()->count());
        $this->assertEquals(0, DatabaseNotification::count());
    }

    /**
     * Test 7: Repeated like / unlike + re-like does NOT create duplicate notification; unlike preserves existing notification.
     */
    public function test_7_repeated_or_toggled_like_does_not_create_duplicate_notification(): void
    {
        $author = User::factory()->create(['is_active' => true]);
        $liker = User::factory()->create(['is_active' => true]);
        $post = Post::factory()->create([
            'user_id' => $author->id,
            'content' => 'Toggle like test post',
        ]);

        // 1st toggle -> liked -> creates 1 notification
        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked', true);

        $this->assertEquals(1, $author->notifications()->count());

        // 2nd toggle -> unliked -> does NOT delete existing notification, does NOT create new one
        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked', false);

        $this->assertEquals(1, $author->notifications()->count());

        // 3rd toggle -> liked again -> dedupe prevents duplicate notification spam
        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked', true);

        $this->assertEquals(1, $author->notifications()->count());

        // Direct duplicate call also deduplicates cleanly
        app(NotificationService::class)->notifyPostLiked($post, $liker);
        $this->assertEquals(1, $author->notifications()->count());
    }

    /**
     * Test 8: Notification list returns ONLY the authenticated user's notifications and never leaks sensitive fields.
     */
    public function test_8_notification_list_returns_only_own_notifications_without_sensitive_data(): void
    {
        $userA = User::factory()->create(['email' => 'secret-a@example.com', 'is_active' => true]);
        $userB = User::factory()->create(['email' => 'secret-b@example.com', 'is_active' => true]);
        $userC = User::factory()->create(['email' => 'secret-c@example.com', 'is_active' => true]);

        // Guest blocked
        $this->getJson('/api/notifications')->assertUnauthorized();

        $postA = Post::factory()->create(['user_id' => $userA->id, 'content' => 'Post of user A']);
        $postB = Post::factory()->create(['user_id' => $userB->id, 'content' => 'Post of user B']);

        // User B likes Post A
        $this->actingAs($userB, 'sanctum')
            ->postJson("/api/posts/{$postA->id}/like")
            ->assertOk();

        // User C likes Post B
        $this->actingAs($userC, 'sanctum')
            ->postJson("/api/posts/{$postB->id}/like")
            ->assertOk();

        // User A fetches list -> must ONLY see notification from B
        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/notifications?page=1&per_page=10')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'type',
                        'title',
                        'body',
                        'actor' => ['id', 'name', 'avatar_path', 'avatar_url', 'role'],
                        'target' => ['type', 'id', 'url'],
                        'is_read',
                        'read_at',
                        'created_at',
                    ],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'unread_count'],
            ]);

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($userB->id, $response->json('data.0.actor.id'));
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertEquals(1, $response->json('meta.unread_count'));

        // Strictly verify no sensitive data leaked
        $rawContent = $response->getContent();
        $this->assertStringNotContainsString('secret-b@example.com', $rawContent);
        $this->assertStringNotContainsString('secret-a@example.com', $rawContent);
        $this->assertStringNotContainsString('password', $rawContent);
        $this->assertStringNotContainsString('remember_token', $rawContent);
    }

    /**
     * Test 9: User CANNOT mark another user's notification as read (IDOR blocked); invalid/missing IDs rejected cleanly.
     */
    public function test_9_user_cannot_mark_another_users_notification_as_read(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $liker = User::factory()->create(['is_active' => true]);
        $intruder = User::factory()->create(['is_active' => true]);
        $post = Post::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk();

        $ownerNotif = $owner->notifications()->firstOrFail();

        // Intruder tries to mark owner's notification -> 403 Forbidden
        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/notifications/{$ownerNotif->id}/read")
            ->assertForbidden();

        // Verify owner's notification remains unread
        $ownerNotif->refresh();
        $this->assertNull($ownerNotif->read_at);

        // Non-existent valid UUID -> 404 Not Found
        $missingUuid = (string) Str::uuid();
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/notifications/{$missingUuid}/read")
            ->assertNotFound();

        // Malformed ID -> 422 Unprocessable Entity
        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/notifications/not-a-valid-uuid/read')
            ->assertStatus(422);
    }

    /**
     * Test 10: Unread count endpoint returns exact unread count and rejects guests/inactive users.
     */
    public function test_10_unread_count_is_accurate(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $other1 = User::factory()->create(['is_active' => true]);
        $other2 = User::factory()->create(['is_active' => true]);

        $this->getJson('/api/notifications/unread-count')->assertUnauthorized();

        // Initially 0
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        // Receive 1 message -> bell unread count REMAINS 0 (CAI-MSG-04 bell separation)
        $this->actingAs($other1, 'sanctum')
            ->postJson("/api/chat/direct/{$user->id}/messages", ['body' => 'Hi'])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        // Receive 1 post like -> bell unread count becomes 1
        $post = Post::factory()->create(['user_id' => $user->id]);
        $this->actingAs($other2, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        // Receive 1 comment -> bell unread count becomes 2
        $this->actingAs($other1, 'sanctum')
            ->postJson("/api/posts/{$post->id}/comments", ['content' => 'Nice post!'])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    /**
     * Test 11: Mark one notification as read works, updates read_at, decrements unread_count, and is idempotent.
     */
    public function test_11_mark_one_notification_as_read_works(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $liker = User::factory()->create(['is_active' => true]);
        $post1 = Post::factory()->create(['user_id' => $user->id]);
        $post2 = Post::factory()->create(['user_id' => $user->id]);

        $this->actingAs($liker, 'sanctum')->postJson("/api/posts/{$post1->id}/like")->assertOk();
        $this->actingAs($liker, 'sanctum')->postJson("/api/posts/{$post2->id}/like")->assertOk();

        $firstNotif = $user->notifications()->firstOrFail();

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/notifications/{$firstNotif->id}/read")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $firstNotif->id)
            ->assertJsonPath('data.is_read', true)
            ->assertJsonPath('meta.unread_count', 1);

        $this->assertNotNull($response->json('data.read_at'));
        $firstNotif->refresh();
        $this->assertNotNull($firstNotif->read_at);

        // Idempotent second call keeps unread_count at 1
        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/notifications/{$firstNotif->id}/read")
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1);
    }

    /**
     * Test 12: Mark all as read marks all unread notifications for current user without touching other users.
     */
    public function test_12_mark_all_as_read_works_and_isolates_users(): void
    {
        $userA = User::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);

        $postA1 = Post::factory()->create(['user_id' => $userA->id]);
        $postA2 = Post::factory()->create(['user_id' => $userA->id]);
        $postB1 = Post::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($actor, 'sanctum')->postJson("/api/posts/{$postA1->id}/like")->assertOk();
        $this->actingAs($actor, 'sanctum')->postJson("/api/posts/{$postA2->id}/like")->assertOk();
        $this->actingAs($actor, 'sanctum')->postJson("/api/posts/{$postB1->id}/like")->assertOk();

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertEquals(0, $userA->unreadNotifications()->whereIn('type', NotificationService::BELL_NOTIFICATION_TYPES)->count());
        $this->assertEquals(1, $userB->unreadNotifications()->whereIn('type', NotificationService::BELL_NOTIFICATION_TYPES)->count());
    }

    /**
     * Test 13: Direct messages do not pollute notification bell or increase bell unread count (CAI-MSG-04).
     */
    public function test_13_chat_notification_navigates_to_exact_conversation(): void
    {
        $sender = User::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);

        $this->actingAs($sender, 'sanctum')
            ->postJson("/api/chat/direct/{$recipient->id}/messages", ['body' => 'Check conversation target'])
            ->assertStatus(201);

        $listRes = $this->actingAs($recipient, 'sanctum')
            ->getJson('/api/notifications')
            ->assertOk();

        $this->assertCount(0, $listRes->json('data'));
        $this->assertEquals(0, $listRes->json('meta.unread_count'));
    }

    /**
     * Test 14: Community like notification target points to the exact post URL.
     */
    public function test_14_community_like_notification_navigates_to_exact_post(): void
    {
        $author = User::factory()->create(['is_active' => true]);
        $liker = User::factory()->create(['is_active' => true]);
        $post = Post::factory()->create(['user_id' => $author->id, 'content' => 'Post navigation target']);

        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk();

        $listRes = $this->actingAs($author, 'sanctum')
            ->getJson('/api/notifications')
            ->assertOk();

        $target = $listRes->json('data.0.target');
        $this->assertEquals('post', $target['type']);
        $this->assertEquals($post->id, $target['id']);
        $this->assertEquals("/posts/{$post->id}", $target['url']);
    }

    /**
     * Test 15: Pagination validation and broadcast failure resilience.
     */
    public function test_15_pagination_validation_and_broadcast_resilience(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $liker = User::factory()->create(['is_active' => true]);

        // Validation rejects invalid query params
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications?per_page=100')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications?page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['page']);

        // Broadcast failure must NEVER break post like or lose database notification
        Event::listen(NotificationCreated::class, function () {
            throw new \RuntimeException('Simulated Reverb outage');
        });

        $post = Post::factory()->create(['user_id' => $user->id]);
        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked', true);

        $this->assertEquals(1, $user->notifications()->count());
    }

    /**
     * Test 16: Realtime NotificationCreated event contains full required payload fields and accurate unread count.
     * Required by CAI-NOTIF-02.
     */
    public function test_16_realtime_event_contains_full_required_payload_and_count(): void
    {
        Event::fake([NotificationCreated::class]);

        $author = User::factory()->create(['name' => 'Creator Author', 'is_active' => true]);
        $liker = User::factory()->create(['name' => 'Reader Liker', 'is_active' => true]);
        $post = Post::factory()->create(['user_id' => $author->id, 'content' => 'Sample post for like notification']);

        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk();

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($author, $liker, $post) {
            $this->assertEquals($author->id, $event->recipientId);
            $this->assertEquals(1, $event->unreadCount);
            $this->assertEquals('NotificationCreated', $event->broadcastAs());

            $channels = $event->broadcastOn();
            $this->assertCount(1, $channels);
            $this->assertEquals('private-chat.user.' . $author->id, $channels[0]->name);

            $payload = $event->broadcastWith();
            $this->assertArrayHasKey('notification', $payload);
            $this->assertArrayHasKey('unread_count', $payload);
            $this->assertEquals(1, $payload['unread_count']);

            $notif = $payload['notification'];
            $this->assertTrue(Str::isUuid($notif['id']));
            $this->assertEquals('post_liked', $notif['type']);
            $this->assertEquals('Reader Liker đã thích bài viết của bạn', $notif['title']);
            $this->assertFalse($notif['is_read']);
            $this->assertEquals($liker->id, $notif['actor']['id']);
            $this->assertEquals('post', $notif['target']['type']);
            $this->assertEquals($post->id, $notif['target']['id']);
            $this->assertEquals("/posts/{$post->id}", $notif['target']['url']);
            $this->assertNotNull($notif['created_at']);

            return true;
        });
    }

    /**
     * Test 17: Sequential notifications increment unread count accurately across message and like events.
     * Required by CAI-NOTIF-02.
     */
    public function test_17_sequential_notifications_increment_unread_count_accurately(): void
    {
        $events = [];
        Event::listen(NotificationCreated::class, function (NotificationCreated $event) use (&$events) {
            $events[] = $event;
        });

        $user = User::factory()->create(['is_active' => true]);
        $actor1 = User::factory()->create(['is_active' => true]);
        $actor2 = User::factory()->create(['is_active' => true]);
        $post = Post::factory()->create(['user_id' => $user->id]);

        // Event 1: Post like
        $this->actingAs($actor1, 'sanctum')
            ->postJson("/api/posts/{$post->id}/like")
            ->assertOk();

        // Event 2: Post comment (CAI-MSG-04 bell is strictly like & comment)
        $this->actingAs($actor2, 'sanctum')
            ->postJson("/api/posts/{$post->id}/comments", [
                'content' => 'Bình luận kiểm tra unread count tăng tuần tự',
            ])
            ->assertStatus(201);

        $this->assertCount(2, $events);
        $this->assertEquals(1, $events[0]->unreadCount);
        $this->assertEquals(2, $events[1]->unreadCount);
        $this->assertEquals(2, $user->unreadNotifications()->whereIn('type', NotificationService::BELL_NOTIFICATION_TYPES)->count());
    }

    /**
     * Test 18: Author receives notification and realtime event when someone comments on their post.
     */
    public function test_18_author_receives_notification_when_post_commented(): void
    {
        Event::fake([NotificationCreated::class]);

        $author = User::factory()->create(['name' => 'Tác Giả', 'is_active' => true]);
        $commenter = User::factory()->create(['name' => 'Độc Giả Bình Luận', 'is_active' => true]);
        $post = Post::factory()->create(['user_id' => $author->id, 'content' => 'Bài viết mới về truyện tranh']);

        $response = $this->actingAs($commenter, 'sanctum')
            ->postJson("/api/posts/{$post->id}/comments", [
                'content' => 'Truyện này đọc cuốn quá bạn ơi!',
            ])
            ->assertStatus(201);

        $commentId = $response->json('data.id');

        $this->assertEquals(1, $author->notifications()->count());
        $this->assertEquals(1, $author->unreadNotifications()->count());

        $notif = $author->notifications()->first();
        $this->assertNotNull($notif);
        $this->assertEquals('post_commented', $notif->data['kind']);
        $this->assertEquals($commenter->id, $notif->data['actor']['id']);
        $this->assertEquals('Độc Giả Bình Luận', $notif->data['actor']['name']);
        $this->assertStringContainsString('đã bình luận về bài viết của bạn', $notif->data['title']);
        $this->assertEquals('post', $notif->data['target']['type']);
        $this->assertEquals($post->id, $notif->data['target']['id']);
        $this->assertEquals($commentId, $notif->data['target']['comment_id']);
        $this->assertEquals("/posts/{$post->id}", $notif->data['target']['url']);

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($author, $commenter, $post, $commentId) {
            $this->assertEquals($author->id, $event->recipientId);
            $this->assertEquals(1, $event->unreadCount);
            $this->assertEquals('NotificationCreated', $event->broadcastAs());

            $payload = $event->broadcastWith();
            $this->assertEquals('post_commented', $payload['notification']['type']);
            $this->assertEquals($commenter->id, $payload['notification']['actor']['id']);
            $this->assertEquals($post->id, $payload['notification']['target']['id']);
            return true;
        });
    }

    /**
     * Test 19: Parent comment author receives notification when someone replies to their comment.
     */
    public function test_19_parent_author_receives_notification_when_comment_replied(): void
    {
        Event::fake([NotificationCreated::class]);

        $postAuthor = User::factory()->create(['is_active' => true]);
        $parentAuthor = User::factory()->create(['name' => 'Bình Luận Viên Gốc', 'is_active' => true]);
        $replier = User::factory()->create(['name' => 'Người Trả Lời', 'is_active' => true]);

        $post = Post::factory()->create(['user_id' => $postAuthor->id]);
        $parentComment = Comment::factory()->create([
            'user_id' => $parentAuthor->id,
            'commentable_type' => 'post',
            'commentable_id' => $post,
            'parent_id' => null,
            'content' => 'Bình luận gốc',
        ]);

        $this->actingAs($replier, 'sanctum')
            ->postJson("/api/posts/{$post->id}/comments", [
                'content' => 'Tôi đồng ý với quan điểm của bạn!',
                'parent_id' => $parentComment->id,
            ])
            ->assertStatus(201);

        $this->assertEquals(1, $parentAuthor->notifications()->count());
        $notif = $parentAuthor->notifications()->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('đã trả lời bình luận của bạn', $notif->data['title']);

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($parentAuthor) {
            return $event->recipientId === $parentAuthor->id && $event->unreadCount === 1;
        });
    }

    /**
     * Test 20: Commenting on own post or replying to own comment does not create notification.
     */
    public function test_20_self_comment_or_reply_does_not_create_notification(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $post = Post::factory()->create(['user_id' => $user->id]);

        // Self-comment on own post
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/posts/{$post->id}/comments", [
                'content' => 'Bình luận bởi chính tác giả bài viết',
            ])
            ->assertStatus(201);

        $parentCommentId = $response->json('data.id');
        $this->assertEquals(0, $user->notifications()->count());

        // Self-reply to own comment
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/posts/{$post->id}/comments", [
                'content' => 'Tác giả tự reply bình luận của chính mình',
                'parent_id' => $parentCommentId,
            ])
            ->assertStatus(201);

        $this->assertEquals(0, $user->notifications()->count());
    }

    /**
     * Test 21: Comic creator receives notification when comic or chapter is commented.
     */
    public function test_21_author_receives_notification_when_comic_or_chapter_commented(): void
    {
        Event::fake([NotificationCreated::class]);

        $creator = User::factory()->create(['name' => 'Creator Author', 'role' => 'creator', 'is_active' => true]);
        $reader = User::factory()->create(['name' => 'Reader Commenter', 'role' => 'reader', 'is_active' => true]);

        $comic = Comic::create([
            'title' => 'Truyện Tranh Hay Nhất',
            'slug' => 'truyen-tranh-hay-nhat',
            'author_name' => 'Creator Author',
            'user_id' => $creator->id,
            'cover_path' => 'covers/sample.webp',
            'status' => 'ongoing',
        ]);
        $comic->approval_status = 'approved';
        $comic->save();

        $this->actingAs($reader, 'sanctum')
            ->postJson("/api/comics/{$comic->id}/comments", [
                'content' => 'Truyện vẽ đẹp quá!',
            ])
            ->assertStatus(201);

        $this->assertEquals(1, $creator->notifications()->count());
        $notif = $creator->notifications()->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('đã bình luận về truyện của bạn', $notif->data['title']);
        $this->assertEquals("/comics/{$comic->id}", $notif->data['target']['url']);

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($creator) {
            return $event->recipientId === $creator->id && $event->unreadCount === 1;
        });
    }
}
