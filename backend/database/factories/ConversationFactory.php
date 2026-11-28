<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Conversation>
     */
    protected $model = Conversation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $userOneId = min($userA->id, $userB->id);
        $userTwoId = max($userA->id, $userB->id);

        return [
            'type' => 'direct',
            'status' => 'accepted',
            'initiator_id' => null,
            'user_one_id' => $userOneId,
            'user_two_id' => $userTwoId,
            'last_message_id' => null,
            'last_message_at' => null,
        ];
    }

    /**
     * Set specific users for the direct conversation.
     */
    public function betweenUsers(int $userAId, int $userBId): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'direct',
            'user_one_id' => min($userAId, $userBId),
            'user_two_id' => max($userAId, $userBId),
        ]);
    }

    /**
     * State for group conversation (user_one_id and user_two_id are null).
     */
    public function group(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'group',
            'user_one_id' => null,
            'user_two_id' => null,
        ]);
    }

    /**
     * Automatically populate participants after creating the conversation.
     */
    public function withParticipants(): static
    {
        return $this->afterCreating(function (Conversation $conversation) {
            if ($conversation->type === 'direct' && $conversation->user_one_id && $conversation->user_two_id) {
                ConversationParticipant::firstOrCreate([
                    'conversation_id' => $conversation->id,
                    'user_id' => $conversation->user_one_id,
                ]);
                ConversationParticipant::firstOrCreate([
                    'conversation_id' => $conversation->id,
                    'user_id' => $conversation->user_two_id,
                ]);
            }
        });
    }
}
