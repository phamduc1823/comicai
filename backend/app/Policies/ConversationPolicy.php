<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Determine whether the user can view any conversations.
     * Allowed for all authenticated active users.
     */
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_active;
    }

    /**
     * Determine whether the user can view the specific conversation.
     * Strictly IDOR-safe: only participants of this conversation can view it.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($conversation->relationLoaded('participants')) {
            return $conversation->participants->contains('user_id', $user->id);
        }

        return $conversation->participants()->where('user_id', $user->id)->exists();
    }

    /**
     * Determine whether the user can create a conversation.
     * Allowed for all authenticated active users.
     */
    public function create(User $user): bool
    {
        return (bool) $user->is_active;
    }

    /**
     * Determine whether the user can send a message in the conversation.
     * Strictly IDOR-safe: only active participants can send messages.
     */
    public function sendMessage(User $user, Conversation $conversation): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($conversation->relationLoaded('participants')) {
            return $conversation->participants->contains('user_id', $user->id);
        }

        return $conversation->participants()->where('user_id', $user->id)->exists();
    }

    /**
     * Determine whether the user can mark conversation as read.
     * Strictly IDOR-safe: only active participants can mark as read.
     */
    public function markAsRead(User $user, Conversation $conversation): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($conversation->relationLoaded('participants')) {
            return $conversation->participants->contains('user_id', $user->id);
        }

        return $conversation->participants()->where('user_id', $user->id)->exists();
    }
}
