<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Message>
     */
    protected $model = Message::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender_id' => User::factory(),
            'client_message_id' => fake()->uuid(),
            'type' => 'text',
            'body' => fake()->sentence(),
        ];
    }

    /**
     * Create message for a specific conversation and sender.
     */
    public function forConversationAndSender(Conversation $conversation, User $sender): static
    {
        return $this->state(fn (array $attributes) => [
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
        ]);
    }
}
