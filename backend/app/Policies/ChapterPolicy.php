<?php

namespace App\Policies;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\User;

class ChapterPolicy
{
    /**
     * Helper to resolve the parent comic of a chapter safely.
     */
    private function resolveComic(Chapter $chapter): ?Comic
    {
        if ($chapter->relationLoaded('comic') && $chapter->comic) {
            return $chapter->comic;
        }

        return $chapter->comic()->first();
    }

    /**
     * Determine whether the user can view the listing of chapters in management studio for a comic.
     */
    public function viewAny(User $user, Comic $comic): bool
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
     * Determine whether the user can view the specific chapter in management studio.
     */
    public function view(User $user, Chapter $chapter): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'creator') {
            $comic = $this->resolveComic($chapter);

            return $comic !== null && (int) $comic->user_id === (int) $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create a chapter for a comic.
     */
    public function create(User $user, Comic $comic): bool
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
     * Determine whether the user can update the chapter details.
     * - Admin can update any chapter.
     * - Creator can only update chapter of their comic, and cannot edit when status is 'pending'.
     */
    public function update(User $user, Chapter $chapter): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'creator') {
            $comic = $this->resolveComic($chapter);
            if (! $comic || (int) $comic->user_id !== (int) $user->id) {
                return false;
            }

            // Cannot edit chapter details while in pending review
            return $chapter->status !== 'pending';
        }

        return false;
    }

    /**
     * Determine whether the user can upload, reorder, or delete page images of the chapter.
     * - Admin can manage images anytime.
     * - Creator can only manage images if they own the comic AND chapter status is 'draft' or 'rejected'.
     * - Tamper-proofing: 'pending' and 'published' chapters CANNOT have images modified.
     */
    public function manageImages(User $user, Chapter $chapter): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'creator') {
            $comic = $this->resolveComic($chapter);
            if (! $comic || (int) $comic->user_id !== (int) $user->id) {
                return false;
            }

            // Strictly prohibited when pending review or published
            return in_array($chapter->status, ['draft', 'rejected'], true);
        }

        return false;
    }

    /**
     * Determine whether the user can submit the chapter for admin review.
     * Allowed only when chapter is in 'draft' or 'rejected' status.
     */
    public function submitReview(User $user, Chapter $chapter): bool
    {
        if ($user->role === 'admin') {
            return in_array($chapter->status, ['draft', 'rejected'], true);
        }

        if ($user->role === 'creator') {
            $comic = $this->resolveComic($chapter);
            if (! $comic || (int) $comic->user_id !== (int) $user->id) {
                return false;
            }

            return in_array($chapter->status, ['draft', 'rejected'], true);
        }

        return false;
    }

    /**
     * Determine whether the user can cancel/withdraw review request (pending -> draft).
     * Allowed only when chapter is currently 'pending'.
     */
    public function cancelReview(User $user, Chapter $chapter): bool
    {
        if ($user->role === 'admin') {
            return $chapter->status === 'pending';
        }

        if ($user->role === 'creator') {
            $comic = $this->resolveComic($chapter);
            if (! $comic || (int) $comic->user_id !== (int) $user->id) {
                return false;
            }

            return $chapter->status === 'pending';
        }

        return false;
    }

    /**
     * Determine whether the user can recall a published chapter back to draft (published -> draft).
     * Allowed only when chapter is currently 'published'.
     */
    public function recall(User $user, Chapter $chapter): bool
    {
        if ($user->role === 'admin') {
            return $chapter->status === 'published';
        }

        if ($user->role === 'creator') {
            $comic = $this->resolveComic($chapter);
            if (! $comic || (int) $comic->user_id !== (int) $user->id) {
                return false;
            }

            return $chapter->status === 'published';
        }

        return false;
    }

    /**
     * Determine whether the user can approve/publish the chapter.
     * STRICTLY Admin-only. Creator can NEVER approve or publish.
     */
    public function approve(User $user, Chapter $chapter): bool
    {
        if ($user->role !== 'admin') {
            return false;
        }

        return $chapter->status === 'pending';
    }

    /**
     * Determine whether the user can reject the chapter.
     * STRICTLY Admin-only. Creator can NEVER reject.
     */
    public function reject(User $user, Chapter $chapter): bool
    {
        if ($user->role !== 'admin') {
            return false;
        }

        return $chapter->status === 'pending';
    }

    /**
     * Determine whether the user can delete the chapter.
     * - Admin can delete any chapter.
     * - Creator can only delete their own draft or rejected chapter.
     */
    public function delete(User $user, Chapter $chapter): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'creator') {
            $comic = $this->resolveComic($chapter);
            if (! $comic || (int) $comic->user_id !== (int) $user->id) {
                return false;
            }

            return in_array($chapter->status, ['draft', 'rejected'], true);
        }

        return false;
    }
}
