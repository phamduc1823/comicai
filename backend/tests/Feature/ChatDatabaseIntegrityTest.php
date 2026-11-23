<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChatDatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure foreign key constraints are enabled on SQLite connections
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }
    }

    // =========================================================================
    // GROUP 1: Database Constraints (1 - 11)
    // =========================================================================

    public function test_1_conversation_cannot_be_created_with_equal_user_ids(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('conversations')->insert([
            'type' => 'direct',
            'user_one_id' => $user->id,
            'user_two_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_2_conversation_enforces_user_one_less_than_user_two(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $smallerId = min($userA->id, $userB->id);
        $largerId = max($userA->id, $userB->id);

        $this->expectException(QueryException::class);

        // Attempt invalid order: user_one_id > user_two_id
        DB::table('conversations')->insert([
            'type' => 'direct',
            'user_one_id' => $largerId,
            'user_two_id' => $smallerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_3_cannot_create_duplicate_direct_conversation_for_same_user_pair(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $userOneId = min($userA->id, $userB->id);
        $userTwoId = max($userA->id, $userB->id);

        Conversation::create([
            'type' => 'direct',
            'user_one_id' => $userOneId,
            'user_two_id' => $userTwoId,
        ]);

        $this->expectException(QueryException::class);

        // Attempt duplicate insertion
        Conversation::create([
            'type' => 'direct',
            'user_one_id' => $userOneId,
            'user_two_id' => $userTwoId,
        ]);
    }

    public function test_4_direct_conversation_requires_both_users(): void
    {
        $user = User::factory()->create();

        // Attempt direct with user_two_id null
        try {
            DB::table('conversations')->insert([
                'type' => 'direct',
                'user_one_id' => $user->id,
                'user_two_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException when direct conversation lacks user_two_id');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }

        // Attempt direct with user_one_id null
        try {
            DB::table('conversations')->insert([
                'type' => 'direct',
                'user_one_id' => null,
                'user_two_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException when direct conversation lacks user_one_id');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_5_group_conversation_must_have_both_user_ids_null(): void
    {
        // Valid group conversation: both null
        $group = Conversation::create([
            'type' => 'group',
            'user_one_id' => null,
            'user_two_id' => null,
        ]);
        $this->assertDatabaseHas('conversations', ['id' => $group->id, 'type' => 'group']);

        // Invalid group conversation: with user_one_id set
        $user = User::factory()->create();
        $this->expectException(QueryException::class);

        DB::table('conversations')->insert([
            'type' => 'group',
            'user_one_id' => $user->id,
            'user_two_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_6_participant_cannot_be_duplicated_for_same_conversation_and_user(): void
    {
        $conversation = Conversation::factory()->create();
        $user = User::factory()->create();

        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        $this->expectException(QueryException::class);

        // Duplicate participant
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_7_message_must_belong_to_a_valid_conversation(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('messages')->insert([
            'conversation_id' => 999999, // non-existent
            'sender_id' => $user->id,
            'body' => 'Test message',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_8_message_sender_must_be_a_valid_user(): void
    {
        $conversation = Conversation::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('messages')->insert([
            'conversation_id' => $conversation->id,
            'sender_id' => 999999, // non-existent user
            'body' => 'Test message',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_9_client_message_id_duplicate_in_same_conversation_and_sender_is_prevented(): void
    {
        $conversation = Conversation::factory()->create();
        $user = User::factory()->create();
        $uuid = '123e4567-e89b-12d3-a456-426614174000';

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'client_message_id' => $uuid,
            'body' => 'Original message',
        ]);

        $this->expectException(QueryException::class);

        // Duplicate client_message_id for same conversation and sender
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'client_message_id' => $uuid,
            'body' => 'Duplicate attempt',
        ]);
    }

    public function test_10_different_senders_can_use_same_client_message_id(): void
    {
        $conversation = Conversation::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $uuid = '123e4567-e89b-12d3-a456-426614174000';

        $msg1 = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userA->id,
            'client_message_id' => $uuid,
            'body' => 'Message from A',
        ]);

        $msg2 = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userB->id,
            'client_message_id' => $uuid,
            'body' => 'Message from B',
        ]);

        $this->assertDatabaseHas('messages', ['id' => $msg1->id]);
        $this->assertDatabaseHas('messages', ['id' => $msg2->id]);
    }

    public function test_11_multiple_messages_with_null_client_message_id_are_allowed(): void
    {
        $conversation = Conversation::factory()->create();
        $user = User::factory()->create();

        $msg1 = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'client_message_id' => null,
            'body' => 'Message 1 with null client_message_id',
        ]);

        $msg2 = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'client_message_id' => null,
            'body' => 'Message 2 with null client_message_id',
        ]);

        $this->assertDatabaseHas('messages', ['id' => $msg1->id]);
        $this->assertDatabaseHas('messages', ['id' => $msg2->id]);
    }

    // =========================================================================
    // GROUP 2: Foreign Keys & Cascading (12 - 16)
    // =========================================================================

    public function test_12_deleting_user_cascades_and_removes_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $message = Message::factory()->forConversationAndSender($conversation, $userA)->create();

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseHas('messages', ['id' => $message->id]);

        // Delete user A
        $userA->delete();

        // Conversation and participants and messages should be cascaded
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('conversation_participants', ['conversation_id' => $conversation->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    public function test_13_deleting_conversation_cascades_participants_and_messages(): void
    {
        $conversation = Conversation::factory()->withParticipants()->create();
        $sender = $conversation->users()->first();
        $message = Message::factory()->forConversationAndSender($conversation, $sender)->create();

        $this->assertDatabaseHas('conversation_participants', ['conversation_id' => $conversation->id]);
        $this->assertDatabaseHas('messages', ['id' => $message->id]);

        $conversation->delete();

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('conversation_participants', ['conversation_id' => $conversation->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    public function test_14_deleting_message_referenced_by_last_message_id_sets_last_message_id_to_null(): void
    {
        $conversation = Conversation::factory()->withParticipants()->create();
        $sender = $conversation->users()->first();
        $message = Message::factory()->forConversationAndSender($conversation, $sender)->create();

        $conversation->update(['last_message_id' => $message->id]);
        $this->assertEquals($message->id, $conversation->fresh()->last_message_id);

        // Delete the referenced message
        $message->delete();

        // last_message_id should now be null via ON DELETE SET NULL
        $this->assertNull($conversation->fresh()->last_message_id);
    }

    public function test_15_deleting_message_referenced_by_last_read_message_id_sets_last_read_to_null(): void
    {
        $conversation = Conversation::factory()->withParticipants()->create();
        $participant = $conversation->participants()->first();
        $sender = $conversation->users()->first();
        $message = Message::factory()->forConversationAndSender($conversation, $sender)->create();

        $participant->update(['last_read_message_id' => $message->id]);
        $this->assertEquals($message->id, $participant->fresh()->last_read_message_id);

        // Delete the referenced message
        $message->delete();

        // last_read_message_id should now be null via ON DELETE SET NULL
        $this->assertNull($participant->fresh()->last_read_message_id);
    }

    public function test_16_cannot_reference_non_existent_conversation_or_message(): void
    {
        $conversation = Conversation::factory()->create();

        $this->expectException(QueryException::class);

        // Assign non-existent message ID as last_message_id
        DB::table('conversations')->where('id', $conversation->id)->update([
            'last_message_id' => 999999,
        ]);
    }

    // =========================================================================
    // GROUP 3: Eloquent Relationships (17 - 23)
    // =========================================================================

    public function test_17_conversation_relationship_participants(): void
    {
        $conversation = Conversation::factory()->withParticipants()->create();

        $this->assertCount(2, $conversation->participants);
        $this->assertInstanceOf(ConversationParticipant::class, $conversation->participants->first());
    }

    public function test_18_conversation_relationship_users(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();

        $users = $conversation->users;
        $this->assertCount(2, $users);
        $this->assertTrue($users->contains($userA));
        $this->assertTrue($users->contains($userB));

        // Check pivot access
        $firstUser = $users->first();
        $this->assertNotNull($firstUser->pivot);
        $this->assertFalse((bool) $firstUser->pivot->is_muted);
    }

    public function test_19_conversation_relationship_messages(): void
    {
        $conversation = Conversation::factory()->withParticipants()->create();
        $sender = $conversation->users->first();

        Message::factory()->count(3)->forConversationAndSender($conversation, $sender)->create();

        $this->assertCount(3, $conversation->messages);
        $this->assertInstanceOf(Message::class, $conversation->messages->first());
    }

    public function test_20_conversation_relationship_last_message(): void
    {
        $conversation = Conversation::factory()->withParticipants()->create();
        $sender = $conversation->users->first();

        $message = Message::factory()->forConversationAndSender($conversation, $sender)->create();
        $conversation->update(['last_message_id' => $message->id]);

        $this->assertNotNull($conversation->lastMessage);
        $this->assertEquals($message->id, $conversation->lastMessage->id);
    }

    public function test_21_message_relationship_sender_and_conversation(): void
    {
        $conversation = Conversation::factory()->withParticipants()->create();
        $sender = $conversation->users->first();

        $message = Message::factory()->forConversationAndSender($conversation, $sender)->create();

        $this->assertEquals($sender->id, $message->sender->id);
        $this->assertEquals($conversation->id, $message->conversation->id);
    }

    public function test_22_participant_relationship_last_read_message(): void
    {
        $conversation = Conversation::factory()->withParticipants()->create();
        $participant = $conversation->participants->first();
        $sender = $conversation->users->first();

        $message = Message::factory()->forConversationAndSender($conversation, $sender)->create();
        $participant->update(['last_read_message_id' => $message->id]);

        $this->assertNotNull($participant->lastReadMessage);
        $this->assertEquals($message->id, $participant->lastReadMessage->id);
    }

    public function test_23_user_relationship_conversations_and_sent_messages(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->withParticipants()->create();
        $message = Message::factory()->forConversationAndSender($conversation, $userA)->create();

        $this->assertTrue($userA->conversations->contains($conversation));
        $this->assertTrue($userA->sentMessages->contains($message));
        $this->assertEquals(1, $userA->conversationParticipants()->count());
    }

    // =========================================================================
    // GROUP 4: Migration Lifecycle & Circular Dependency (24 - 28)
    // =========================================================================

    public function test_24_fresh_migration_on_sqlite(): void
    {
        $this->assertTrue(Schema::hasTable('conversations'));
        $this->assertTrue(Schema::hasTable('conversation_participants'));
        $this->assertTrue(Schema::hasTable('messages'));

        $this->assertTrue(Schema::hasColumns('conversations', [
            'id', 'type', 'user_one_id', 'user_two_id', 'last_message_id', 'last_message_at', 'created_at', 'updated_at'
        ]));

        $this->assertTrue(Schema::hasColumns('conversation_participants', [
            'id', 'conversation_id', 'user_id', 'last_read_message_id', 'last_read_at', 'is_muted', 'created_at', 'updated_at'
        ]));

        $this->assertTrue(Schema::hasColumns('messages', [
            'id', 'conversation_id', 'sender_id', 'client_message_id', 'type', 'body', 'created_at', 'updated_at'
        ]));
    }

    public function test_25_migration_rollback(): void
    {
        // Rollback the 4 chat migrations specifically
        $chatMigrationPaths = [
            'database/migrations/2026_11_17_000004_add_last_message_foreign_keys.php',
            'database/migrations/2026_11_17_000003_create_messages_table.php',
            'database/migrations/2026_11_17_000002_create_conversation_participants_table.php',
            'database/migrations/2026_11_17_000001_create_conversations_table.php',
        ];
        foreach ($chatMigrationPaths as $path) {
            Artisan::call('migrate:rollback', ['--path' => $path]);
        }

        $this->assertFalse(Schema::hasTable('messages'));
        $this->assertFalse(Schema::hasTable('conversation_participants'));
        $this->assertFalse(Schema::hasTable('conversations'));
    }

    public function test_26_migration_rerun(): void
    {
        // Rollback then re-run chat migrations
        $chatMigrationPaths = [
            'database/migrations/2026_11_17_000004_add_last_message_foreign_keys.php',
            'database/migrations/2026_11_17_000003_create_messages_table.php',
            'database/migrations/2026_11_17_000002_create_conversation_participants_table.php',
            'database/migrations/2026_11_17_000001_create_conversations_table.php',
        ];
        foreach ($chatMigrationPaths as $path) {
            Artisan::call('migrate:rollback', ['--path' => $path]);
        }
        foreach (array_reverse($chatMigrationPaths) as $path) {
            Artisan::call('migrate', ['--path' => $path]);
        }

        $this->assertTrue(Schema::hasTable('conversations'));
        $this->assertTrue(Schema::hasTable('conversation_participants'));
        $this->assertTrue(Schema::hasTable('messages'));
    }

    public function test_27_migration_with_pre_existing_data_created_via_factories(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::rollBack();
        }

        try {
            // Rollback to before migration 4 specifically
            Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_11_17_000004_add_last_message_foreign_keys.php']);

            // Insert conversation and message data before migration 4 adds the FK
            $userA = User::factory()->create();
            $userB = User::factory()->create();

            $cId = DB::table('conversations')->insertGetId([
                'type' => 'direct',
                'user_one_id' => min($userA->id, $userB->id),
                'user_two_id' => max($userA->id, $userB->id),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $mId = DB::table('messages')->insertGetId([
                'conversation_id' => $cId,
                'sender_id' => $userA->id,
                'body' => 'Existing message before migration 4',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('conversations')->where('id', $cId)->update(['last_message_id' => $mId]);

            // Now run migration 4 (adding foreign key with pre-existing data)
            Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_17_000004_add_last_message_foreign_keys.php']);

            $this->assertDatabaseHas('conversations', [
                'id' => $cId,
                'last_message_id' => $mId,
            ]);

            // Test nullOnDelete on pre-existing data
            DB::table('messages')->where('id', $mId)->delete();
            $this->assertDatabaseHas('conversations', [
                'id' => $cId,
                'last_message_id' => null,
            ]);
        } finally {
            DB::table('messages')->delete();
            DB::table('conversation_participants')->delete();
            DB::table('conversations')->delete();
            if (isset($userA)) $userA->delete();
            if (isset($userB)) $userB->delete();

            if (DB::getDriverName() === 'sqlite') {
                DB::beginTransaction();
            }
        }
    }

    public function test_28_migration_order_and_circular_dependency_resolution(): void
    {
        // Verify that circular references (conversations.last_message_id -> messages.id)
        // and (messages.conversation_id -> conversations.id) operate without deadlock or orphan errors.
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::factory()->betweenUsers($userA->id, $userB->id)->create();
        $message = Message::factory()->forConversationAndSender($conversation, $userA)->create();
        $conversation->update(['last_message_id' => $message->id]);

        $this->assertEquals($message->id, $conversation->lastMessage->id);
        $this->assertEquals($conversation->id, $message->conversation->id);

        // Deleting conversation cascades message without circular dependency constraint violation
        $conversation->delete();
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }
}
