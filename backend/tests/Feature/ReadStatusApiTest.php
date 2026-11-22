<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadStatusApiTest extends TestCase
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
    // NHÓM A: Authorization Tests
    // =========================================================================

    public function test_a1_guest_cannot_mark_as_read(): void
    {
        $conversation = Conversation::factory()->create();

        $this->postJson("/api/chat/conversations/{$conversation->id}/read", [
            'last_read_message_id' => 1,
        ])->assertUnauthorized();
    }

    public function test_a2_guest_cannot_view_unread_count(): void
    {
        $this->getJson('/api/chat/unread-count')
            ->assertUnauthorized();
    }

    public function test_a3_non_participant_cannot_mark_as_read(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $stranger = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $message = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $message->id,
            ])->assertForbidden();
    }

    public function test_a4_inactive_user_cannot_access_read_endpoints(): void
    {
        $userA = User::factory()->create(['is_active' => false]);
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $message = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $message->id,
            ])->assertForbidden();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertForbidden();
    }

    public function test_a5_changing_conversation_id_on_url_is_blocked_by_policy(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();
        $userD = User::factory()->create();

        $convAB = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $convCD = Conversation::factory()->betweenUsers($userC->id, $userD->id)->withParticipants()->create();

        $msgCD = Message::factory()->forConversationAndSender($convCD, $userD)->create();

        // User A attempts to mark read on convCD
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$convCD->id}/read", [
                'last_read_message_id' => $msgCD->id,
            ])->assertForbidden();
    }

    // =========================================================================
    // NHÓM B: Mark As Read Tests
    // =========================================================================

    public function test_b1_mark_valid_message_succeeds(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $message = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $message->id,
            ])->assertOk();

        $response->assertJson([
            'status' => 'success',
            'data' => [
                'conversation_id' => $conversation->id,
                'last_read_message_id' => $message->id,
            ],
        ]);

        $this->assertNotNull($response->json('data.last_read_at'));

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $userA->id,
            'last_read_message_id' => $message->id,
        ]);
    }

    public function test_b2_message_belonging_to_different_conversation_is_rejected(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();

        $conv1 = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $conv2 = Conversation::factory()->betweenUsers($userA->id, $userC->id)->withParticipants()->create();

        // Message belongs to conv2
        $msgConv2 = Message::factory()->forConversationAndSender($conv2, $userC)->create();

        // User A attempts to use msgConv2 ID to mark read on conv1
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conv1->id}/read", [
                'last_read_message_id' => $msgConv2->id,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['last_read_message_id']);
    }

    public function test_b3_non_existent_message_is_rejected(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => 999999,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['last_read_message_id']);
    }

    public function test_b4_missing_last_read_message_id_is_rejected(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['last_read_message_id']);
    }

    public function test_b5_invalid_message_id_is_rejected(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => 0,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['last_read_message_id']);

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => 'abc',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['last_read_message_id']);
    }

    public function test_b6_null_last_read_is_updated_to_new_marker(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $message = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        // Initial participant state has null last_read_message_id
        $participantA = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $userA->id)
            ->first();
        $this->assertNull($participantA->last_read_message_id);

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $message->id,
            ])->assertOk();

        $participantA->refresh();
        $this->assertEquals($message->id, $participantA->last_read_message_id);
    }

    public function test_b7_read_marker_advances_from_lower_to_higher_id(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $m1 = Message::factory()->forConversationAndSender($conversation, $userB)->create();
        $m2 = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        // Read up to m1
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $m1->id,
            ])->assertOk();

        $participantA = ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $userA->id)->first();
        $this->assertEquals($m1->id, $participantA->last_read_message_id);

        // Advance to m2
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $m2->id,
            ])->assertOk();

        $participantA->refresh();
        $this->assertEquals($m2->id, $participantA->last_read_message_id);
    }

    public function test_b8_read_marker_does_not_move_backward(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $m1 = Message::factory()->forConversationAndSender($conversation, $userB)->create();
        $m2 = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        // Advance to m2 (higher ID)
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $m2->id,
            ])->assertOk();

        // Attempt to move backward to m1 (lower ID)
        $response = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $m1->id,
            ])->assertOk();

        // Response and DB must still reflect m2
        $this->assertEquals($m2->id, $response->json('data.last_read_message_id'));

        $participantA = ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $userA->id)->first();
        $this->assertEquals($m2->id, $participantA->last_read_message_id);
    }

    public function test_b9_resending_same_marker_does_not_cause_error(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $m1 = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        // 1st request
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $m1->id,
            ])->assertOk();

        // 2nd request with exact same marker
        $res2 = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $m1->id,
            ])->assertOk();

        $this->assertEquals($m1->id, $res2->json('data.last_read_message_id'));
    }

    public function test_b10_late_arriving_older_marker_does_not_overwrite_newer_marker(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $m1 = Message::factory()->forConversationAndSender($conversation, $userB)->create();
        $m2 = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        // Simulate m2 already processed in database
        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $userA->id)
            ->update([
                'last_read_message_id' => $m2->id,
                'last_read_at' => now(),
            ]);

        // Delayed request with m1 arrives
        $response = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $m1->id,
            ])->assertOk();

        $this->assertEquals($m2->id, $response->json('data.last_read_message_id'));

        $participantA = ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $userA->id)->first();
        $this->assertEquals($m2->id, $participantA->last_read_message_id);
    }

    public function test_b11_last_read_at_is_updated_appropriately(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $m1 = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        $res = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $m1->id,
            ])->assertOk();

        $this->assertNotNull($res->json('data.last_read_at'));

        $participantA = ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $userA->id)->first();
        $this->assertNotNull($participantA->last_read_at);
    }

    public function test_b12_other_participant_is_not_modified(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $m1 = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        $participantB = ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $userB->id)->first();
        $this->assertNull($participantB->last_read_message_id);

        // User A marks as read
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $m1->id,
            ])->assertOk();

        // User B's marker MUST remain untouched
        $participantB->refresh();
        $this->assertNull($participantB->last_read_message_id);
    }

    // =========================================================================
    // NHÓM C: Unread Count Tests
    // =========================================================================

    public function test_c1_no_unread_messages_returns_total_unread_zero(): void
    {
        $userA = User::factory()->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();

        $this->assertEquals([
            'data' => [
                'total_unread' => 0,
                'conversations' => [],
            ],
        ], $response->json());
    }

    public function test_c2_counts_incoming_messages_from_recipient_correctly(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        // User B sends 3 messages
        Message::factory()->count(3)->forConversationAndSender($conversation, $userB)->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();

        $this->assertEquals(3, $response->json('data.total_unread'));
        $this->assertCount(1, $response->json('data.conversations'));
        $this->assertEquals($conversation->id, $response->json('data.conversations.0.conversation_id'));
        $this->assertEquals(3, $response->json('data.conversations.0.unread_count'));
    }

    public function test_c3_does_not_count_own_messages(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        // User A sends 5 messages
        Message::factory()->count(5)->forConversationAndSender($conversation, $userA)->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();

        $this->assertEquals(0, $response->json('data.total_unread'));
        $this->assertEquals([], $response->json('data.conversations'));
    }

    public function test_c4_messages_with_id_less_than_or_equal_to_last_read_are_not_counted(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $m1 = Message::factory()->forConversationAndSender($conversation, $userB)->create();
        $m2 = Message::factory()->forConversationAndSender($conversation, $userB)->create();
        $m3 = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        // Set last_read_message_id = m2->id for User A
        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $userA->id)
            ->update(['last_read_message_id' => $m2->id]);

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();

        // Only m3 is unread (> m2->id)
        $this->assertEquals(1, $response->json('data.total_unread'));
        $this->assertEquals(1, $response->json('data.conversations.0.unread_count'));
    }

    public function test_c5_null_last_read_message_id_counts_all_recipient_messages(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        Message::factory()->count(4)->forConversationAndSender($conversation, $userB)->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();

        $this->assertEquals(4, $response->json('data.total_unread'));
    }

    public function test_c6_multiple_conversations_have_individual_unread_counts(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();

        $convAB = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $convAC = Conversation::factory()->betweenUsers($userA->id, $userC->id)->withParticipants()->create();

        // 3 messages in AB from B
        Message::factory()->count(3)->forConversationAndSender($convAB, $userB)->create();

        // 2 messages in AC from C
        Message::factory()->count(2)->forConversationAndSender($convAC, $userC)->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();

        $this->assertEquals(5, $response->json('data.total_unread'));
        $this->assertCount(2, $response->json('data.conversations'));

        $convMap = collect($response->json('data.conversations'))->keyBy('conversation_id');
        $this->assertEquals(3, $convMap[$convAB->id]['unread_count']);
        $this->assertEquals(2, $convMap[$convAC->id]['unread_count']);
    }

    public function test_c7_total_unread_equals_sum_of_all_conversation_unread_counts(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();

        $conv1 = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $conv2 = Conversation::factory()->betweenUsers($userA->id, $userC->id)->withParticipants()->create();

        Message::factory()->count(7)->forConversationAndSender($conv1, $userB)->create();
        Message::factory()->count(4)->forConversationAndSender($conv2, $userC)->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();

        $sum = collect($response->json('data.conversations'))->sum('unread_count');
        $this->assertEquals($sum, $response->json('data.total_unread'));
        $this->assertEquals(11, $response->json('data.total_unread'));
    }

    public function test_c8_does_not_count_conversations_user_does_not_participate_in(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();
        $userD = User::factory()->create();

        // Conv between C and D
        $convCD = Conversation::factory()->betweenUsers($userC->id, $userD->id)->withParticipants()->create();
        Message::factory()->count(10)->forConversationAndSender($convCD, $userD)->create();

        // User A has no conversations
        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();

        $this->assertEquals(0, $response->json('data.total_unread'));
        $this->assertEquals([], $response->json('data.conversations'));
    }

    public function test_c9_does_not_leak_other_user_data_in_unread_response(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conv = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        Message::factory()->forConversationAndSender($conv, $userB)->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('email', $content);
        $this->assertStringNotContainsString('remember_token', $content);
    }

    public function test_c10_unread_count_avoids_n_plus_one_queries(): void
    {
        $userA = User::factory()->create();

        // 3 conversations
        for ($i = 0; $i < 3; $i++) {
            $other = User::factory()->create();
            $conv = Conversation::factory()->betweenUsers($userA->id, $other->id)->withParticipants()->create();
            Message::factory()->count(2)->forConversationAndSender($conv, $other)->create();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();

        $queries3 = count(DB::getQueryLog());

        // 3 more conversations (total 6)
        for ($i = 3; $i < 6; $i++) {
            $other = User::factory()->create();
            $conv = Conversation::factory()->betweenUsers($userA->id, $other->id)->withParticipants()->create();
            Message::factory()->count(2)->forConversationAndSender($conv, $other)->create();
        }

        DB::flushQueryLog();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();

        $queries6 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Query count should remain exactly 1 query regardless of conversation count
        $this->assertEquals($queries3, $queries6, 'Unread count query must remain constant and avoid N+1.');
    }

    // =========================================================================
    // NHÓM D: Regression Tests
    // =========================================================================

    public function test_d1_conversation_index_unread_count_is_consistent(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        Message::factory()->count(4)->forConversationAndSender($conversation, $userB)->create();

        // Index unread_count
        $resIndex = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();
        $this->assertEquals(4, $resIndex->json('data.0.unread_count'));

        // Unread-count endpoint
        $resCount = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk();
        $this->assertEquals(4, $resCount->json('data.total_unread'));
        $this->assertEquals(4, $resCount->json('data.conversations.0.unread_count'));
    }

    public function test_d2_conversation_show_unread_count_is_consistent(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        Message::factory()->count(3)->forConversationAndSender($conversation, $userB)->create();

        $resShow = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}")
            ->assertOk();
        $this->assertEquals(3, $resShow->json('data.unread_count'));
    }

    public function test_d3_marking_as_read_synchronizes_index_and_detail_unread_counts(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $m1 = Message::factory()->forConversationAndSender($conversation, $userB)->create();
        $m2 = Message::factory()->forConversationAndSender($conversation, $userB)->create();

        // Mark m1 as read
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $m1->id,
            ])->assertOk();

        // Index unread_count becomes 1
        $resIndex = $this->actingAs($userA, 'sanctum')->getJson('/api/chat/conversations')->assertOk();
        $this->assertEquals(1, $resIndex->json('data.0.unread_count'));

        // Detail unread_count becomes 1
        $resShow = $this->actingAs($userA, 'sanctum')->getJson("/api/chat/conversations/{$conversation->id}")->assertOk();
        $this->assertEquals(1, $resShow->json('data.unread_count'));

        // Unread count endpoint becomes 1
        $resCount = $this->actingAs($userA, 'sanctum')->getJson('/api/chat/unread-count')->assertOk();
        $this->assertEquals(1, $resCount->json('data.total_unread'));

        // Mark m2 as read
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $m2->id,
            ])->assertOk();

        // All become 0
        $resIndex2 = $this->actingAs($userA, 'sanctum')->getJson('/api/chat/conversations')->assertOk();
        $this->assertEquals(0, $resIndex2->json('data.0.unread_count'));

        $resCount2 = $this->actingAs($userA, 'sanctum')->getJson('/api/chat/unread-count')->assertOk();
        $this->assertEquals(0, $resCount2->json('data.total_unread'));
        $this->assertEquals([], $resCount2->json('data.conversations'));
    }

    public function test_d4_sending_message_does_not_update_last_read_message_id(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Checking read status invariance',
            ])->assertCreated();

        $participantA = ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $userA->id)->first();
        $this->assertNull($participantA->last_read_message_id);
    }

    public function test_d5_community_and_library_apis_are_not_regressed(): void
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
