<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Chapter;
use App\Models\ChapterImage;
use App\Models\Comic;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that DatabaseSeeder seeds all expected records and relationships.
     */
    public function test_database_seeder_creates_all_expected_records_and_relations(): void
    {
        $this->seed(DatabaseSeeder::class);

        // 1. Verify Users
        $this->assertSame(3, User::count(), 'Expected exactly 3 demo users.');

        $admin = User::where('email', 'admin@bluetoon.local')->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_active);

        $demoPassword = env('SEEDER_DEMO_PASSWORD', 'DemoPass123!@#');
        $this->assertTrue(Hash::check($demoPassword, $admin->password), 'Admin password should match demo password.');

        $creator = User::where('email', 'creator@bluetoon.local')->first();
        $this->assertNotNull($creator);
        $this->assertSame('creator', $creator->role);
        $this->assertTrue($creator->is_active);

        $reader = User::where('email', 'reader@bluetoon.local')->first();
        $this->assertNotNull($reader);
        $this->assertSame('reader', $reader->role);
        $this->assertTrue($reader->is_active);

        // 2. Verify Genres
        $this->assertSame(8, Genre::count(), 'Expected exactly 8 genres.');
        $expectedGenreSlugs = [
            'action', 'romance', 'fantasy', 'comedy',
            'slice-of-life', 'drama', 'mystery', 'sci-fi',
        ];
        foreach ($expectedGenreSlugs as $slug) {
            $this->assertTrue(Genre::where('slug', $slug)->exists(), "Genre {$slug} should exist.");
        }

        // 3. Verify Tags
        $this->assertSame(12, Tag::count(), 'Expected exactly 12 tags.');
        $expectedTagSlugs = [
            'kiem-thuat', 'ma-phap', 'hoc-duong', 'xuyen-khong',
            'he-thong', 'do-thi', 'trong-sinh', 'quai-vat',
            'tinh-ban', 'bang-dang', 'sinh-ton', 'co-trang',
        ];
        foreach ($expectedTagSlugs as $slug) {
            $this->assertTrue(Tag::where('slug', $slug)->exists(), "Tag {$slug} should exist.");
        }

        // 4. Verify Comics
        $this->assertSame(3, Comic::count(), 'Expected exactly 3 demo comics.');
        $comics = Comic::with(['user', 'approver', 'genres', 'tags', 'chapters', 'banners'])->get();

        foreach ($comics as $comic) {
            $this->assertSame('approved', $comic->approval_status, "Comic {$comic->slug} should be approved.");
            $this->assertNotNull($comic->approved_at, "Comic {$comic->slug} should have approved_at timestamp.");
            $this->assertNotNull($comic->user, "Comic {$comic->slug} must belong to a creator.");
            $this->assertSame('creator', $comic->user->role);
            $this->assertSame($creator->id, $comic->user_id);

            $this->assertNotNull($comic->approver, "Comic {$comic->slug} must have an admin approver.");
            $this->assertSame('admin', $comic->approver->role);
            $this->assertSame($admin->id, $comic->approved_by);

            // Verify Genres pivot
            $this->assertGreaterThan(0, $comic->genres->count(), "Comic {$comic->slug} must have at least one genre.");

            // Verify Tags pivot (<= 10 tags per comic constraint)
            $this->assertGreaterThan(0, $comic->tags->count(), "Comic {$comic->slug} must have at least one tag.");
            $this->assertLessThanOrEqual(10, $comic->tags->count(), "Comic {$comic->slug} must not exceed 10 tags.");

            // Verify Chapters
            $this->assertSame(2, $comic->chapters->count(), "Comic {$comic->slug} must have exactly 2 chapters.");
        }

        // 5. Verify Chapters and Images
        $this->assertSame(6, Chapter::count(), 'Expected exactly 6 chapters across 3 comics.');
        $this->assertSame(24, ChapterImage::count(), 'Expected exactly 24 chapter images (4 pages per chapter).');

        $chapters = Chapter::with(['comic', 'images'])->get();
        foreach ($chapters as $chapter) {
            $this->assertSame('published', $chapter->status, "Chapter {$chapter->id} should be published.");
            $this->assertNotNull($chapter->comic, "Chapter {$chapter->id} must belong to a valid comic.");
            $this->assertSame(4, $chapter->images->count(), "Chapter {$chapter->id} must have exactly 4 images.");

            $pageNumbers = $chapter->images->pluck('page_number')->toArray();
            $this->assertSame([1, 2, 3, 4], $pageNumbers, "Chapter {$chapter->id} page numbers must be 1, 2, 3, 4.");

            foreach ($chapter->images as $image) {
                $this->assertSame(800, $image->width);
                $this->assertSame(1280, $image->height);
                $this->assertNotEmpty($image->image_path);
            }
        }

        // 6. Verify Banners
        $this->assertSame(2, Banner::count(), 'Expected exactly 2 featured banners.');
        $banners = Banner::with('comic')->orderBy('sort_order')->get();

        $this->assertSame(1, $banners[0]->sort_order);
        $this->assertSame(2, $banners[1]->sort_order);

        foreach ($banners as $banner) {
            $this->assertTrue($banner->is_active);
            $this->assertNotNull($banner->comic);
            $this->assertTrue($banner->comic->is_featured);
            $this->assertNotEmpty($banner->desktop_banner_path);
        }
    }

    /**
     * Test that DatabaseSeeder is fully idempotent and can run multiple times without duplicating data.
     */
    public function test_database_seeder_is_idempotent_on_repeated_runs(): void
    {
        // First run
        $this->seed(DatabaseSeeder::class);

        $initialUserCount = User::count();
        $initialGenreCount = Genre::count();
        $initialTagCount = Tag::count();
        $initialComicCount = Comic::count();
        $initialChapterCount = Chapter::count();
        $initialChapterImageCount = ChapterImage::count();
        $initialBannerCount = Banner::count();
        $initialComicGenrePivotCount = DB::table('comic_genre')->count();
        $initialComicTagPivotCount = DB::table('comic_tag')->count();

        // Second run in the same database state
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($initialUserCount, User::count(), 'User count should remain identical after rerun.');
        $this->assertSame($initialGenreCount, Genre::count(), 'Genre count should remain identical after rerun.');
        $this->assertSame($initialTagCount, Tag::count(), 'Tag count should remain identical after rerun.');
        $this->assertSame($initialComicCount, Comic::count(), 'Comic count should remain identical after rerun.');
        $this->assertSame($initialChapterCount, Chapter::count(), 'Chapter count should remain identical after rerun.');
        $this->assertSame($initialChapterImageCount, ChapterImage::count(), 'ChapterImage count should remain identical after rerun.');
        $this->assertSame($initialBannerCount, Banner::count(), 'Banner count should remain identical after rerun.');
        $this->assertSame($initialComicGenrePivotCount, DB::table('comic_genre')->count(), 'Pivot comic_genre count should remain identical.');
        $this->assertSame($initialComicTagPivotCount, DB::table('comic_tag')->count(), 'Pivot comic_tag count should remain identical.');
    }

    /**
     * Test that UserSeeder refuses to create demo accounts in production environment.
     */
    public function test_user_seeder_does_not_create_demo_users_in_production(): void
    {
        $originalEnv = app()['env'];

        try {
            app()['env'] = 'production';
            $this->assertTrue(app()->environment('production'), 'App environment should be production.');

            $this->artisan('db:seed', [
                '--class' => UserSeeder::class,
                '--force' => true,
            ]);

            $this->assertSame(0, User::count(), 'UserSeeder must not seed demo users when in production environment.');
        } finally {
            app()['env'] = $originalEnv;
        }

        $this->assertSame($originalEnv, app()['env'], 'Environment must be restored to original state.');

        // Verify that after restoring environment, UserSeeder functions normally
        $this->seed(UserSeeder::class);
        $this->assertSame(3, User::count(), 'UserSeeder should seed demo users once environment is restored to non-production.');
    }
}
