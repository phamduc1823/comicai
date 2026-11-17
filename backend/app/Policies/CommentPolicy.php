<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    /**
     * Determine whether the user can view any comments.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the specific comment.
     */
    public function view(?User $user, Comment $comment): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create comments or replies.
     * Allowed for all authenticated active users (reader, creator, admin).
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['reader', 'creator', 'admin'], true);
    }

    /**
     * Determine whether the user can delete the comment.
     * Author can delete their own comment; Admins can delete any comment for moderation.
     */
    public function delete(User $user, Comment $comment): bool
    {
        return (int) $user->id === (int) $comment->user_id || $user->role === 'admin';
    }

    /**
     * Determine whether the user can like/unlike the comment.
     * Any authenticated active user can like.
     */
    public function like(User $user, Comment $comment): bool
    {
        return true;
    }
}
