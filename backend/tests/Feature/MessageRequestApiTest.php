<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageRequestApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }
    }

    public function test_first_message_creates_pending_request_for_recipient(): void
    {
        $userA = User::factory()->create(['name' => 'User A']);
        $userB = User::factory()->create(['name' => 'User B']);

        // User A sends first direct message to User B
        $res = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/direct/{$userB->id}/messages", [
                'body' => 'Xin chào bạn, mình làm quen nhé!',
            ]);
        $res->assertCreated();

        $conversation = Conversation::first();
        $this->assertNotNull($conversation);
        $this->assertEquals('pending', $conversation->status);
        $this->assertEquals($userA->id, $conversation->initiator_id);

        // User A (initiator) checks main inbox -> should see conversation
        $resMainA = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations?tab=main');
        $resMainA->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id);

        // User A checks requests tab -> should be empty (A initiated it)
        $resReqA = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/conversations?tab=requests');
        $resReqA->assertOk()
            ->assertJsonCount(0, 'data');

        // User B (recipient) checks requests count -> should be 1
        $resCountB = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/chat/conversations/requests-count');
        $resCountB->assertOk()
            ->assertJsonPath('data.count', 1);

        // User B checks main inbox -> empty
        $resMainB = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/chat/conversations?tab=main');
        $resMainB->assertOk()
            ->assertJsonCount(0, 'data');

        // User B checks requests tab -> sees conversation
        $resReqB = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/chat/conversations?tab=requests');
        $resReqB->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.is_pending_request', true);
    }

    public function test_recipient_can_manually_accept_or_reject_request(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // User A initiates conversation
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/direct/{$userB->id}/messages", [
                'body' => 'Lời mời trò chuyện',
            ]);

        $conversation = Conversation::first();

        // User B accepts
        $resAccept = $this->actingAs($userB, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/accept");
        $resAccept->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertEquals('accepted', $conversation->fresh()->status);

        // Now User B has 0 requests and sees conversation in main inbox
        $this->actingAs($userB, 'sanctum')
            ->getJson('/api/chat/conversations/requests-count')
            ->assertJsonPath('data.count', 0);

        $this->actingAs($userB, 'sanctum')
            ->getJson('/api/chat/conversations?tab=main')
            ->assertJsonCount(1, 'data');
    }

    public function test_recipient_replying_automatically_accepts_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // User A sends first message
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/direct/{$userB->id}/messages", [
                'body' => 'Chào B!',
            ]);

        $conversation = Conversation::first();
        $this->assertEquals('pending', $conversation->status);

        // User B replies to the conversation
        $this->actingAs($userB, 'sanctum')
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Chào A, mình nhận được rồi!',
            ])
            ->assertCreated();

        // Conversation status must now be 'accepted'
        $this->assertEquals('accepted', $conversation->fresh()->status);

        // User B's requests count is 0
        $this->actingAs($userB, 'sanctum')
            ->getJson('/api/chat/conversations/requests-count')
            ->assertJsonPath('data.count', 0);
    }
}
