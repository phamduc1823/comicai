<?php

namespace Tests\Feature;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatBroadcastEventsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user1;
    protected User $user2;
    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user1 = User::factory()->create([
            'name' => 'Alice',
            'is_active' => true,
        ]);

        $this->user2 = User::factory()->create([
            'name' => 'Bob',
            'is_active' => true,
        ]);

        $this->conversation = Conversation::create([
            'type' => 'direct',
            'user_one_id' => min($this->user1->id, $this->user2->id),
            'user_two_id' => max($this->user1->id, $this->user2->id),
        ]);

        $this->conversation->participants()->createMany([
            ['user_id' => $this->user1->id],
            ['user_id' => $this->user2->id],
        ]);
    }

    /**
     * Test 1: MessageSent event broadcasts to correct conversation channel with MessageResource payload.
     */
    public function test_message_sent_event_broadcasts_to_correct_conversation_channel_with_resource_payload(): void
    {
        Event::fake([MessageSent::class, ConversationUpdated::class]);

        $uuid = (string) Str::uuid();

        $response = $this->actingAs($this->user1, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'body' => 'Hello Reverb World',
                'type' => 'text',
                'client_message_id' => $uuid,
            ]);

        $response->assertStatus(201);
        $messageId = $response->json('data.id');

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($messageId, $uuid) {
            $channels = $event->broadcastOn();
            $this->assertGreaterThanOrEqual(1, count($channels));
            $this->assertEquals('private-chat.conversation.' . $this->conversation->id, $channels[0]->name);
            $this->assertEquals('MessageSent', $event->broadcastAs());

            $payload = $event->broadcastWith();
            $this->assertEquals($messageId, $payload['id']);
            $this->assertEquals($this->conversation->id, $payload['conversation_id']);
            $this->assertEquals($this->user1->id, $payload['sender_id']);
            $this->assertEquals('Hello Reverb World', $payload['body']);
            $this->assertEquals('text', $payload['type']);
            $this->assertEquals($uuid, $payload['client_message_id']);
            $this->assertArrayHasKey('created_at', $payload);

            // Also supports nested message wrapper
            $this->assertArrayHasKey('message', $payload);
            $this->assertEquals($messageId, $payload['message']['id']);

            return true;
        });
    }

    /**
     * Test 2: ConversationUpdated event broadcasts to recipient user channel with accurate unread count.
     */
    public function test_conversation_updated_event_broadcasts_to_recipient_user_channel_with_unread_count(): void
    {
        Event::fake([MessageSent::class, ConversationUpdated::class]);

        $response = $this->actingAs($this->user1, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'body' => 'Notice to recipient',
            ]);

        $response->assertStatus(201);

        Event::assertDispatched(ConversationUpdated::class, function (ConversationUpdated $event) {
            $channels = $event->broadcastOn();
            $this->assertCount(1, $channels);
            $this->assertEquals('private-chat.user.' . $this->user2->id, $channels[0]->name);
            $this->assertEquals('ConversationUpdated', $event->broadcastAs());

            $payload = $event->broadcastWith();
            $this->assertEquals($this->conversation->id, $payload['conversation_id']);
            $this->assertEquals('Notice to recipient', $payload['last_message']['body']);
            $this->assertEquals(1, $payload['unread_count']);
            $this->assertEquals(1, $event->unreadCount);

            return true;
        });
    }

    /**
     * Test 3: ConversationUpdated is never dispatched to sender's user channel.
     */
    public function test_conversation_updated_event_is_never_broadcast_to_sender(): void
    {
        Event::fake([MessageSent::class, ConversationUpdated::class]);

        $this->actingAs($this->user1, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'body' => 'Message from sender',
            ])
            ->assertStatus(201);

        Event::assertNotDispatched(ConversationUpdated::class, function (ConversationUpdated $event) {
            return $event->recipientId === $this->user1->id;
        });
    }

    /**
     * Test 4: Idempotent message replay does not dispatch any broadcast events.
     */
    public function test_idempotent_message_replay_does_not_dispatch_any_broadcast_events(): void
    {
        $uuid = (string) Str::uuid();

        // First request creates message and dispatches events
        Event::fake([MessageSent::class, ConversationUpdated::class]);

        $this->actingAs($this->user1, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'body' => 'Idempotent body',
                'client_message_id' => $uuid,
            ])
            ->assertStatus(201);

        Event::assertDispatched(MessageSent::class, 1);
        Event::assertDispatched(ConversationUpdated::class, 1);

        // Reset event fake for subsequent replay request
        Event::fake([MessageSent::class, ConversationUpdated::class]);

        $replayResponse = $this->actingAs($this->user1, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'body' => 'Idempotent body',
                'client_message_id' => $uuid,
            ]);

        $replayResponse->assertStatus(200);

        // Strictly NO events dispatched on replay
        Event::assertNotDispatched(MessageSent::class);
        Event::assertNotDispatched(ConversationUpdated::class);
    }

    /**
     * Test 5: Advance read marker broadcasts MessagesRead event to conversation channel.
     */
    public function test_mark_as_read_broadcasts_messages_read_event_when_read_marker_advances(): void
    {
        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender_id' => $this->user1->id,
            'body' => 'Read me',
        ]);

        Event::fake([MessagesRead::class]);

        $response = $this->actingAs($this->user2, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/read", [
                'last_read_message_id' => $message->id,
            ]);

        $response->assertStatus(200);

        Event::assertDispatched(MessagesRead::class, function (MessagesRead $event) use ($message) {
            $channels = $event->broadcastOn();
            $this->assertGreaterThanOrEqual(1, count($channels));
            $this->assertEquals('private-chat.conversation.' . $this->conversation->id, $channels[0]->name);
            $this->assertEquals('MessagesRead', $event->broadcastAs());

            $payload = $event->broadcastWith();
            $this->assertEquals($this->conversation->id, $payload['conversation_id']);
            $this->assertEquals($this->user2->id, $payload['user_id']);
            $this->assertEquals($message->id, $payload['last_read_message_id']);
            $this->assertNotNull($payload['last_read_at']);

            return true;
        });
    }

    /**
     * Test 6: Stale or duplicate read marker does not broadcast MessagesRead event.
     */
    public function test_mark_as_read_does_not_broadcast_when_read_marker_is_not_advanced(): void
    {
        $msg1 = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender_id' => $this->user1->id,
            'body' => 'First message',
        ]);
        $msg2 = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender_id' => $this->user1->id,
            'body' => 'Second message',
        ]);

        // Advance read marker to msg2
        $this->actingAs($this->user2, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/read", [
                'last_read_message_id' => $msg2->id,
            ])
            ->assertStatus(200);

        // Now fake events for duplicate / backwards calls
        Event::fake([MessagesRead::class]);

        // Duplicate call with same last_read_message_id
        $this->actingAs($this->user2, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/read", [
                'last_read_message_id' => $msg2->id,
            ])
            ->assertStatus(200);

        Event::assertNotDispatched(MessagesRead::class);

        // Backwards call with older last_read_message_id
        $this->actingAs($this->user2, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/read", [
                'last_read_message_id' => $msg1->id,
            ])
            ->assertStatus(200);

        Event::assertNotDispatched(MessagesRead::class);
    }

    /**
     * Test 7: Broadcast event names are consistent with broadcastAs contract.
     */
    public function test_broadcast_event_names_broadcast_as(): void
    {
        $message = Message::factory()->make([
            'id' => 10,
            'conversation_id' => 1,
            'sender_id' => 2,
        ]);

        $sent = new MessageSent($message);
        $this->assertEquals('MessageSent', $sent->broadcastAs());

        $updated = new ConversationUpdated(1, 2, $message, 3);
        $this->assertEquals('ConversationUpdated', $updated->broadcastAs());

        $read = new MessagesRead(1, 2, 10, '2026-11-21T00:00:00.000000Z');
        $this->assertEquals('MessagesRead', $read->broadcastAs());
    }

    /**
     * Test 8: Failed transaction does not dispatch any broadcast events.
     */
    public function test_failed_transaction_does_not_dispatch_broadcast_events(): void
    {
        Event::fake([MessageSent::class, ConversationUpdated::class]);

        // Triggering with invalid data that fails validation
        $response = $this->actingAs($this->user1, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'body' => '', // empty body fails validation
            ]);

        $response->assertStatus(422);

        Event::assertNotDispatched(MessageSent::class);
        Event::assertNotDispatched(ConversationUpdated::class);
    }

    /**
     * Test 9: Non-participant cannot trigger broadcast events.
     */
    public function test_non_participant_cannot_trigger_broadcast_events(): void
    {
        $intruder = User::factory()->create(['is_active' => true]);

        Event::fake([MessageSent::class, ConversationUpdated::class, MessagesRead::class]);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'body' => 'I am an intruder',
            ])
            ->assertStatus(403);

        Event::assertNotDispatched(MessageSent::class);
        Event::assertNotDispatched(ConversationUpdated::class);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/read", [
                'last_read_message_id' => 1,
            ])
            ->assertStatus(403);

        Event::assertNotDispatched(MessagesRead::class);
    }

    /**
     * Test 10: Broadcasting failure does not crash REST message creation.
     */
    public function test_broadcasting_failure_does_not_break_message_creation_response(): void
    {
        // Simulate broadcast transport failure on MessageSent event
        Event::listen(MessageSent::class, function () {
            throw new \RuntimeException('Reverb connection timeout');
        });

        $response = $this->actingAs($this->user1, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'body' => 'Message with failing broadcaster',
            ]);

        // REST request must still succeed with 201 Created
        $response->assertStatus(201)
            ->assertJsonPath('data.body', 'Message with failing broadcaster');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'body' => 'Message with failing broadcaster',
        ]);
    }

    /**
     * Test 11: Broadcasting failure does not crash REST read marker endpoint.
     */
    public function test_broadcasting_failure_does_not_break_read_marker_response(): void
    {
        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender_id' => $this->user1->id,
            'body' => 'Unread message',
        ]);

        // Simulate broadcast transport failure on MessagesRead event
        Event::listen(MessagesRead::class, function () {
            throw new \RuntimeException('Reverb connection timeout');
        });

        $response = $this->actingAs($this->user2, 'sanctum')
            ->postJson("/api/chat/conversations/{$this->conversation->id}/read", [
                'last_read_message_id' => $message->id,
            ]);

        // REST request must still succeed with 200 OK
        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.last_read_message_id', $message->id);
    }
}
