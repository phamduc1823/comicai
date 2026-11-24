<?php

namespace Tests\Feature;

use App\Events\MessagesRead;
use App\Http\Resources\ConversationParticipantResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MessagingBugFixesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bug 1: Verify Message model and MessageResource strictly cast sender_id and IDs to integer.
     */
    public function test_message_resource_strictly_serializes_sender_id_as_integer(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::create([
            'type' => 'direct',
            'user_one_id' => min($userA->id, $userB->id),
            'user_two_id' => max($userA->id, $userB->id),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userA->id,
            'type' => 'text',
            'body' => 'Hello from User A',
        ]);

        $resourceArray = (new MessageResource($message))->toArray(new Request());

        $this->assertIsInt($resourceArray['id']);
        $this->assertIsInt($resourceArray['conversation_id']);
        $this->assertIsInt($resourceArray['sender_id']);
        $this->assertSame((int) $userA->id, $resourceArray['sender_id']);
    }

    /**
     * Bug 2: Verify ConversationParticipantResource strictly serializes IDs and last_read_message_id as integers.
     */
    public function test_conversation_participant_resource_strictly_serializes_ids_and_last_read_message_id_as_integers(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::create([
            'type' => 'direct',
            'user_one_id' => min($userA->id, $userB->id),
            'user_two_id' => max($userA->id, $userB->id),
        ]);

        $participantWithNull = ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $userA->id,
            'last_read_message_id' => null,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userA->id,
            'body' => 'Existing msg',
        ]);

        $participantWithId = ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $userB->id,
            'last_read_message_id' => $message->id,
        ]);

        $resNull = (new ConversationParticipantResource($participantWithNull))->toArray(new Request());
        $this->assertIsInt($resNull['id']);
        $this->assertIsInt($resNull['conversation_id']);
        $this->assertIsInt($resNull['user_id']);
        $this->assertNull($resNull['last_read_message_id']);

        $resId = (new ConversationParticipantResource($participantWithId))->toArray(new Request());
        $this->assertIsInt($resId['id']);
        $this->assertIsInt($resId['conversation_id']);
        $this->assertIsInt($resId['user_id']);
        $this->assertIsInt($resId['last_read_message_id']);
        $this->assertSame((int) $message->id, $resId['last_read_message_id']);
    }

    /**
     * Bug 1 & 2: Verify ConversationResource strictly serializes last_message sender_id and id as integers.
     */
    public function test_conversation_resource_strictly_serializes_last_message_sender_id_as_integer(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::create([
            'type' => 'direct',
            'user_one_id' => min($userA->id, $userB->id),
            'user_two_id' => max($userA->id, $userB->id),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userA->id,
            'type' => 'text',
            'body' => 'Recent message',
        ]);

        $conversation->update(['last_message_id' => $message->id]);
        $conversation->load('lastMessage');

        $request = Request::create('/');
        $request->setUserResolver(fn () => $userB);

        $res = (new ConversationResource($conversation))->toArray($request);

        $this->assertIsInt($res['id']);
        $this->assertNotNull($res['last_message']);
        $this->assertIsInt($res['last_message']['id']);
        $this->assertIsInt($res['last_message']['sender_id']);
        $this->assertSame((int) $userA->id, $res['last_message']['sender_id']);
    }

    /**
     * Bug 3: Verify calculateUnreadCount when last_read_message_id is null counts all recipient messages.
     */
    public function test_calculate_unread_count_when_last_read_message_id_is_null_counts_all_recipient_messages(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::create([
            'type' => 'direct',
            'user_one_id' => min($userA->id, $userB->id),
            'user_two_id' => max($userA->id, $userB->id),
        ]);

        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $userA->id,
            'last_read_message_id' => null,
        ]);
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $userB->id,
            'last_read_message_id' => null,
        ]);

        // A sends 3 messages to B
        Message::create(['conversation_id' => $conversation->id, 'sender_id' => $userA->id, 'body' => 'Msg 1']);
        Message::create(['conversation_id' => $conversation->id, 'sender_id' => $userA->id, 'body' => 'Msg 2']);
        Message::create(['conversation_id' => $conversation->id, 'sender_id' => $userA->id, 'body' => 'Msg 3']);

        $service = app(ConversationService::class);

        // B should see 3 unread messages
        $unreadB = $service->calculateUnreadCount($conversation, $userB->id);
        $this->assertSame(3, $unreadB);

        // A should see 0 unread messages (own messages don't count)
        $unreadA = $service->calculateUnreadCount($conversation, $userA->id);
        $this->assertSame(0, $unreadA);
    }

    /**
     * Bug 3: Verify calculateUnreadCount with non-null last_read_message_id accurately counts only newer messages.
     */
    public function test_calculate_unread_count_when_last_read_message_id_is_present_counts_only_newer_messages(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::create([
            'type' => 'direct',
            'user_one_id' => min($userA->id, $userB->id),
            'user_two_id' => max($userA->id, $userB->id),
        ]);

        $msg1 = Message::create(['conversation_id' => $conversation->id, 'sender_id' => $userA->id, 'body' => 'Msg 1']);
        $msg2 = Message::create(['conversation_id' => $conversation->id, 'sender_id' => $userA->id, 'body' => 'Msg 2']);
        $msg3 = Message::create(['conversation_id' => $conversation->id, 'sender_id' => $userA->id, 'body' => 'Msg 3']);

        // B has read up to Msg 2
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $userB->id,
            'last_read_message_id' => $msg2->id,
        ]);

        $service = app(ConversationService::class);

        // B should have exactly 1 unread message (Msg 3)
        $unreadB = $service->calculateUnreadCount($conversation, $userB->id);
        $this->assertSame(1, $unreadB);

        // After B reads Msg 3
        $service->markAsRead($userB, $conversation, $msg3->id);
        $unreadAfterRead = $service->calculateUnreadCount($conversation, $userB->id);
        $this->assertSame(0, $unreadAfterRead);
    }

    /**
     * Bug 2: Verify markAsRead updates participant and dispatches MessagesRead event with integer IDs.
     */
    public function test_mark_as_read_updates_participant_and_dispatches_messages_read_event(): void
    {
        Event::fake([MessagesRead::class]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::create([
            'type' => 'direct',
            'user_one_id' => min($userA->id, $userB->id),
            'user_two_id' => max($userA->id, $userB->id),
        ]);

        $participant = ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $userB->id,
            'last_read_message_id' => null,
        ]);

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userA->id,
            'body' => 'Test message',
        ]);

        $service = app(ConversationService::class);
        $res = $service->markAsRead($userB, $conversation, $msg->id);

        $this->assertSame((int) $msg->id, (int) $res['last_read_message_id']);

        Event::assertDispatched(MessagesRead::class, function (MessagesRead $event) use ($conversation, $userB, $msg) {
            $data = $event->broadcastWith();
            return $data['conversation_id'] === (int) $conversation->id
                && $data['user_id'] === (int) $userB->id
                && $data['last_read_message_id'] === (int) $msg->id;
        });
    }

    /**
     * Bug 3: Verify /api/chat/unread-count endpoint returns accurate unread counts.
     */
    public function test_unread_count_endpoint_returns_accurate_totals(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::create([
            'type' => 'direct',
            'user_one_id' => min($userA->id, $userB->id),
            'user_two_id' => max($userA->id, $userB->id),
        ]);

        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $userA->id,
            'last_read_message_id' => null,
        ]);
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $userB->id,
            'last_read_message_id' => null,
        ]);

        Message::create(['conversation_id' => $conversation->id, 'sender_id' => $userA->id, 'body' => 'A1']);
        Message::create(['conversation_id' => $conversation->id, 'sender_id' => $userA->id, 'body' => 'A2']);

        // User B calls unread-count endpoint
        $response = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();

        $response->assertJson([
            'data' => [
                'total_unread' => 2,
                'conversations' => [
                    [
                        'conversation_id' => $conversation->id,
                        'unread_count' => 2,
                    ],
                ],
            ],
        ]);
    }
}
