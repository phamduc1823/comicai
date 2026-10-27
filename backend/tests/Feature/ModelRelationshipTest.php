<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Bookmark;
use App\Models\Chapter;
use App\Models\ChapterImage;
use App\Models\Comic;
use App\Models\Follow;
use App\Models\Genre;
use App\Models\ReadingHistory;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test User ↔ Comic ownership and approver relationships.
     */
    public function test_user_comic_relationships(): void
    {
        $creator = User::create([
            'name' => 'Creator User',
            'email' => 'creator@example.com',
            'password' => 'secret123',
        ]);

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);
        $admin->role = 'admin';
        $admin->save();

        $comic = Comic::create([
            'title' => 'Dũng Sĩ Hồi Sinh',
            'slug' => 'dung-si-hoi-sinh',
            'author_name' => 'Tác giả A',
            'user_id' => $creator->id,
            'cover_path' => 'comics/covers/cover1.webp',
        ]);
        $comic->approved_by = $admin->id;
        $comic->approval_status = 'approved';
        $comic->save();

        // Check Creator -> Comics & Comic -> User
        $this->assertTrue($creator->comics->contains($comic));
        $this->assertEquals($creator->id, $comic->user->id);

        // Check Admin -> Approved Comics & Comic -> Approver
        $this->assertTrue($admin->approvedComics->contains($comic));
        $this->assertEquals($admin->id, $comic->approver->id);
    }

    /**
     * Test Comic ↔ Genre and Comic ↔ Tag Many-to-Many relationships.
     */
    public function test_comic_genre_and_tag_relationships(): void
    {
        $comic = Comic::create([
            'title' => 'Kiếm Ma Xuất Thế',
            'slug' => 'kiem-ma-xuat-the',
            'author_name' => 'Tác giả B',
            'cover_path' => 'comics/covers/cover2.webp',
        ]);

        $genre = Genre::create([
            'name' => 'Hành Động',
            'slug' => 'hanh-dong',
            'description' => 'Truyện hành động hấp dẫn',
        ]);

        $tag = Tag::create([
            'name' => 'kiếm thuật',
            'slug' => 'kiem-thuat',
            'description' => 'Mô típ chiến đấu dùng kiếm',
        ]);

        $comic->genres()->attach($genre->id);
        $comic->tags()->attach($tag->id);

        $this->assertTrue($comic->genres->contains($genre));
        $this->assertTrue($genre->comics->contains($comic));

        $this->assertTrue($comic->tags->contains($tag));
        $this->assertTrue($tag->comics->contains($comic));
    }

    /**
     * Test Comic ↔ Chapter and Chapter ↔ ChapterImage relationships.
     */
    public function test_comic_chapter_and_image_relationships(): void
    {
        $comic = Comic::create([
            'title' => 'Truyện Webtoon Mẫu',
            'slug' => 'truyen-webtoon-mau',
            'author_name' => 'Tác giả C',
            'cover_path' => 'comics/covers/cover3.webp',
        ]);

        $chapter = Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => '1.50',
            'title' => 'Chương 1.5 - Ngoại truyện',
            'slug' => 'chuong-1-5',
        ]);

        $image1 = ChapterImage::create([
            'chapter_id' => $chapter->id,
            'page_number' => 1,
            'image_path' => 'chapters/1/page_001.webp',
            'width' => 800,
            'height' => 1200,
        ]);

        $image2 = ChapterImage::create([
            'chapter_id' => $chapter->id,
            'page_number' => 2,
            'image_path' => 'chapters/1/page_002.webp',
            'width' => 800,
            'height' => 1200,
        ]);

        $this->assertTrue($comic->chapters->contains($chapter));
        $this->assertEquals($comic->id, $chapter->comic->id);

        $this->assertCount(2, $chapter->images);
        $this->assertEquals(1, $chapter->images->first()->page_number);
        $this->assertEquals($chapter->id, $image1->chapter->id);

        // Verify ChapterImage has created_at but null updated_at
        $this->assertNotNull($image1->created_at);
        $this->assertNull($image1->updated_at);
    }

    /**
     * Test User ↔ Follow, Bookmark, ReadingHistory and Banner relationships.
     */
    public function test_user_interactions_and_banner_relationships(): void
    {
        $user = User::create([
            'name' => 'Độc giả 1',
            'email' => 'reader1@example.com',
            'password' => 'secret123',
        ]);

        $comic = Comic::create([
            'title' => 'Truyện Đọc Thử',
            'slug' => 'truyen-doc-thu',
            'author_name' => 'Tác giả D',
            'cover_path' => 'comics/covers/cover4.webp',
        ]);

        $chapter = Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1',
            'slug' => 'chuong-1',
        ]);

        // Follow
        $follow = Follow::create(['user_id' => $user->id, 'comic_id' => $comic->id]);
        $this->assertTrue($user->follows->contains($follow));
        $this->assertTrue($comic->follows->contains($follow));
        $this->assertNotNull($follow->created_at);
        $this->assertNull($follow->updated_at);

        // Bookmark
        $bookmark = Bookmark::create(['user_id' => $user->id, 'comic_id' => $comic->id]);
        $this->assertTrue($user->bookmarks->contains($bookmark));
        $this->assertTrue($comic->bookmarks->contains($bookmark));
        $this->assertNotNull($bookmark->created_at);
        $this->assertNull($bookmark->updated_at);

        // ReadingHistory
        $reading = ReadingHistory::create([
            'user_id' => $user->id,
            'comic_id' => $comic->id,
            'chapter_id' => $chapter->id,
        ]);
        $this->assertTrue($user->readingHistories->contains($reading));
        $this->assertTrue($comic->readingHistories->contains($reading));
        $this->assertEquals($chapter->id, $reading->chapter->id);
        $this->assertNull($reading->created_at);
        $this->assertNotNull($reading->updated_at);

        // Banner
        $banner = Banner::create([
            'comic_id' => $comic->id,
            'title_override' => 'Banner nổi bật',
            'desktop_banner_path' => 'banners/desktop/banner1.webp',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $this->assertTrue($comic->banners->contains($banner));
        $this->assertEquals($comic->id, $banner->comic->id);
    }

    /**
     * Test ReadingHistory verifiedChapter integrity check for a single record.
     */
    public function test_reading_history_verified_chapter_single_record(): void
    {
        $user = User::create([
            'name' => 'Độc giả 2',
            'email' => 'reader2@example.com',
            'password' => 'secret123',
        ]);

        $comicA = Comic::create([
            'title' => 'Truyện A',
            'slug' => 'truyen-a',
            'author_name' => 'Tác giả A',
            'cover_path' => 'comics/covers/a.webp',
        ]);

        $comicB = Comic::create([
            'title' => 'Truyện B',
            'slug' => 'truyen-b',
            'author_name' => 'Tác giả B',
            'cover_path' => 'comics/covers/b.webp',
        ]);

        $chapterA = Chapter::create([
            'comic_id' => $comicA->id,
            'chapter_number' => '1.00',
            'slug' => 'truyen-a-chuong-1',
        ]);

        $chapterB = Chapter::create([
            'comic_id' => $comicB->id,
            'chapter_number' => '1.00',
            'slug' => 'truyen-b-chuong-1',
        ]);

        // Legitimate entry: Reading comic A at chapter A
        $readingValid = ReadingHistory::create([
            'user_id' => $user->id,
            'comic_id' => $comicA->id,
            'chapter_id' => $chapterA->id,
        ]);

        // verifiedChapter() must successfully match
        $this->assertNotNull($readingValid->verifiedChapter());
        $this->assertNotNull($readingValid->verified_chapter);
        $this->assertEquals($chapterA->id, $readingValid->verifiedChapter()->id);

        // Mismatched entry: Comic A but Chapter B (Chapter belongs to Comic B!)
        $readingMismatched = new ReadingHistory([
            'user_id' => $user->id,
            'comic_id' => $comicA->id,
            'chapter_id' => $chapterB->id,
        ]);

        // verifiedChapter() must return null due to comic_id mismatch
        $this->assertNull($readingMismatched->verifiedChapter());
        $this->assertNull($readingMismatched->verified_chapter);
    }

    /**
     * Test ReadingHistory verifiedChapter with eager loading across multiple records.
     * Demonstrates that each reading history only resolves chapters matching the exact comic_id,
     * without generating N+1 queries.
     */
    public function test_reading_history_verified_chapter_eager_loading_multiple_records(): void
    {
        $user1 = User::create(['name' => 'U1', 'email' => 'u1@example.com', 'password' => 'secret']);
        $user2 = User::create(['name' => 'U2', 'email' => 'u2@example.com', 'password' => 'secret']);

        $comic1 = Comic::create(['title' => 'C1', 'slug' => 'c1', 'author_name' => 'A1', 'cover_path' => 'p1']);
        $comic2 = Comic::create(['title' => 'C2', 'slug' => 'c2', 'author_name' => 'A2', 'cover_path' => 'p2']);

        $chapter1 = Chapter::create(['comic_id' => $comic1->id, 'chapter_number' => '1.00', 'slug' => 'c1-ch1']);
        $chapter2 = Chapter::create(['comic_id' => $comic2->id, 'chapter_number' => '2.00', 'slug' => 'c2-ch2']);

        // Legitimate histories:
        $h1 = ReadingHistory::create(['user_id' => $user1->id, 'comic_id' => $comic1->id, 'chapter_id' => $chapter1->id]);
        $h2 = ReadingHistory::create(['user_id' => $user2->id, 'comic_id' => $comic2->id, 'chapter_id' => $chapter2->id]);

        // Eager load all reading histories with their chapter
        DB::enableQueryLog();
        $histories = ReadingHistory::with('chapter')->whereIn('id', [$h1->id, $h2->id])->get();

        // 1 query for histories, 1 query for chapters (no N+1 queries)
        $this->assertCount(2, DB::getQueryLog());
        DB::disableQueryLog();

        $loaded1 = $histories->firstWhere('id', $h1->id);
        $loaded2 = $histories->firstWhere('id', $h2->id);

        $this->assertNotNull($loaded1->verifiedChapter());
        $this->assertEquals($chapter1->id, $loaded1->verifiedChapter()->id);

        $this->assertNotNull($loaded2->verifiedChapter());
        $this->assertEquals($chapter2->id, $loaded2->verifiedChapter()->id);

        // Mismatched in-memory object (simulating corrupt record where chapter belongs to another comic)
        $mismatched = new ReadingHistory([
            'user_id' => $user1->id,
            'comic_id' => $comic1->id,
            'chapter_id' => $chapter2->id, // Chapter 2 belongs to Comic 2!
        ]);
        $mismatched->setRelation('chapter', $chapter2);

        // verifiedChapter must return null because chapter2->comic_id !== comic1->id
        $this->assertNull($mismatched->verifiedChapter());
        $this->assertNull($mismatched->verified_chapter);
    }

    /**
     * Test Mass Assignment protection on User, Comic, and Chapter models.
     */
    public function test_mass_assignment_protection_across_models(): void
    {
        // 1. User: 'role' and 'is_active' must be ignored during mass assignment
        $user = User::create([
            'name' => 'Regular Reader',
            'email' => 'regular@example.com',
            'password' => 'plaintext',
            'role' => 'admin',       // must be ignored
            'is_active' => false,     // must be ignored
        ]);
        $this->assertEquals('reader', $user->role);
        $this->assertTrue($user->is_active);

        // 2. Comic: approval and counter fields must be ignored during mass assignment
        $comic = Comic::create([
            'title' => 'Truyện Tự Do',
            'slug' => 'truyen-tu-do',
            'author_name' => 'Tác giả F',
            'cover_path' => 'comics/covers/f.webp',
            'approval_status' => 'approved', // must be ignored
            'approved_by' => 999,           // must be ignored
            'approved_at' => now(),          // must be ignored
            'rejection_reason' => 'Bad',    // must be ignored
            'views_count' => 99999,          // must be ignored
            'follows_count' => 8888,         // must be ignored
            'is_featured' => true,           // must be ignored
        ]);
        $this->assertEquals('draft', $comic->approval_status);
        $this->assertNull($comic->approved_by);
        $this->assertNull($comic->approved_at);
        $this->assertNull($comic->rejection_reason);
        $this->assertEquals(0, $comic->views_count);
        $this->assertEquals(0, $comic->follows_count);
        $this->assertFalse($comic->is_featured);

        // 3. Chapter: 'views_count' must be ignored during mass assignment
        $chapter = Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => '1.00',
            'slug' => 'ch-1',
            'views_count' => 5000,           // must be ignored
        ]);
        $this->assertEquals(0, $chapter->views_count);
    }

    /**
     * Test Chapter chapter_number preserves exact decimal(6,2) format without precision loss.
     */
    public function test_decimal_chapter_number_exact_precision(): void
    {
        $comic = Comic::create([
            'title' => 'Truyện Số Thập Phân',
            'slug' => 'truyen-so-thap-phan',
            'author_name' => 'Tác giả G',
            'cover_path' => 'comics/covers/g.webp',
        ]);

        $testNumbers = ['1.00', '2.50', '10.25', '100.05', '999.99'];

        foreach ($testNumbers as $index => $num) {
            $chapter = Chapter::create([
                'comic_id' => $comic->id,
                'chapter_number' => $num,
                'slug' => 'ch-num-' . $index,
            ]);

            $chapter->refresh();

            // Must preserve exactly 2 decimal places and not truncate trailing zeroes
            $this->assertSame($num, $chapter->chapter_number);
            $this->assertIsString($chapter->chapter_number);
        }
    }

    /**
     * Test Casts for JSON and boolean attributes.
     */
    public function test_model_casts(): void
    {
        $comic = Comic::create([
            'title' => 'Truyện Casts',
            'slug' => 'truyen-casts',
            'author_name' => 'Tác giả E',
            'cover_path' => 'comics/covers/casts.webp',
            'pending_changes' => ['title' => 'Tên mới đề xuất', 'age_rating' => '16+'],
            'embedding' => [0.1234, 0.5678, -0.999],
        ]);
        $comic->is_featured = 1;
        $comic->save();

        $comic->refresh();
        $this->assertIsArray($comic->pending_changes);
        $this->assertEquals('Tên mới đề xuất', $comic->pending_changes['title']);
        $this->assertIsArray($comic->embedding);
        $this->assertEquals(0.1234, $comic->embedding[0]);
        $this->assertIsBool($comic->is_featured);
        $this->assertTrue($comic->is_featured);
    }

    /**
     * Test User serialization hides sensitive fields (password, remember_token).
     */
    public function test_user_serialization_safety(): void
    {
        $user = User::create([
            'name' => 'Private User',
            'email' => 'private@example.com',
            'password' => 'plaintext',
        ]);

        $serialized = $user->toArray();
        $this->assertArrayNotHasKey('password', $serialized);
        $this->assertArrayNotHasKey('remember_token', $serialized);
    }

    /**
     * Test database unique constraints enforcement.
     */
    public function test_unique_constraints_enforced(): void
    {
        User::create(['name' => 'U1', 'email' => 'duplicate@example.com', 'password' => 'secret']);
        $this->expectException(QueryException::class);
        User::create(['name' => 'U2', 'email' => 'duplicate@example.com', 'password' => 'secret']);
    }
}
