<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PolicyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $creatorA;
    private User $creatorB;
    private User $admin;
    private User $reader;

    private Comic $comicA;
    private Comic $comicB;

    private Chapter $chapterA;
    private Chapter $chapterB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creatorA = User::create([
            'name' => 'Creator A',
            'email' => 'creatorA@example.com',
            'password' => 'Password123!',
        ]);
        $this->creatorA->role = 'creator';
        $this->creatorA->save();

        $this->creatorB = User::create([
            'name' => 'Creator B',
            'email' => 'creatorB@example.com',
            'password' => 'Password123!',
        ]);
        $this->creatorB->role = 'creator';
        $this->creatorB->save();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'Password123!',
        ]);
        $this->admin->role = 'admin';
        $this->admin->save();

        $this->reader = User::create([
            'name' => 'Reader User',
            'email' => 'reader@example.com',
            'password' => 'Password123!',
        ]);
        // Default role is 'reader'

        // Comic A owned by Creator A
        $this->comicA = Comic::create([
            'title' => 'Comic of Creator A',
            'slug' => 'comic-a',
            'author_name' => 'Creator A',
            'user_id' => $this->creatorA->id,
            'cover_path' => 'comics/covers/comic_a.webp',
            'status' => 'ongoing',
            'approval_status' => 'draft',
        ]);

        // Comic B owned by Creator B
        $this->comicB = Comic::create([
            'title' => 'Comic of Creator B',
            'slug' => 'comic-b',
            'author_name' => 'Creator B',
            'user_id' => $this->creatorB->id,
            'cover_path' => 'comics/covers/comic_b.webp',
            'status' => 'ongoing',
            'approval_status' => 'draft',
        ]);

        // Chapter of Comic A
        $this->chapterA = Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => 1,
            'title' => 'Chapter 1 of Comic A',
            'slug' => 'chapter-1',
            'status' => 'draft',
        ]);

        // Chapter of Comic B
        $this->chapterB = Chapter::create([
            'comic_id' => $this->comicB->id,
            'chapter_number' => 1,
            'title' => 'Chapter 1 of Comic B',
            'slug' => 'chapter-1',
            'status' => 'draft',
        ]);
    }

    /**
     * 1. Creator A can manage their own comic (view, update, submitReview, delete).
     */
    public function test_creator_can_manage_own_comic(): void
    {
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('viewAny', Comic::class));
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('view', $this->comicA));
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('create', Comic::class));
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('update', $this->comicA));
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('submitReview', $this->comicA));
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('delete', $this->comicA));
    }

    /**
     * 2. Creator A is denied from managing Creator B's comic.
     */
    public function test_creator_cannot_manage_other_creators_comic(): void
    {
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('view', $this->comicB));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('update', $this->comicB));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('submitReview', $this->comicB));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('cancelReview', $this->comicB));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('delete', $this->comicB));
    }

    /**
     * 3. Creator A is denied from managing chapters belonging to Creator B's comic.
     */
    public function test_creator_cannot_manage_chapters_of_other_creators_comic(): void
    {
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('viewAny', [Chapter::class, $this->comicB]));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('view', $this->chapterB));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('create', [Chapter::class, $this->comicB]));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('update', $this->chapterB));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('manageImages', $this->chapterB));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('submitReview', $this->chapterB));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('cancelReview', $this->chapterB));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('recall', $this->chapterB));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('delete', $this->chapterB));
    }

    /**
     * 4. Admin has full management and moderation authority over comics and chapters.
     */
    public function test_admin_can_perform_administrative_abilities(): void
    {
        // Comic management
        $this->assertTrue(Gate::forUser($this->admin)->allows('viewAny', Comic::class));
        $this->assertTrue(Gate::forUser($this->admin)->allows('view', $this->comicA));
        $this->assertTrue(Gate::forUser($this->admin)->allows('view', $this->comicB));
        $this->assertTrue(Gate::forUser($this->admin)->allows('create', Comic::class));
        $this->assertTrue(Gate::forUser($this->admin)->allows('update', $this->comicA));
        $this->assertTrue(Gate::forUser($this->admin)->allows('delete', $this->comicA));

        // Chapter management
        $this->assertTrue(Gate::forUser($this->admin)->allows('viewAny', [Chapter::class, $this->comicA]));
        $this->assertTrue(Gate::forUser($this->admin)->allows('view', $this->chapterA));
        $this->assertTrue(Gate::forUser($this->admin)->allows('create', [Chapter::class, $this->comicA]));
        $this->assertTrue(Gate::forUser($this->admin)->allows('update', $this->chapterA));
        $this->assertTrue(Gate::forUser($this->admin)->allows('manageImages', $this->chapterA));
        $this->assertTrue(Gate::forUser($this->admin)->allows('delete', $this->chapterA));

        // Admin approval when pending
        $this->comicA->approval_status = 'pending';
        $this->comicA->save();
        $this->assertTrue(Gate::forUser($this->admin)->allows('approve', $this->comicA));
        $this->assertTrue(Gate::forUser($this->admin)->allows('reject', $this->comicA));

        $this->chapterA->status = 'pending';
        $this->chapterA->save();
        $this->assertTrue(Gate::forUser($this->admin)->allows('approve', $this->chapterA));
        $this->assertTrue(Gate::forUser($this->admin)->allows('reject', $this->chapterA));
    }

    /**
     * 5. Reader role has NO management or moderation abilities on comics or chapters.
     */
    public function test_reader_has_no_management_abilities(): void
    {
        // Comics
        $this->assertFalse(Gate::forUser($this->reader)->allows('viewAny', Comic::class));
        $this->assertFalse(Gate::forUser($this->reader)->allows('view', $this->comicA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('create', Comic::class));
        $this->assertFalse(Gate::forUser($this->reader)->allows('update', $this->comicA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('submitReview', $this->comicA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('cancelReview', $this->comicA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('approve', $this->comicA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('reject', $this->comicA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('delete', $this->comicA));

        // Chapters
        $this->assertFalse(Gate::forUser($this->reader)->allows('viewAny', [Chapter::class, $this->comicA]));
        $this->assertFalse(Gate::forUser($this->reader)->allows('view', $this->chapterA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('create', [Chapter::class, $this->comicA]));
        $this->assertFalse(Gate::forUser($this->reader)->allows('update', $this->chapterA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('manageImages', $this->chapterA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('submitReview', $this->chapterA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('cancelReview', $this->chapterA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('recall', $this->chapterA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('approve', $this->chapterA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('reject', $this->chapterA));
        $this->assertFalse(Gate::forUser($this->reader)->allows('delete', $this->chapterA));
    }

    /**
     * 6. Creator can NEVER self-approve or reject their own comic or chapter.
     */
    public function test_creator_cannot_self_approve_or_reject_content(): void
    {
        $this->comicA->approval_status = 'pending';
        $this->comicA->save();
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('approve', $this->comicA));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('reject', $this->comicA));

        $this->chapterA->status = 'pending';
        $this->chapterA->save();
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('approve', $this->chapterA));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('reject', $this->chapterA));
    }

    /**
     * 7. Anti-tampering: Creator CANNOT modify images when chapter is 'pending' or 'published'.
     * Image modification is strictly restricted to 'draft' or 'rejected'.
     */
    public function test_creator_cannot_modify_images_when_chapter_is_pending_or_published(): void
    {
        // 1. In 'draft': Creator CAN modify images
        $this->chapterA->status = 'draft';
        $this->chapterA->save();
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('manageImages', $this->chapterA));

        // 2. In 'pending': Creator CANNOT modify images (tamper protection)
        $this->chapterA->status = 'pending';
        $this->chapterA->save();
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('manageImages', $this->chapterA));

        // 3. In 'published': Creator CANNOT modify images without recalling to draft first
        $this->chapterA->status = 'published';
        $this->chapterA->save();
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('manageImages', $this->chapterA));

        // 4. In 'rejected': Creator CAN modify images to fix issues
        $this->chapterA->status = 'rejected';
        $this->chapterA->save();
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('manageImages', $this->chapterA));
    }

    /**
     * 8. Creator cannot update comic details while comic is currently under 'pending' review.
     */
    public function test_creator_cannot_update_comic_while_pending_review(): void
    {
        // In draft: can update
        $this->comicA->approval_status = 'draft';
        $this->comicA->save();
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('update', $this->comicA));

        // In pending: cannot update
        $this->comicA->approval_status = 'pending';
        $this->comicA->save();
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('update', $this->comicA));

        // In rejected: can update
        $this->comicA->approval_status = 'rejected';
        $this->comicA->save();
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('update', $this->comicA));
    }

    /**
     * 9. Review request state transitions:
     * - submitReview: only when draft or rejected
     * - cancelReview: only when pending
     * - recall: only when published
     */
    public function test_review_and_recall_transitions(): void
    {
        // Comic submit / cancel
        $this->comicA->approval_status = 'draft';
        $this->comicA->save();
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('submitReview', $this->comicA));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('cancelReview', $this->comicA));

        $this->comicA->approval_status = 'pending';
        $this->comicA->save();
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('submitReview', $this->comicA));
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('cancelReview', $this->comicA));

        // Chapter recall
        $this->chapterA->status = 'published';
        $this->chapterA->save();
        $this->assertTrue(Gate::forUser($this->creatorA)->allows('recall', $this->chapterA));

        $this->chapterA->status = 'draft';
        $this->chapterA->save();
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('recall', $this->chapterA));
    }

    /**
     * 10. Orphaned chapter or missing relation does not crash and safely denies authorization.
     */
    public function test_chapter_with_missing_comic_safely_denies(): void
    {
        $orphanedChapter = new Chapter();
        $orphanedChapter->id = 9999;
        $orphanedChapter->comic_id = 99999; // Nonexistent comic
        $orphanedChapter->status = 'draft';

        $this->assertFalse(Gate::forUser($this->creatorA)->allows('view', $orphanedChapter));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('update', $orphanedChapter));
        $this->assertFalse(Gate::forUser($this->creatorA)->allows('manageImages', $orphanedChapter));
    }
}
