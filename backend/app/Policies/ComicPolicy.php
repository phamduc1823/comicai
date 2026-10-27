<?php

namespace App\Policies;

use App\Models\Comic;
use App\Models\User;

class ComicPolicy
{
    /**
     * Determine whether the user can view any comics in management studio.
     * Note: This checks authority to enter management studio; the controller must still
     * filter records by owner for creators (e.g. comics()->where('user_id', $user->id)).
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['creator', 'admin'], true);
    }

    /**
     * Determine whether the user can view the specific comic in management studio.
     */
    public function view(User $user, Comic $comic): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'creator') {
            return (int) $comic->user_id === (int) $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create comics.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['creator', 'admin'], true);
    }

    /**
     * Determine whether the user can update the comic.
     * - Admin can update any comic.
     * - Creator can only update their own comic, and cannot edit when status is 'pending' review.
     */
    public function update(User $user, Comic $comic): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'creator') {
            if ((int) $comic->user_id !== (int) $user->id) {
                return false;
            }

            // Cannot directly edit while the comic is in pending review
            return $comic->approval_status !== 'pending';
        }

        return false;
    }

    /**
     * Determine whether the user can submit the comic for admin review.
     * Allowed only when comic is in 'draft' or 'rejected' status.
     */
    public function submitReview(User $user, Comic $comic): bool
    {
        if ($user->role === 'admin') {
            return in_array($comic->approval_status, ['draft', 'rejected'], true);
        }

        if ($user->role === 'creator') {
            if ((int) $comic->user_id !== (int) $user->id) {
                return false;
            }

            return in_array($comic->approval_status, ['draft', 'rejected'], true);
        }

        return false;
    }

    /**
     * Determine whether the user can cancel/withdraw review request (pending -> draft).
     * Allowed only when comic is currently 'pending'.
     */
    public function cancelReview(User $user, Comic $comic): bool
    {
        if ($user->role === 'admin') {
            return $comic->approval_status === 'pending';
        }

        if ($user->role === 'creator') {
            if ((int) $comic->user_id !== (int) $user->id) {
                return false;
            }

            return $comic->approval_status === 'pending';
        }

        return false;
    }

    /**
     * Determine whether the user can approve the comic or its pending changes.
     * STRICTLY Admin-only. Creator can NEVER approve.
     */
    public function approve(User $user, Comic $comic): bool
    {
        if ($user->role !== 'admin') {
            return false;
        }

        return $comic->approval_status === 'pending' || $comic->pending_changes !== null;
    }

    /**
     * Determine whether the user can reject the comic or its pending changes.
     * STRICTLY Admin-only. Creator can NEVER reject.
     */
    public function reject(User $user, Comic $comic): bool
    {
        if ($user->role !== 'admin') {
            return false;
        }

        return $comic->approval_status === 'pending' || $comic->pending_changes !== null;
    }

    /**
     * Determine whether the user can delete the comic.
     * - Admin can delete any comic.
     * - Creator can only delete their own draft or rejected comic (cannot delete approved or pending).
     */
    public function delete(User $user, Comic $comic): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'creator') {
            if ((int) $comic->user_id !== (int) $user->id) {
                return false;
            }

            return in_array($comic->approval_status, ['draft', 'rejected'], true);
        }

        return false;
    }
}
