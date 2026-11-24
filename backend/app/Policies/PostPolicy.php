<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    /**
     * Determine whether the user can view any posts.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the specific post.
     */
    public function view(?User $user, Post $post): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create community posts.
     * Allowed for all authenticated active users (reader, creator, admin) according to CAI-COMM-01.
     * Inactive / banned accounts are strictly forbidden.
     */
    public function create(User $user): bool
    {
        return (bool) $user->is_active && in_array($user->role, ['reader', 'creator', 'admin'], true);
    }

    /**
     * Determine whether the user can update the post.
     * Strictly author-only and active. Even administrators cannot edit another user's post content.
     */
    public function update(User $user, Post $post): bool
    {
        return (bool) $user->is_active && (int) $user->id === (int) $post->user_id;
    }

    /**
     * Determine whether the user can delete the post.
     * Active author can delete their own post; Active Admins can delete any post for moderation.
     */
    public function delete(User $user, Post $post): bool
    {
        return (bool) $user->is_active && ((int) $user->id === (int) $post->user_id || $user->role === 'admin');
    }

    /**
     * Determine whether the user can like/unlike the post.
     * Any authenticated active user (reader, creator, admin) can like.
     */
    public function like(User $user, Post $post): bool
    {
        return (bool) $user->is_active;
    }
}
