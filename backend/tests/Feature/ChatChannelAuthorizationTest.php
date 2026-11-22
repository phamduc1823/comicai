<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        // Configure Reverb broadcaster connection for test authorization signing
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'test-reverb-key',
                'secret' => 'test-reverb-secret',
                'app_id' => '100001',
                'options' => [
                    'host' => 'localhost',
                    'port' => 8080,
                    'scheme' => 'http',
                    'useTLS' => false,
                ],
            ],
        ]);

        require base_path('routes/channels.php');
    }

    public function test_1_guest_cannot_authorize_conversation_channel(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $response = $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-chat.conversation.'.$conversation->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertUnauthorized();
    }

    public function test_2_guest_cannot_authorize_user_channel(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-chat.user.'.$user->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertUnauthorized();
    }

    public function test_3_inactive_user_cannot_authorize_conversation_channel(): void
    {
        $inactiveUser = User::factory()->create(['is_active' => false]);
        $userB = User::factory()->create();
        $conversation = Conversation::factory()->betweenUsers($inactiveUser->id, $userB->id)->withParticipants()->create();

        $response = $this->actingAs($inactiveUser, 'sanctum')->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-chat.conversation.'.$conversation->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertForbidden();
    }

    public function test_4_inactive_user_cannot_authorize_own_user_channel(): void
    {
        $inactiveUser = User::factory()->create(['is_active' => false]);

        $response = $this->actingAs($inactiveUser, 'sanctum')->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-chat.user.'.$inactiveUser->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertForbidden();
    }

    public function test_5_valid_participant_can_authorize_conversation_channel(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $response = $this->actingAs($userA, 'sanctum')->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-chat.conversation.'.$conversation->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['auth']);

        $this->assertStringStartsWith('test-reverb-key:', $response->json('auth'));
    }

    public function test_6_other_participant_can_also_authorize_same_conversation_channel(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $response = $this->actingAs($userB, 'sanctum')->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-chat.conversation.'.$conversation->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_7_non_participant_user_is_forbidden_from_conversation_channel(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $stranger = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $response = $this->actingAs($stranger, 'sanctum')->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-chat.conversation.'.$conversation->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertForbidden();
    }

    public function test_8_non_existent_conversation_channel_is_forbidden(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-chat.conversation.999999',
            'socket_id' => '1234.5678',
        ]);

        $response->assertForbidden();
    }

    public function test_9_user_can_authorize_own_user_channel(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-chat.user.'.$user->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['auth']);

        $this->assertStringStartsWith('test-reverb-key:', $response->json('auth'));
    }

    public function test_10_user_cannot_authorize_other_user_channel(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $response = $this->actingAs($userA, 'sanctum')->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-chat.user.'.$userB->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertForbidden();
    }
}
