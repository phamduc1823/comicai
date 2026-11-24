<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Comic;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CacheSerializationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private Comic $comic;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->creator = User::create([
            'name' => 'Creator Author',
            'email' => 'creator_test@example.com',
            'password' => bcrypt('password123'),
        ]);
        $this->creator->role = 'creator';
        $this->creator->save();

        $this->comic = Comic::create([
            'title' => 'Test Comic Approved',
            'slug' => 'test-comic-approved',
            'author_name' => 'Test Author',
            'description' => 'Description of test comic',
            'cover_path' => 'covers/test.webp',
            'user_id' => $this->creator->id,
        ]);
        $this->comic->approval_status = 'approved';
        $this->comic->save();
    }

    /**
     * Test GET /api/banners returns clean data on cache miss and hit without object serialization issues.
     */
    public function test_banners_caching_returns_safe_array_payload(): void
    {
        Banner::create([
            'comic_id' => $this->comic->id,
            'desktop_banner_path' => 'banners/desktop.webp',
            'mobile_banner_path' => 'banners/mobile.webp',
            'title_override' => 'Banner Title',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        // First request: Cache Miss
        $response1 = $this->getJson('/api/banners');
        $response1->assertOk();
        $response1->assertJsonPath('data.0.title_override', 'Banner Title');

        $this->assertTrue(Cache::has(Banner::CACHE_KEY));
        $cachedValue = Cache::get(Banner::CACHE_KEY);

        // Cached value must be a pure array, NOT an Eloquent Collection or Model object
        $this->assertIsArray($cachedValue, 'Cached banners must be a pure primitive array');
        $this->assertNotEmpty($cachedValue);

        // Second request: Cache Hit
        $response2 = $this->getJson('/api/banners');
        $response2->assertOk();
        $response2->assertJsonPath('data.0.title_override', 'Banner Title');
    }

    /**
     * Test GET /api/genres returns clean data on cache miss and hit without object serialization issues.
     */
    public function test_genres_caching_returns_safe_array_payload(): void
    {
        Genre::create([
            'name' => 'Khoa Học Viễn Tưởng',
            'slug' => 'khoa-hoc-vien-tuong',
            'is_active' => true,
        ]);

        // First request
        $response1 = $this->getJson('/api/genres');
        $response1->assertOk();
        $response1->assertJsonFragment(['slug' => 'khoa-hoc-vien-tuong']);

        $cachedValue = Cache::get(Genre::CACHE_KEY);
        $this->assertIsArray($cachedValue, 'Cached genres must be a pure primitive array');

        // Second request
        $response2 = $this->getJson('/api/genres');
        $response2->assertOk();
        $response2->assertJsonFragment(['slug' => 'khoa-hoc-vien-tuong']);
    }

    /**
     * Test GET /api/tags returns clean data on cache miss and hit without object serialization issues.
     */
    public function test_tags_caching_returns_safe_array_payload(): void
    {
        Tag::create([
            'name' => 'Học Đường',
            'slug' => 'hoc-duong',
        ]);

        // First request
        $response1 = $this->getJson('/api/tags');
        $response1->assertOk();
        $response1->assertJsonFragment(['slug' => 'hoc-duong']);

        $cachedValue = Cache::get(Tag::CACHE_KEY);
        $this->assertIsArray($cachedValue, 'Cached tags must be a pure primitive array');

        // Second request
        $response2 = $this->getJson('/api/tags');
        $response2->assertOk();
        $response2->assertJsonFragment(['slug' => 'hoc-duong']);
    }

    /**
     * Regression test: Self-healing guard when stale cache contains an incomplete object.
     */
    public function test_self_healing_when_cache_contains_incomplete_class(): void
    {
        Genre::create([
            'name' => 'Trinh Thám',
            'slug' => 'trinh-tham',
            'is_active' => true,
        ]);

        // Simulate stale cache containing an incomplete object
        $staleIncompleteObject = unserialize('O:35:"NonExistentLegacyCacheObjectWrapper":0:{}');
        $this->assertInstanceOf('__PHP_Incomplete_Class', $staleIncompleteObject);

        // Put stale incomplete object into cache
        Cache::put(Genre::CACHE_KEY, $staleIncompleteObject, 3600);

        // Request must NOT fail with "call a method on an incomplete object"
        // Instead, the self-healing guard detects non-array, clears cache, and re-fetches cleanly
        $response = $this->getJson('/api/genres');
        $response->assertOk();
        $response->assertJsonFragment(['slug' => 'trinh-tham']);

        // Cache must now contain clean array
        $fixedCache = Cache::get(Genre::CACHE_KEY);
        $this->assertIsArray($fixedCache);
    }
}
