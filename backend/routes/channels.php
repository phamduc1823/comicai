<?php

use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('chat.conversation.{conversationId}', function (User $user, int|string $conversationId): bool {
    if (! $user->is_active) {
        return false;
    }

    return ConversationParticipant::where('conversation_id', (int) $conversationId)
        ->where('user_id', $user->id)
        ->exists();
});

Broadcast::channel('chat.user.{userId}', function (User $user, int|string $userId): bool {
    if (! $user->is_active) {
        return false;
    }

    return (int) $user->id === (int) $userId;
});
