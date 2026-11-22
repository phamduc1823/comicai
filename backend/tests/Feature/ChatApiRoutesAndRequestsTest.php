<?php

namespace Tests\Feature;

use App\Http\Requests\IndexConversationsRequest;
use App\Http\Requests\IndexMessagesRequest;
use App\Http\Requests\MarkConversationReadRequest;
use App\Http\Requests\StoreConversationRequest;
use App\Http\Requests\StoreMessageRequest;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatApiRoutesAndRequestsTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // SECTION 1: StoreConversationRequest Tests
    // =========================================================================

    public function test_store_conversation_request_fails_when_user_id_is_missing(): void
    {
        $authUser = User::factory()->create();
        $request = new StoreConversationRequest();
        $request->setUserResolver(fn () => $authUser);

        $validator = Validator::make([], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('user_id', $validator->errors()->toArray());
    }

    public function test_store_conversation_request_fails_when_user_id_does_not_exist(): void
    {
        $authUser = User::factory()->create();
        $request = new StoreConversationRequest();
        $request->setUserResolver(fn () => $authUser);

        $validator = Validator::make(['user_id' => 999999], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('user_id', $validator->errors()->toArray());
    }

    public function test_store_conversation_request_fails_when_user_id_is_not_integer(): void
    {
        $authUser = User::factory()->create();
        $request = new StoreConversationRequest();
        $request->setUserResolver(fn () => $authUser);

        $validator = Validator::make(['user_id' => 'not-an-id'], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('user_id', $validator->errors()->toArray());
    }

    public function test_store_conversation_request_fails_when_user_id_matches_authenticated_user(): void
    {
        $authUser = User::factory()->create();
        $request = new StoreConversationRequest();
        $request->setUserResolver(fn () => $authUser);

        $validator = Validator::make(['user_id' => $authUser->id], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('user_id', $validator->errors()->toArray());
        $this->assertStringContainsString('Không thể tạo cuộc trò chuyện với chính mình', $validator->errors()->first('user_id'));
    }

    public function test_store_conversation_request_passes_with_valid_recipient(): void
    {
        $authUser = User::factory()->create();
        $recipient = User::factory()->create();
        $request = new StoreConversationRequest();
        $request->setUserResolver(fn () => $authUser);

        $validator = Validator::make(['user_id' => $recipient->id], $request->rules(), $request->messages());

        $this->assertFalse($validator->fails());
    }

    public function test_store_conversation_request_prohibits_client_assigned_system_fields(): void
    {
        $authUser = User::factory()->create();
        $recipient = User::factory()->create();
        $request = new StoreConversationRequest();
        $request->setUserResolver(fn () => $authUser);

        $validator = Validator::make([
            'user_id' => $recipient->id,
            'type' => 'group',
            'user_one_id' => 1,
            'user_two_id' => 2,
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('type', $validator->errors()->toArray());
        $this->assertArrayHasKey('user_one_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('user_two_id', $validator->errors()->toArray());
    }

    // =========================================================================
    // SECTION 1B: IndexConversationsRequest Tests
    // =========================================================================

    public function test_index_conversations_request_passes_with_empty_query(): void
    {
        $request = new IndexConversationsRequest();
        $validator = Validator::make([], $request->rules(), $request->messages());

        $this->assertFalse($validator->fails());
        $this->assertEquals(15, $request->getPerPage());
    }

    public function test_index_conversations_request_fails_when_page_is_less_than_1(): void
    {
        $request = new IndexConversationsRequest();
        $validator = Validator::make(['page' => 0], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('page', $validator->errors()->toArray());
    }

    public function test_index_conversations_request_fails_when_page_is_not_integer(): void
    {
        $request = new IndexConversationsRequest();
        $validator = Validator::make(['page' => 'abc'], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('page', $validator->errors()->toArray());
    }

    public function test_index_conversations_request_fails_when_per_page_is_less_than_1(): void
    {
        $request = new IndexConversationsRequest();
        $validator = Validator::make(['per_page' => 0], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('per_page', $validator->errors()->toArray());
    }

    public function test_index_conversations_request_fails_when_per_page_exceeds_50(): void
    {
        $request = new IndexConversationsRequest();
        $validator = Validator::make(['per_page' => 51], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('per_page', $validator->errors()->toArray());
    }

    public function test_index_conversations_request_passes_with_valid_pagination(): void
    {
        $request = new IndexConversationsRequest();
        $request->merge(['per_page' => 25]);
        $validator = Validator::make(['page' => 2, 'per_page' => 25], $request->rules(), $request->messages());

        $this->assertFalse($validator->fails());
        $this->assertEquals(25, $request->getPerPage());
    }

    // =========================================================================
    // SECTION 2: StoreMessageRequest Tests
    // =========================================================================

    public function test_store_message_request_fails_when_body_is_empty(): void
    {
        $request = new StoreMessageRequest();
        $validator = Validator::make(['body' => ''], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('body', $validator->errors()->toArray());
    }

    public function test_store_message_request_fails_when_body_exceeds_2000_characters(): void
    {
        $request = new StoreMessageRequest();
        $longBody = Str::random(2001);

        $validator = Validator::make(['body' => $longBody], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('body', $validator->errors()->toArray());
    }

    public function test_store_message_request_fails_with_invalid_type(): void
    {
        $request = new StoreMessageRequest();

        $validator = Validator::make([
            'body' => 'Hello',
            'type' => 'image', // Not allowed in MVP
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('type', $validator->errors()->toArray());
    }

    public function test_store_message_request_fails_with_invalid_uuid(): void
    {
        $request = new StoreMessageRequest();

        $validator = Validator::make([
            'body' => 'Hello',
            'client_message_id' => 'not-a-valid-uuid',
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('client_message_id', $validator->errors()->toArray());
    }

    public function test_store_message_request_passes_with_valid_payload(): void
    {
        $request = new StoreMessageRequest();

        $validator = Validator::make([
            'body' => 'Xin chào tác giả!',
            'type' => 'text',
            'client_message_id' => '550e8400-e29b-41d4-a716-446655440000',
        ], $request->rules(), $request->messages());

        $this->assertFalse($validator->fails());
    }

    public function test_store_message_request_prohibits_client_assigned_sender_and_conversation(): void
    {
        $request = new StoreMessageRequest();

        $validator = Validator::make([
            'body' => 'Hello',
            'sender_id' => 123,
            'conversation_id' => 456,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('sender_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('conversation_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('created_at', $validator->errors()->toArray());
        $this->assertArrayHasKey('updated_at', $validator->errors()->toArray());
    }

    // =========================================================================
    // SECTION 3: IndexMessagesRequest Tests
    // =========================================================================

    public function test_index_messages_request_passes_with_empty_query(): void
    {
        $request = new IndexMessagesRequest();
        $validator = Validator::make([], $request->rules(), $request->messages());

        $this->assertFalse($validator->fails());
        $this->assertEquals(25, $request->getLimit());
    }

    public function test_index_messages_request_fails_when_limit_is_less_than_1(): void
    {
        $request = new IndexMessagesRequest();
        $validator = Validator::make(['limit' => 0], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('limit', $validator->errors()->toArray());
    }

    public function test_index_messages_request_fails_when_limit_exceeds_50(): void
    {
        $request = new IndexMessagesRequest();
        $validator = Validator::make(['limit' => 51], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('limit', $validator->errors()->toArray());
    }

    public function test_index_messages_request_passes_with_valid_before_id(): void
    {
        $request = new IndexMessagesRequest();
        $validator = Validator::make([
            'limit' => 30,
            'before_id' => 150,
        ], $request->rules(), $request->messages());

        $this->assertFalse($validator->fails());
    }

    public function test_index_messages_request_passes_with_valid_after_id(): void
    {
        $request = new IndexMessagesRequest();
        $validator = Validator::make([
            'limit' => 10,
            'after_id' => 200,
        ], $request->rules(), $request->messages());

        $this->assertFalse($validator->fails());
    }

    public function test_index_messages_request_fails_when_both_before_and_after_id_are_present(): void
    {
        $request = new IndexMessagesRequest();
        $validator = Validator::make([
            'before_id' => 150,
            'after_id' => 100,
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertTrue(
            isset($validator->errors()->toArray()['before_id']) ||
            isset($validator->errors()->toArray()['after_id'])
        );
    }

    public function test_index_messages_request_fails_with_invalid_cursor_id(): void
    {
        $request = new IndexMessagesRequest();

        $validatorBefore = Validator::make(['before_id' => -5], $request->rules(), $request->messages());
        $this->assertTrue($validatorBefore->fails());

        $validatorAfter = Validator::make(['after_id' => 'abc'], $request->rules(), $request->messages());
        $this->assertTrue($validatorAfter->fails());
    }

    // =========================================================================
    // SECTION 4: MarkConversationReadRequest Tests
    // =========================================================================

    public function test_mark_conversation_read_request_fails_when_message_id_is_missing(): void
    {
        $request = new MarkConversationReadRequest();
        $validator = Validator::make([], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('last_read_message_id', $validator->errors()->toArray());
    }

    public function test_mark_conversation_read_request_fails_when_message_id_is_not_positive_integer(): void
    {
        $request = new MarkConversationReadRequest();

        $validatorZero = Validator::make(['last_read_message_id' => 0], $request->rules(), $request->messages());
        $this->assertTrue($validatorZero->fails());

        $validatorString = Validator::make(['last_read_message_id' => 'invalid'], $request->rules(), $request->messages());
        $this->assertTrue($validatorString->fails());
    }

    public function test_mark_conversation_read_request_passes_with_valid_id(): void
    {
        $request = new MarkConversationReadRequest();
        $validator = Validator::make(['last_read_message_id' => 105], $request->rules(), $request->messages());

        $this->assertFalse($validator->fails());
    }

    public function test_mark_conversation_read_request_prohibits_client_assigned_user_id(): void
    {
        $request = new MarkConversationReadRequest();
        $validator = Validator::make([
            'last_read_message_id' => 105,
            'user_id' => 42,
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('user_id', $validator->errors()->toArray());
    }

    // =========================================================================
    // SECTION 5: Route Registration & Middleware Tests
    // =========================================================================

    public function test_all_seven_chat_routes_are_registered_with_correct_names_and_uris(): void
    {
        $this->assertTrue(Route::has('chat.conversations.index'));
        $this->assertTrue(Route::has('chat.conversations.store'));
        $this->assertTrue(Route::has('chat.conversations.show'));
        $this->assertTrue(Route::has('chat.messages.index'));
        $this->assertTrue(Route::has('chat.messages.store'));
        $this->assertTrue(Route::has('chat.conversations.read'));
        $this->assertTrue(Route::has('chat.unread-count'));

        $this->assertStringEndsWith('/api/chat/conversations', route('chat.conversations.index'));
        $this->assertStringEndsWith('/api/chat/unread-count', route('chat.unread-count'));
    }

    public function test_guest_is_unauthorized_for_all_chat_routes(): void
    {
        $this->getJson('/api/chat/conversations')->assertUnauthorized();
        $this->postJson('/api/chat/conversations', ['user_id' => 1])->assertUnauthorized();
        $this->getJson('/api/chat/conversations/1')->assertUnauthorized();
        $this->getJson('/api/chat/conversations/1/messages')->assertUnauthorized();
        $this->postJson('/api/chat/conversations/1/messages', ['body' => 'hi'])->assertUnauthorized();
        $this->postJson('/api/chat/conversations/1/read', ['last_read_message_id' => 1])->assertUnauthorized();
        $this->getJson('/api/chat/unread-count')->assertUnauthorized();
    }

    public function test_deactivated_user_is_forbidden_for_chat_routes(): void
    {
        $inactiveUser = User::factory()->create(['is_active' => false]);

        $this->actingAs($inactiveUser, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertForbidden()
            ->assertJson([
                'status' => 'error',
                'message' => 'Tài khoản của bạn đã bị vô hiệu hóa.',
            ]);
    }

    public function test_authenticated_active_user_reaches_contract_endpoints(): void
    {
        $activeUser = User::factory()->create(['is_active' => true]);
        $conversation = Conversation::factory()->create();

        // Calling index reaches implemented endpoint (Package 9.7.3B)
        $this->actingAs($activeUser, 'sanctum')
            ->getJson('/api/chat/conversations')
            ->assertOk();

        // Calling unread-count reaches implemented endpoint (Package 9.7.3D)
        $this->actingAs($activeUser, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total_unread',
                    'conversations',
                ],
            ]);

        // Calling messages index with participant reaches implemented endpoint (Package 9.7.3C)
        $conversation->participants()->create(['user_id' => $activeUser->id]);
        $this->actingAs($activeUser, 'sanctum')
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertOk();
    }

    public function test_validation_is_triggered_before_reaching_controller_stubs(): void
    {
        $activeUser = User::factory()->create(['is_active' => true]);
        $conversation = Conversation::factory()->create();

        // POST conversation with missing user_id -> triggers 422 Unprocessable Content
        $this->actingAs($activeUser, 'sanctum')
            ->postJson('/api/chat/conversations', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);

        // POST message with empty body -> triggers 422 Unprocessable Content
        $this->actingAs($activeUser, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);

        // POST read with missing last_read_message_id -> triggers 422 Unprocessable Content
        $this->actingAs($activeUser, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/read", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['last_read_message_id']);
    }
}
