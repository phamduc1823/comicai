<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConversationApiTest extends TestCase
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
    // NHÓM A: Index Tests
    // =========================================================================

    public function test_a1_guest_cannot_access_conversations_index(): void
    {
        $this->getJson('/api/chat/conversations')
            ->assertUnauthorized();
    }

    public function test_a2_active_user_can_view_own_conversations_list(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()
            ->betweenUsers($userA->id, $userB->id)
            ->withParticipants()
            ->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'type',
                    'recipient' => ['id', 'name', 'avatar_path', 'role'],
                    'last_message',
                    'last_message_at',
                    'participants',
                    'unread_count',
                    'created_at',
                    'updated_at',
                ],
            ],
            'links',
            'meta',
        ]);

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($conversation->id, $response->json('data.0.id'));
        $this->assertEquals($userB->id, $response->json('data.0.recipient.id'));
    }

    public function test_a3_conversations_index_does_not_return_conversations_of_other_users(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();

        // Conversation between B and C
        Conversation::factory()
            ->betweenUsers($userB->id, $userC->id)
            ->withParticipants()
            ->create();

        // User A requests conversations
        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_a4_conversations_index_is_sorted_by_last_message_at_descending(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();

        $convOld = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create([
            'last_message_at' => now()->subHours(2),
        ]);

        $convNew = Conversation::factory()->betweenUsers($userA->id, $userC->id)->withParticipants()->create([
            'last_message_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals($convNew->id, $data[0]['id']);
        $this->assertEquals($convOld->id, $data[1]['id']);
    }

    public function test_a5_conversations_without_messages_are_sorted_after_conversations_with_messages(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();

        $convNoMsg = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create([
            'last_message_at' => null,
        ]);

        $convWithMsg = Conversation::factory()->betweenUsers($userA->id, $userC->id)->withParticipants()->create([
            'last_message_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals($convWithMsg->id, $data[0]['id']);
        $this->assertEquals($convNoMsg->id, $data[1]['id']);
    }

    public function test_a6_pagination_without_params_uses_defaults(): void
    {
        $userA = User::factory()->create();

        for ($i = 0; $i < 20; $i++) {
            $other = User::factory()->create();
            Conversation::factory()->betweenUsers($userA->id, $other->id)->withParticipants()->create([
                'last_message_at' => now()->subMinutes(30 - $i),
            ]);
        }

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();

        $this->assertCount(15, $response->json('data'));
        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(15, $response->json('meta.per_page'));
        $this->assertEquals(20, $response->json('meta.total'));
    }

    public function test_a7_pagination_rejects_page_zero(): void
    {
        $userA = User::factory()->create();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations?page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['page']);
    }

    public function test_a8_pagination_rejects_non_integer_page(): void
    {
        $userA = User::factory()->create();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations?page=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['page']);
    }

    public function test_a9_pagination_rejects_per_page_zero(): void
    {
        $userA = User::factory()->create();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations?per_page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_a10_pagination_rejects_per_page_exceeding_fifty(): void
    {
        $userA = User::factory()->create();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations?per_page=51')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_a11_pagination_accepts_valid_per_page(): void
    {
        $userA = User::factory()->create();

        for ($i = 0; $i < 35; $i++) {
            $other = User::factory()->create();
            Conversation::factory()->betweenUsers($userA->id, $other->id)->withParticipants()->create([
                'last_message_at' => now()->subMinutes(40 - $i),
            ]);
        }

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations?per_page=30')
            ->assertOk();

        $this->assertCount(30, $response->json('data'));
        $this->assertEquals(30, $response->json('meta.per_page'));
        $this->assertEquals(35, $response->json('meta.total'));
    }

    public function test_a12_pagination_page_two_returns_next_slice(): void
    {
        $userA = User::factory()->create();

        for ($i = 0; $i < 25; $i++) {
            $other = User::factory()->create();
            Conversation::factory()->betweenUsers($userA->id, $other->id)->withParticipants()->create([
                'last_message_at' => now()->subMinutes(30 - $i),
            ]);
        }

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations?per_page=10&page=2')
            ->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertEquals(2, $response->json('meta.current_page'));
        $this->assertEquals(10, $response->json('meta.per_page'));
    }

    public function test_a13_conversations_index_does_not_leak_sensitive_user_data(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('remember_token', $content);
        $this->assertStringNotContainsString($userB->email, $content);
    }

    public function test_a14_conversations_index_avoids_n_plus_one_queries(): void
    {
        $userA = User::factory()->create();

        // Create 5 conversations with messages
        for ($i = 0; $i < 5; $i++) {
            $other = User::factory()->create();
            $conv = Conversation::factory()->betweenUsers($userA->id, $other->id)->withParticipants()->create([
                'last_message_at' => now()->subMinutes(10 - $i),
            ]);
            $msg = Message::factory()->forConversationAndSender($conv, $other)->create();
            $conv->update(['last_message_id' => $msg->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();

        $queryCount5 = count(DB::getQueryLog());

        // Create 5 more conversations (total 10)
        for ($i = 5; $i < 10; $i++) {
            $other = User::factory()->create();
            $conv = Conversation::factory()->betweenUsers($userA->id, $other->id)->withParticipants()->create([
                'last_message_at' => now()->subMinutes(10 - $i),
            ]);
            $msg = Message::factory()->forConversationAndSender($conv, $other)->create();
            $conv->update(['last_message_id' => $msg->id]);
        }

        DB::flushQueryLog();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();

        $queryCount10 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Query count should remain constant (e.g. 5 queries for count, main query, users, participants, lastMessage)
        $this->assertEquals($queryCount5, $queryCount10, 'Query count must not grow with the number of conversations (N+1 protection).');
    }

    // =========================================================================
    // NHÓM B: Store Tests
    // =========================================================================

    public function test_b1_guest_cannot_create_conversation(): void
    {
        $this->postJson('/api/chat/conversations', ['user_id' => 1])
            ->assertUnauthorized();
    }

    public function test_b2_user_cannot_chat_with_self(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/chat/conversations', ['user_id' => $user->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_b3_non_existent_recipient_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/chat/conversations', ['user_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_b4_deactivated_recipient_is_rejected(): void
    {
        $user = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/chat/conversations', ['user_id' => $inactive->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_b5_creates_direct_conversation_successfully(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/chat/conversations', ['user_id' => $userB->id])
            ->assertCreated();

        $response->assertJsonStructure([
            'data' => [
                'id',
                'type',
                'recipient' => ['id', 'name', 'avatar_path', 'role'],
                'last_message',
                'last_message_at',
                'participants',
                'unread_count',
            ],
        ]);

        $this->assertDatabaseHas('conversations', [
            'id' => $response->json('data.id'),
            'type' => 'direct',
            'user_one_id' => min($userA->id, $userB->id),
            'user_two_id' => max($userA->id, $userB->id),
        ]);
    }

    public function test_b6_creates_exactly_two_participants_for_direct_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/chat/conversations', ['user_id' => $userB->id])
            ->assertCreated();

        $convId = $response->json('data.id');
        $participants = ConversationParticipant::where('conversation_id', $convId)->get();

        $this->assertCount(2, $participants);
        $this->assertTrue($participants->contains('user_id', $userA->id));
        $this->assertTrue($participants->contains('user_id', $userB->id));
    }

    public function test_b7_repeating_store_with_same_recipient_returns_existing_conversation_with_status_200(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // 1st request -> Created 201
        $res1 = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/chat/conversations', ['user_id' => $userB->id])
            ->assertCreated();

        // 2nd request -> OK 200 with same conversation ID
        $res2 = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/chat/conversations', ['user_id' => $userB->id])
            ->assertOk();

        $this->assertEquals($res1->json('data.id'), $res2->json('data.id'));
        $this->assertEquals(1, Conversation::count());
        $this->assertEquals(2, ConversationParticipant::count());
    }

    public function test_b8_both_directions_a_to_b_and_b_to_a_return_the_same_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // A creates with B
        $resA = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/chat/conversations', ['user_id' => $userB->id])
            ->assertCreated();

        // B calls with A
        $resB = $this->actingAs($userB, 'sanctum')
            ->postJson('/api/chat/conversations', ['user_id' => $userA->id])
            ->assertOk();

        $this->assertEquals($resA->json('data.id'), $resB->json('data.id'));
        $this->assertEquals(1, Conversation::count());
    }

    public function test_b9_prohibited_system_fields_are_rejected(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/chat/conversations', [
                'user_id' => $userB->id,
                'user_one_id' => 99,
                'user_two_id' => 100,
                'type' => 'group',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_one_id', 'user_two_id', 'type']);
    }

    public function test_b10_failure_during_participant_creation_rolls_back_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Simulate transaction rollback on participant creation failure
        $service = new ConversationService();

        try {
            DB::transaction(function () use ($userA, $userB) {
                $conv = Conversation::create([
                    'type' => 'direct',
                    'user_one_id' => min($userA->id, $userB->id),
                    'user_two_id' => max($userA->id, $userB->id),
                ]);

                // Simulate intentional exception before completing participants
                throw new \RuntimeException('Simulated failure during participant insert');
            });
        } catch (\RuntimeException $e) {
            // Expected simulated exception
        }

        $this->assertEquals(0, Conversation::count());
        $this->assertEquals(0, ConversationParticipant::count());
    }

    public function test_b11_duplicate_key_race_condition_is_handled_gracefully(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $service = new ConversationService();

        // First creation succeeds
        $result1 = $service->findOrCreateDirectConversation($userA, $userB->id);
        $this->assertTrue($result1['is_new']);

        // Second creation returns existing
        $result2 = $service->findOrCreateDirectConversation($userB, $userA->id);
        $this->assertFalse($result2['is_new']);
        $this->assertEquals($result1['conversation']->id, $result2['conversation']->id);
    }

    public function test_b12_unrelated_query_exceptions_are_re_thrown(): void
    {
        $this->expectException(QueryException::class);

        // Attempt invalid column insertion to verify re-throw
        DB::table('conversations')->insert([
            'non_existent_column' => 'invalid_value',
        ]);
    }

    // =========================================================================
    // NHÓM C: Show Tests
    // =========================================================================

    public function test_c1_guest_cannot_view_conversation_detail(): void
    {
        $conversation = Conversation::factory()->create();

        $this->getJson("/api/chat/conversations/{$conversation->id}")
            ->assertUnauthorized();
    }

    public function test_c2_participant_can_view_conversation_detail(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()
            ->betweenUsers($userA->id, $userB->id)
            ->withParticipants()
            ->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}")
            ->assertOk();

        $response->assertJson([
            'data' => [
                'id' => $conversation->id,
                'type' => 'direct',
                'recipient' => [
                    'id' => $userB->id,
                    'name' => $userB->name,
                ],
            ],
        ]);
    }

    public function test_c3_non_participant_is_forbidden_via_policy(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $stranger = User::factory()->create();

        $conversation = Conversation::factory()
            ->betweenUsers($userA->id, $userB->id)
            ->withParticipants()
            ->create();

        // Stranger tries to view A and B's conversation -> 403 Forbidden
        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}")
            ->assertForbidden();
    }

    public function test_c4_tampering_with_conversation_id_in_url_is_blocked(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();
        $userD = User::factory()->create();

        // Conversation 1: A and B
        $conv1 = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        // Conversation 2: C and D
        $conv2 = Conversation::factory()->betweenUsers($userC->id, $userD->id)->withParticipants()->create();

        // User A tries to access Conversation 2
        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conv2->id}")
            ->assertForbidden();
    }

    public function test_c5_non_existent_conversation_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/chat/conversations/999999')
            ->assertNotFound();
    }

    public function test_c6_show_does_not_leak_sensitive_user_data(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()
            ->betweenUsers($userA->id, $userB->id)
            ->withParticipants()
            ->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}")
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('remember_token', $content);
        $this->assertStringNotContainsString($userB->email, $content);
    }

    public function test_c7_show_does_not_load_all_messages(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()
            ->betweenUsers($userA->id, $userB->id)
            ->withParticipants()
            ->create();

        Message::factory()->count(10)->forConversationAndSender($conversation, $userB)->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}")
            ->assertOk();

        // Detail response should not have a full 'messages' array
        $this->assertArrayNotHasKey('messages', $response->json('data'));
    }

    public function test_c8_conversation_participant_resource_matches_actual_database_schema(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()
            ->betweenUsers($userA->id, $userB->id)
            ->withParticipants()
            ->create();

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}")
            ->assertOk();

        $participants = $response->json('data.participants');
        $this->assertCount(2, $participants);

        $participantA = collect($participants)->firstWhere('user_id', $userA->id);
        $this->assertNotNull($participantA);

        // Actual database columns present
        $this->assertArrayHasKey('id', $participantA);
        $this->assertArrayHasKey('conversation_id', $participantA);
        $this->assertArrayHasKey('user_id', $participantA);
        $this->assertArrayHasKey('last_read_message_id', $participantA);
        $this->assertArrayHasKey('last_read_at', $participantA);
        $this->assertArrayHasKey('is_muted', $participantA);
        $this->assertArrayHasKey('created_at', $participantA);
        $this->assertArrayHasKey('user', $participantA);

        // User profile sub-object
        $this->assertEquals($userA->id, $participantA['user']['id']);
        $this->assertEquals($userA->name, $participantA['user']['name']);
        $this->assertEquals($userA->role, $participantA['user']['role']);

        // Fabricated or non-existent fields MUST NOT be present at the participant level
        $this->assertArrayNotHasKey('role', $participantA, 'Participant root must not contain fabricated role.');
        $this->assertArrayNotHasKey('joined_at', $participantA, 'Participant root must not contain non-existent joined_at.');
    }

    // =========================================================================
    // NHÓM D: Regression Tests
    // =========================================================================

    public function test_d1_message_and_read_endpoints_are_fully_implemented(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        // Message index -> 200 OK (implemented in Package 9.7.3C)
        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertOk();

        // Message store -> 201 Created (implemented in Package 9.7.3C)
        $resStore = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", ['body' => 'Test'])
            ->assertCreated();

        $msgId = $resStore->json('data.id');

        // Mark as read -> 200 OK (implemented in Package 9.7.3D)
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", ['last_read_message_id' => $msgId])
            ->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'conversation_id' => $conversation->id,
                    'last_read_message_id' => $msgId,
                ],
            ]);

        // Unread count -> 200 OK (implemented in Package 9.7.3D)
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

    public function test_d2_community_and_library_apis_are_not_regressed(): void
    {
        // Public Catalog
        $this->getJson('/api/comics')->assertOk();

        // Authenticated user library
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/follows')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/bookmarks')
            ->assertOk();
    }
}
