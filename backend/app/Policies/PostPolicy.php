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
     * Allowed only for creators and administrators according to project role matrix.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['creator', 'admin'], true);
    }

    /**
     * Determine whether the user can update the post.
     * Strictly author-only. Even administrators cannot edit another user's post content.
     */
    public function update(User $user, Post $post): bool
    {
        return (int) $user->id === (int) $post->user_id;
    }

    /**
     * Determine whether the user can delete the post.
     * Author can delete their own post; Admins can delete any post for moderation.
     */
    public function delete(User $user, Post $post): bool
    {
        return (int) $user->id === (int) $post->user_id || $user->role === 'admin';
    }

    /**
     * Determine whether the user can like/unlike the post.
     * Any authenticated active user (reader, creator, admin) can like.
     */
    public function like(User $user, Post $post): bool
    {
        return true;
    }
}
