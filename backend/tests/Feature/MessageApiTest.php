<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MessageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }
    }

    // =========================================================================
    // NHÓM A: Authorization & IDOR Tests
    // =========================================================================

    public function test_a1_guest_cannot_read_messages(): void
    {
        $conversation = Conversation::factory()->create();

        $this->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertUnauthorized();
    }

    public function test_a2_guest_cannot_send_messages(): void
    {
        $conversation = Conversation::factory()->create();

        $this->postJson("/api/chat/conversations/{$conversation->id}/messages", [
            'body' => 'Xin chào',
        ])->assertUnauthorized();
    }

    public function test_a3_user_not_participant_cannot_read_messages(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        // User C is NOT a participant of this conversation
        $this->actingAs($userC, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertForbidden();
    }

    public function test_a4_user_not_participant_cannot_send_messages(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        // User C attempts to send a message into A & B's conversation
        $this->actingAs($userC, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Tin nhắn spam trái phép',
            ])->assertForbidden();
    }

    public function test_a5_deactivated_user_cannot_access_messages(): void
    {
        $userA = User::factory()->create(['is_active' => false]);
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertForbidden();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Chào',
            ])->assertForbidden();
    }

    public function test_a6_cannot_read_messages_of_other_conversation_by_changing_id(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();
        $userD = User::factory()->create();

        $convAB = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $convCD = Conversation::factory()->betweenUsers($userC->id, $userD->id)->withParticipants()->create();

        // User A tampers URL to read convCD
        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$convCD->id}/messages")
            ->assertForbidden();
    }

    public function test_a7_cannot_use_cursor_of_other_conversation_to_retrieve_its_data(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();
        $userD = User::factory()->create();

        $convAB = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $convCD = Conversation::factory()->betweenUsers($userC->id, $userD->id)->withParticipants()->create();

        // Create message in CD with a secret body
        $secretMsgCD = Message::factory()->forConversationAndSender($convCD, $userC)->create([
            'body' => 'Secret message in CD conversation',
        ]);

        // User A queries convAB with before_id or after_id set to secretMsgCD->id
        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$convAB->id}/messages?after_id={$secretMsgCD->id}")
            ->assertOk();

        // The secret message must NOT appear in convAB results
        $this->assertStringNotContainsString('Secret message in CD conversation', $response->getContent());
    }

    // =========================================================================
    // NHÓM B: Index Messages & Cursor Pagination Tests
    // =========================================================================

    public function test_b1_initial_fetch_returns_latest_25_messages_by_default(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        // Create 30 messages in conversation
        for ($i = 1; $i <= 30; $i++) {
            Message::factory()->forConversationAndSender($conversation, $userA)->create([
                'body' => "Message number {$i}",
            ]);
        }

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(25, $data);
        $this->assertTrue($response->json('meta.has_more'));
        $this->assertEquals(25, $response->json('meta.limit'));
        $this->assertNotNull($response->json('meta.next_cursor'));

        // The 25 messages should be messages 6 to 30
        $this->assertEquals('Message number 6', $data[0]['body']);
        $this->assertEquals('Message number 30', $data[24]['body']);
    }

    public function test_b2_messages_are_always_sorted_ascending_by_id(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $m1 = Message::factory()->forConversationAndSender($conversation, $userA)->create(['body' => 'First']);
        $m2 = Message::factory()->forConversationAndSender($conversation, $userB)->create(['body' => 'Second']);
        $m3 = Message::factory()->forConversationAndSender($conversation, $userA)->create(['body' => 'Third']);

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(3, $data);
        $this->assertEquals($m1->id, $data[0]['id']);
        $this->assertEquals($m2->id, $data[1]['id']);
        $this->assertEquals($m3->id, $data[2]['id']);
    }

    public function test_b3_before_id_returns_older_messages_correctly(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        for ($i = 1; $i <= 20; $i++) {
            Message::factory()->forConversationAndSender($conversation, $userA)->create([
                'body' => "Message {$i}",
            ]);
        }

        // Get 11th message as cursor
        $cursorMsg = Message::where('body', 'Message 15')->first();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages?before_id={$cursorMsg->id}&limit=5")
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(5, $data);
        // Messages strictly older than Message 15: 10, 11, 12, 13, 14
        $this->assertEquals('Message 10', $data[0]['body']);
        $this->assertEquals('Message 14', $data[4]['body']);
        $this->assertTrue($response->json('meta.has_more'));
    }

    public function test_b4_after_id_returns_newer_messages_correctly(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        for ($i = 1; $i <= 20; $i++) {
            Message::factory()->forConversationAndSender($conversation, $userA)->create([
                'body' => "Message {$i}",
            ]);
        }

        $cursorMsg = Message::where('body', 'Message 15')->first();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages?after_id={$cursorMsg->id}&limit=10")
            ->assertOk();

        $data = $response->json('data');
        // Messages strictly newer than Message 15: 16, 17, 18, 19, 20 (5 messages)
        $this->assertCount(5, $data);
        $this->assertEquals('Message 16', $data[0]['body']);
        $this->assertEquals('Message 20', $data[4]['body']);
        $this->assertFalse($response->json('meta.has_more'));
        $this->assertNull($response->json('meta.next_cursor'));
    }

    public function test_b5_empty_messages_returns_empty_data_with_proper_meta(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertOk();

        $this->assertEquals([], $response->json('data'));
        $this->assertFalse($response->json('meta.has_more'));
        $this->assertNull($response->json('meta.next_cursor'));
    }

    public function test_b6_pagination_when_more_messages_exist_sets_has_more_and_next_cursor(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        for ($i = 1; $i <= 10; $i++) {
            Message::factory()->forConversationAndSender($conversation, $userA)->create();
        }

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages?limit=5")
            ->assertOk();

        $this->assertCount(5, $response->json('data'));
        $this->assertTrue($response->json('meta.has_more'));
        $this->assertNotNull($response->json('meta.next_cursor'));
    }

    public function test_b7_no_messages_missed_between_consecutive_polling_requests(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        // Base anchor message
        $baseMsg = Message::factory()->forConversationAndSender($conversation, $userA)->create([
            'body' => 'Base anchor',
        ]);

        // 40 new messages total after the base anchor
        for ($i = 1; $i <= 40; $i++) {
            Message::factory()->forConversationAndSender($conversation, $userA)->create([
                'body' => "Msg {$i}",
            ]);
        }

        // Request 1: after_id = baseMsg->id, limit = 15
        $res1 = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages?after_id={$baseMsg->id}&limit=15")
            ->assertOk();
        $this->assertCount(15, $res1->json('data'));
        $this->assertTrue($res1->json('meta.has_more'));
        $cursor1 = $res1->json('meta.next_cursor');

        // Request 2: after_id = cursor1, limit = 15
        $res2 = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages?after_id={$cursor1}&limit=15")
            ->assertOk();
        $this->assertCount(15, $res2->json('data'));
        $this->assertTrue($res2->json('meta.has_more'));
        $cursor2 = $res2->json('meta.next_cursor');

        // Request 3: after_id = cursor2, limit = 15
        $res3 = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages?after_id={$cursor2}&limit=15")
            ->assertOk();
        $this->assertCount(10, $res3->json('data'));
        $this->assertFalse($res3->json('meta.has_more'));
        $this->assertNull($res3->json('meta.next_cursor'));

        // Verify all 40 messages accounted for without overlap
        $allBodies = array_merge(
            collect($res1->json('data'))->pluck('body')->all(),
            collect($res2->json('data'))->pluck('body')->all(),
            collect($res3->json('data'))->pluck('body')->all()
        );
        $this->assertCount(40, $allBodies);
        $this->assertEquals('Msg 1', $allBodies[0]);
        $this->assertEquals('Msg 40', $allBodies[39]);
    }

    public function test_b8_cursor_does_not_leak_messages_from_other_conversations(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();

        $conv1 = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $conv2 = Conversation::factory()->betweenUsers($userA->id, $userC->id)->withParticipants()->create();

        Message::factory()->forConversationAndSender($conv1, $userA)->create(['body' => 'Conv1 Msg']);
        $msg2 = Message::factory()->forConversationAndSender($conv2, $userA)->create(['body' => 'Conv2 Secret Msg']);

        // Query conv1 with after_id set to conv2 message id
        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conv1->id}/messages?after_id={$msg2->id}")
            ->assertOk();

        // Must strictly not return conv2 messages
        $this->assertStringNotContainsString('Conv2 Secret Msg', $response->getContent());
    }

    public function test_b9_limit_cannot_exceed_fifty(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages?limit=51")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);
    }

    public function test_b10_index_messages_avoids_n_plus_one_queries(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        // Create 10 messages
        for ($i = 0; $i < 10; $i++) {
            Message::factory()->forConversationAndSender($conversation, $userA)->create();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages?limit=5")
            ->assertOk();

        $queries5 = count(DB::getQueryLog());

        // Create 10 more messages (total 20)
        for ($i = 0; $i < 10; $i++) {
            Message::factory()->forConversationAndSender($conversation, $userA)->create();
        }

        DB::flushQueryLog();

        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages?limit=10")
            ->assertOk();

        $queries10 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Query count should be identical regardless of limit
        $this->assertEquals($queries5, $queries10, 'Message index must not suffer from N+1 query growth.');
    }

    // =========================================================================
    // NHÓM C: Store Message Tests
    // =========================================================================

    public function test_c1_send_valid_message_returns_201(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $clientUuid = (string) Str::uuid();

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Xin chào bạn nhé!',
                'type' => 'text',
                'client_message_id' => $clientUuid,
            ])->assertCreated();

        $response->assertJsonStructure([
            'data' => [
                'id',
                'conversation_id',
                'sender_id',
                'type',
                'body',
                'client_message_id',
                'created_at',
            ],
        ]);

        $this->assertEquals('Xin chào bạn nhé!', $response->json('data.body'));
        $this->assertEquals($conversation->id, $response->json('data.conversation_id'));
        $this->assertEquals($userA->id, $response->json('data.sender_id'));
        $this->assertEquals($clientUuid, $response->json('data.client_message_id'));
    }

    public function test_c2_message_is_attached_to_correct_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Test attachment',
            ])->assertCreated();

        $this->assertDatabaseHas('messages', [
            'id' => $response->json('data.id'),
            'conversation_id' => $conversation->id,
        ]);
    }

    public function test_c3_sender_is_taken_from_authenticated_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Test sender auth',
            ])->assertCreated();

        $this->assertEquals($userA->id, $response->json('data.sender_id'));
    }

    public function test_c4_client_cannot_assign_sender_id(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Tamper sender',
                'sender_id' => $userB->id,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['sender_id']);
    }

    public function test_c5_client_cannot_assign_conversation_id(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Tamper conversation_id',
                'conversation_id' => 9999,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['conversation_id']);
    }

    public function test_c6_empty_body_is_rejected(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => '',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_c7_body_exceeding_2000_characters_is_rejected(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => str_repeat('A', 2001),
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_c8_type_other_than_text_is_rejected(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Hello',
                'type' => 'image',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_c9_non_participant_cannot_send_message(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $stranger = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Unauthorized hello',
            ])->assertForbidden();
    }

    public function test_c10_conversation_last_message_id_updated_correctly(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->assertNull($conversation->last_message_id);

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Updating last message id',
            ])->assertCreated();

        $msgId = $response->json('data.id');
        $conversation->refresh();

        $this->assertEquals($msgId, $conversation->last_message_id);
    }

    public function test_c11_conversation_last_message_at_updated_correctly(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Updating last message at',
            ])->assertCreated();

        $conversation->refresh();
        $this->assertNotNull($conversation->last_message_at);
        $this->assertEquals($response->json('data.created_at'), $conversation->last_message_at->toISOString());
    }

    public function test_c12_transaction_rollback_if_conversation_update_fails(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        // Listen for query on conversations table and throw exception to simulate DB crash during update
        DB::listen(function ($query) {
            if (stripos($query->sql, 'update') !== false && stripos($query->sql, 'conversations') !== false) {
                throw new \RuntimeException('Simulated DB failure during conversation update');
            }
        });

        $service = app(MessageService::class);

        try {
            $service->sendMessage($userA, $conversation, [
                'body' => 'This should be rolled back',
            ]);
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Message record must NOT exist in database due to transaction rollback
        $this->assertEquals(0, Message::where('conversation_id', $conversation->id)->count());
    }

    public function test_c13_sending_message_does_not_update_senders_last_read_message_id(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $participantA = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $userA->id)
            ->first();

        $this->assertNull($participantA->last_read_message_id);

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Sender sending a message',
            ])->assertCreated();

        $participantA->refresh();
        $this->assertNull($participantA->last_read_message_id, 'Sending a message MUST NOT automatically mark messages as read for sender.');
    }

    // =========================================================================
    // NHÓM D: Idempotency Tests
    // =========================================================================

    public function test_d1_first_send_returns_201_created(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $uuid = (string) Str::uuid();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Initial message',
                'client_message_id' => $uuid,
            ])->assertCreated();

        $this->assertEquals(1, Message::where('client_message_id', $uuid)->count());
    }

    public function test_d2_retry_same_client_message_id_and_same_body_returns_200_ok(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $uuid = (string) Str::uuid();

        // 1st request -> 201 Created
        $res1 = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Idempotent test',
                'client_message_id' => $uuid,
            ])->assertCreated();

        // 2nd request (retry) -> 200 OK with same message ID
        $res2 = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Idempotent test',
                'client_message_id' => $uuid,
            ])->assertOk();

        $this->assertEquals($res1->json('data.id'), $res2->json('data.id'));
        $this->assertEquals(1, Message::where('client_message_id', $uuid)->count());
    }

    public function test_d3_retry_same_client_message_id_with_different_body_returns_409_conflict(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $uuid = (string) Str::uuid();

        // 1st request
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Original body',
                'client_message_id' => $uuid,
            ])->assertCreated();

        // 2nd request with different body -> 409 Conflict
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Modified body with same key',
                'client_message_id' => $uuid,
            ])->assertStatus(409);
    }

    public function test_d4_two_different_senders_can_use_same_client_message_id_without_conflict(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $sharedUuid = (string) Str::uuid();

        // User A sends with sharedUuid
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'User A message',
                'client_message_id' => $sharedUuid,
            ])->assertCreated();

        // User B sends with sharedUuid in same conversation
        $this->actingAs($userB, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'User B message',
                'client_message_id' => $sharedUuid,
            ])->assertCreated();

        $this->assertEquals(2, Message::where('client_message_id', $sharedUuid)->count());
    }

    public function test_d5_multiple_messages_with_null_client_message_id_are_allowed(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Message without client id 1',
            ])->assertCreated();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Message without client id 2',
            ])->assertCreated();

        $this->assertEquals(2, Message::where('conversation_id', $conversation->id)->whereNull('client_message_id')->count());
    }

    public function test_d6_duplicate_key_race_condition_handled_gracefully(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $uuid = (string) Str::uuid();

        // Simulate pre-existing message created concurrently
        $existing = Message::factory()->forConversationAndSender($conversation, $userA)->create([
            'body' => 'Concurrent body',
            'type' => 'text',
            'client_message_id' => $uuid,
        ]);

        $service = app(MessageService::class);

        // Attempting to send identical payload returns existing with 200
        $result = $service->sendMessage($userA, $conversation, [
            'body' => 'Concurrent body',
            'type' => 'text',
            'client_message_id' => $uuid,
        ]);

        $this->assertEquals(200, $result['status']);
        $this->assertEquals($existing->id, $result['message']->id);
    }

    public function test_d7_unrelated_query_exceptions_are_re_thrown(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $service = app(MessageService::class);

        // Hook into Message creating to throw an unrelated QueryException
        Message::creating(function () {
            throw new QueryException('sqlite', 'SYNTAX ERROR', [], new \Exception('Unrelated SQL error'));
        });

        $this->expectException(QueryException::class);

        $service->sendMessage($userA, $conversation, [
            'body' => 'Failing message',
        ]);
    }

    public function test_d8_retry_does_not_create_additional_message_records(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $uuid = (string) Str::uuid();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Single record test',
                'client_message_id' => $uuid,
            ])->assertCreated();

        // Retry 3 times
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($userA, 'sanctum')
                ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                    'body' => 'Single record test',
                    'client_message_id' => $uuid,
                ])->assertOk();
        }

        $this->assertEquals(1, Message::where('conversation_id', $conversation->id)->count());
    }

    public function test_d9_retry_does_not_overwrite_last_message_with_older_message(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $uuidOld = (string) Str::uuid();

        // 1. Send first message
        $res1 = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Message 1',
                'client_message_id' => $uuidOld,
            ])->assertCreated();

        // 2. Send second message
        $res2 = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Message 2',
            ])->assertCreated();

        $conversation->refresh();
        $this->assertEquals($res2->json('data.id'), $conversation->last_message_id);

        // 3. Retry first message (with old uuid)
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Message 1',
                'client_message_id' => $uuidOld,
            ])->assertOk();

        // last_message_id must still be Message 2!
        $conversation->refresh();
        $this->assertEquals($res2->json('data.id'), $conversation->last_message_id);
    }

    // =========================================================================
    // NHÓM E: Regression Tests
    // =========================================================================

    public function test_e1_conversation_index_displays_last_message_correctly(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        // Send a message
        $res = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Preview body test',
            ])->assertCreated();

        // Fetch conversation index
        $indexRes = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();

        $convData = $indexRes->json('data.0');
        $this->assertEquals($res->json('data.id'), $convData['last_message']['id']);
        $this->assertEquals('Preview body test', $convData['last_message']['body']);
    }

    public function test_e2_conversation_show_works_properly(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}")
            ->assertOk();
    }

    public function test_e3_conversation_unread_count_does_not_count_own_messages(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        // User A sends 3 messages
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($userA, 'sanctum')
                ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                    'body' => "A message {$i}",
                ])->assertCreated();
        }

        // Check index for User A -> unread_count should be 0
        $resA = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();
        $this->assertEquals(0, $resA->json('data.0.unread_count'));

        // Check index for User B -> unread_count should be 3
        $resB = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();
        $this->assertEquals(3, $resB->json('data.0.unread_count'));
    }

    public function test_e4_read_endpoint_is_fully_functional(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $msg = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $msg->id,
            ])->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'conversation_id' => $conversation->id,
                    'last_read_message_id' => $msg->id,
                ],
            ]);
    }

    public function test_e5_unread_count_endpoint_is_fully_functional(): void
    {
        $userA = User::factory()->create();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total_unread',
                    'conversations',
                ],
            ]);
    }

    public function test_e6_community_and_library_apis_are_not_regressed(): void
    {
        $this->getJson('/api/comics')->assertOk();

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/follows')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/bookmarks')
            ->assertOk();
    }
}
