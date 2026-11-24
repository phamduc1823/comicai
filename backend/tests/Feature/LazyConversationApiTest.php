<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LazyConversationApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. GET /api/chat/direct/{id} for new recipient returns 200 with data: null and recipient info,
     * and does NOT create any conversation or participant row in DB.
     */
    public function test_find_direct_for_new_recipient_returns_null_data_without_creating_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->assertEquals(0, Conversation::count());

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/direct/{$userB->id}")
            ->assertOk();

        $response->assertJson([
            'data' => null,
            'recipient' => [
                'id' => $userB->id,
                'name' => $userB->name,
                'role' => $userB->role,
            ],
        ]);

        $this->assertEquals(0, Conversation::count());
    }

    /**
     * 2. GET /api/chat/direct/{id} for existing conversation returns 200 with conversation data.
     */
    public function test_find_direct_for_existing_conversation_returns_conversation_resource(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conv = Conversation::factory()
            ->betweenUsers($userA->id, $userB->id)
            ->withParticipants()
            ->create(['last_message_at' => now()]);

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/direct/{$userB->id}")
            ->assertOk();

        $response->assertJsonPath('data.id', $conv->id);
        $response->assertJsonPath('data.recipient.id', $userB->id);
    }

    /**
     * 3. POST /api/chat/direct/{id}/messages creates conversation and message atomically.
     */
    public function test_send_direct_message_creates_conversation_and_message_atomically(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->assertEquals(0, Conversation::count());
        $this->assertEquals(0, Message::count());

        $payload = [
            'body' => 'Xin chao ban!',
            'type' => 'text',
            'client_message_id' => \Illuminate\Support\Str::uuid()->toString(),
        ];

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/direct/{$userB->id}/messages", $payload)
            ->assertStatus(201);

        $response->assertJsonStructure([
            'data' => ['id', 'conversation_id', 'body', 'type', 'client_message_id'],
            'conversation' => ['id', 'type', 'recipient'],
        ]);

        $this->assertEquals(1, Conversation::count());
        $this->assertEquals(1, Message::count());

        $conv = Conversation::first();
        $msg = Message::first();

        $this->assertEquals($conv->id, $msg->conversation_id);
        $this->assertEquals('Xin chao ban!', $msg->body);
        $this->assertEquals($msg->id, $conv->last_message_id);
        $this->assertNotNull($conv->last_message_at);
        $this->assertEquals(2, $conv->participants()->count());
    }

    /**
     * 4. Failed message creation rolls back completely and leaves NO empty conversation in DB.
     */
    public function test_failed_message_creation_rolls_back_and_leaves_no_empty_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->assertEquals(0, Conversation::count());

        // Empty body triggers validation failure
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/direct/{$userB->id}/messages", ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);

        $this->assertEquals(0, Conversation::count());
        $this->assertEquals(0, Message::count());

        // Body exceeding 2000 chars triggers validation failure
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/direct/{$userB->id}/messages", ['body' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);

        $this->assertEquals(0, Conversation::count());
        $this->assertEquals(0, Message::count());
    }

    /**
     * 5. GET /api/chat/conversations does NOT return conversations without messages.
     */
    public function test_chat_list_does_not_return_empty_conversations(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Conversation::factory()
            ->betweenUsers($userA->id, $userB->id)
            ->withParticipants()
            ->create([
                'last_message_id' => null,
                'last_message_at' => null,
            ]);

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    /**
     * 6. Conversation with messages appears in GET /api/chat/conversations.
     */
    public function test_conversation_with_messages_appears_in_chat_list(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Send a message first
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/direct/{$userB->id}/messages", ['body' => 'Hello there'])
            ->assertStatus(201);

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Hello there', $response->json('data.0.last_message.body'));
    }

    /**
     * 7. Sending a second direct message to existing conversation does NOT create a duplicate conversation.
     */
    public function test_sending_second_message_re为其_reuses_existing_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $res1 = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/direct/{$userB->id}/messages", ['body' => 'Message 1'])
            ->assertStatus(201);

        $convId1 = $res1->json('conversation.id');

        $res2 = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/direct/{$userB->id}/messages", ['body' => 'Message 2'])
            ->assertStatus(201);

        $convId2 = $res2->json('conversation.id');

        $this->assertEquals($convId1, $convId2);
        $this->assertEquals(1, Conversation::count());
        $this->assertEquals(2, Message::count());
    }

    /**
     * 8. Messaging oneself returns 422 Unprocessable Entity.
     */
    public function test_cannot_find_direct_or_send_message_to_oneself(): void
    {
        $userA = User::factory()->create();

        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/chat/direct/{$userA->id}")
            ->assertStatus(422);

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/direct/{$userA->id}/messages", ['body' => 'Hello me'])
            ->assertStatus(422);

        $this->assertEquals(0, Conversation::count());
    }

    /**
     * 9. Non-existent recipient returns 404 Not Found.
     */
    public function test_non_existent_recipient_returns_404(): void
    {
        $userA = User::factory()->create();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/direct/999999')
            ->assertStatus(404);

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/chat/direct/999999/messages', ['body' => 'Hello ghost'])
            ->assertStatus(404);

        $this->assertEquals(0, Conversation::count());
    }
}
