<?php

namespace Tests\Feature;

use App\Models\Comic;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatShareApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }
    }

    private function createConversationBetween(User $userA, User $userB, string $status = 'accepted'): Conversation
    {
        $conv = Conversation::create([
            'type' => 'direct',
            'user_one_id' => min($userA->id, $userB->id),
            'user_two_id' => max($userA->id, $userB->id),
            'status' => $status,
            'initiator_id' => $userA->id,
        ]);
        ConversationParticipant::create(['conversation_id' => $conv->id, 'user_id' => $userA->id]);
        ConversationParticipant::create(['conversation_id' => $conv->id, 'user_id' => $userB->id]);
        return $conv;
    }

    private function createComic(array $attributes = []): Comic
    {
        $creator = User::factory()->create();
        return Comic::create(array_merge([
            'title' => 'Đại Chúa Tể',
            'slug' => 'dai-chua-te-' . uniqid(),
            'author_name' => 'Thiên Tằm Thổ Đậu',
            'user_id' => $creator->id,
            'description' => 'Mô tả truyện',
            'cover_path' => 'comics/covers/test.webp',
        ], $attributes));
    }

    public function test_get_share_recipients_only_returns_accepted_contacts(): void
    {
        $userA = User::factory()->create(['name' => 'Alice']);
        $userB = User::factory()->create(['name' => 'Bob']);
        $userC = User::factory()->create(['name' => 'Charlie']); // Pending
        $userD = User::factory()->create(['name' => 'David']); // No conversation

        $this->createConversationBetween($userA, $userB, 'accepted');
        $this->createConversationBetween($userA, $userC, 'pending');

        $res = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/chat/share/recipients');

        $res->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $userB->id)
            ->assertJsonPath('data.0.name', 'Bob');
    }

    public function test_can_share_comic_to_multiple_accepted_recipients(): void
    {
        $userA = User::factory()->create(['name' => 'Alice']);
        $userB = User::factory()->create(['name' => 'Bob']);
        $userC = User::factory()->create(['name' => 'Charlie']);

        $convB = $this->createConversationBetween($userA, $userB, 'accepted');
        $convC = $this->createConversationBetween($userA, $userC, 'accepted');

        $comic = $this->createComic(['title' => 'Đại Chúa Tể']);

        $res = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/chat/share', [
                'recipient_ids' => [$userB->id, $userC->id],
                'comic_id' => $comic->id,
                'body' => 'Truyện này đọc cuốn lắm nè!',
            ]);

        $res->assertOk()
            ->assertJsonPath('sent_count', 2);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $convB->id,
            'sender_id' => $userA->id,
            'comic_id' => $comic->id,
            'body' => 'Truyện này đọc cuốn lắm nè!',
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $convC->id,
            'sender_id' => $userA->id,
            'comic_id' => $comic->id,
            'body' => 'Truyện này đọc cuốn lắm nè!',
        ]);
    }

    public function test_can_share_community_post_to_recipient(): void
    {
        $userA = User::factory()->create(['name' => 'Alice']);
        $userB = User::factory()->create(['name' => 'Bob']);
        $convB = $this->createConversationBetween($userA, $userB, 'accepted');

        $author = User::factory()->create();
        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Review chi tiết arc 2',
        ]);

        $res = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/chat/share', [
                'recipient_ids' => [$userB->id],
                'post_id' => $post->id,
                'body' => 'Xem bài review này:',
            ]);

        $res->assertOk()
            ->assertJsonPath('sent_count', 1);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $convB->id,
            'sender_id' => $userA->id,
            'post_id' => $post->id,
            'type' => 'post',
        ]);
    }

    public function test_cannot_share_to_unaccepted_recipient(): void
    {
        $userA = User::factory()->create(['name' => 'Alice']);
        $userB = User::factory()->create(['name' => 'Bob']); // Pending
        $this->createConversationBetween($userA, $userB, 'pending');

        $comic = $this->createComic();

        $res = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/chat/share', [
                'recipient_ids' => [$userB->id],
                'comic_id' => $comic->id,
            ]);

        $res->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_ids']);
    }

    public function test_cannot_share_to_self(): void
    {
        $userA = User::factory()->create(['name' => 'Alice']);
        $comic = $this->createComic();

        $res = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/chat/share', [
                'recipient_ids' => [$userA->id],
                'comic_id' => $comic->id,
            ]);

        $res->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_ids.0']);
    }
}
