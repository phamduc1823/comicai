<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Models\Banner;
use App\Models\Chapter;
use App\Models\ChapterImage;
use App\Models\ChapterUnlock;
use App\Models\Comic;
use App\Models\Conversation;
use App\Models\Genre;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AiSearchService;
use App\Services\Contracts\EmbeddingServiceInterface;
use App\Services\FallbackSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerformanceBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private User $admin;
    private User $userA;
    private User $userB;
    private Genre $genreAction;
    private Tag $tagShounen;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->creator = User::create([
            'name' => 'Creator Author',
            'email' => 'creator@example.com',
            'password' => bcrypt('password123'),
        ]);
        $this->creator->role = 'creator';
        $this->creator->save();

        $this->admin = User::create([
            'name' => 'Admin Boss',
            'email' => 'admin@example.com',
            'password' => bcrypt('password123'),
        ]);
        $this->admin->role = 'admin';
        $this->admin->save();

        $this->userA = User::create([
            'name' => 'User Alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('password123'),
        ]);
        $this->userA->role = 'user';
        $this->userA->save();

        $this->userB = User::create([
            'name' => 'User Bob',
            'email' => 'bob@example.com',
            'password' => bcrypt('password123'),
        ]);
        $this->userB->role = 'user';
        $this->userB->save();

        $this->genreAction = Genre::create([
            'name' => 'Hành Động',
            'slug' => 'hanh-dong',
            'description' => 'Thể loại hành động kịch tính',
            'is_active' => true,
        ]);

        $this->tagShounen = Tag::create([
            'name' => 'Shounen',
            'slug' => 'shounen',
            'description' => 'Dành cho thiếu niên',
        ]);
    }

    /**
     * Helper to seed the comics fixture with associations.
     */
    private function seedPerformanceFixture(int $comicCount = 50, int $chaptersPerComic = 10, int $startIndex = 1): void
    {
        for ($i = $startIndex; $i < $startIndex + $comicCount; $i++) {
            $comic = Comic::create([
                'title' => "Siêu Phẩm Truyện Số $i",
                'slug' => "sieu-pham-truyen-$i",
                'author_name' => "Tác Giả $i",
                'description' => "Cốt truyện đầy hấp dẫn của bộ truyện số $i với nhiều tình tiết gay cấn.",
                'user_id' => $this->creator->id,
                'cover_path' => "covers/comic-$i.webp",
                'banner_path' => "banners/comic-$i.webp",
                'age_rating' => '13+',
                'status' => 'ongoing',
                'views_count' => $i * 100,
                'follows_count' => $i * 10,
            ]);

            $comic->approval_status = 'approved';
            $comic->approved_by = $this->admin->id;
            $comic->approved_at = now();
            $comic->save();

            $comic->genres()->attach($this->genreAction->id);
            $comic->tags()->attach($this->tagShounen->id);

            for ($ch = 1; $ch <= $chaptersPerComic; $ch++) {
                $isVip = ($ch >= 8); // Chapters 8, 9, 10 are VIP
                $chapter = Chapter::create([
                    'comic_id' => $comic->id,
                    'chapter_number' => sprintf('%.2f', $ch),
                    'title' => "Chương $ch: Khởi đầu mới",
                    'slug' => "chuong-$ch",
                    'status' => 'published',
                    'is_vip' => $isVip,
                    'price' => $isVip ? 15 : 0,
                    'views_count' => $ch * 50,
                ]);

                // Create 3 page images per chapter
                for ($p = 1; $p <= 3; $p++) {
                    ChapterImage::create([
                        'chapter_id' => $chapter->id,
                        'page_number' => $p,
                        'image_path' => "pages/c{$comic->id}_ch{$chapter->id}_p{$p}.webp",
                        'width' => 1200,
                        'height' => 1800,
                    ]);
                }
            }
        }
    }

    /**
     * Helper to execute callback while logging queries.
     */
    private function captureQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return $queries;
    }

    /**
     * 1. GET /api/comics query count test:
     * - Must be <= 5 queries for paginated index.
     * - Query count must NOT scale with the number of comics (O(1)).
     */
    public function test_catalog_query_count_is_bounded_and_does_not_scale_linearly(): void
    {
        // First test with 5 comics
        $this->seedPerformanceFixture(5, 5, 1);

        $queries5 = $this->captureQueries(function () {
            $response = $this->getJson('/api/comics?per_page=5');
            $response->assertOk();
            $response->assertJsonCount(5, 'data');
        });
        $count5 = count($queries5);

        // Seed up to 50 comics (45 more, starting at 6)
        $this->seedPerformanceFixture(45, 5, 6);

        $queries50 = $this->captureQueries(function () {
            $response = $this->getJson('/api/comics?per_page=15');
            $response->assertOk();
            $response->assertJsonCount(15, 'data');
        });
        $count50 = count($queries50);

        // Verification:
        // Query 1: count(*) for pagination
        // Query 2: select comics with published_chapters_count
        // Query 3: eager load genres
        // Query 4: eager load tags
        // Query 5: eager load creator user
        $this->assertLessThanOrEqual(5, $count50, "GET /api/comics query count must be <= 5 queries, got {$count50}");
        $this->assertEquals($count5, $count50, "Query count must be constant O(1), not scale from {$count5} to {$count50}");
    }

    /**
     * 2. GET /api/comics/{slug}/chapters/{num} query count test:
     * - Eager loads comic, chapter, images, prev, and next navigation without N+1.
     */
    public function test_reader_api_query_count_is_bounded_and_eager_loads_images_and_navigation(): void
    {
        $this->seedPerformanceFixture(2, 5, 1);

        $queries = $this->captureQueries(function () {
            $response = $this->getJson('/api/comics/sieu-pham-truyen-1/chapters/2');
            $response->assertOk();
            $response->assertJsonPath('data.chapter_number', '2.00');
            $response->assertJsonPath('data.prev_chapter.chapter_number', '1.00');
            $response->assertJsonPath('data.next_chapter.chapter_number', '3.00');
            $response->assertJsonCount(3, 'data.images');
        });

        // 5 queries: comic lookup, chapter lookup, images eager-load, prev chapter, next chapter
        $this->assertLessThanOrEqual(5, count($queries), "Reader API query count must be <= 5 queries");
    }

    /**
     * 3. GET /api/chat/conversations query count test:
     * - Verifies conversation query count does not scale with conversation count.
     */
    public function test_chat_conversations_query_count_does_not_scale_with_conversation_count(): void
    {
        // Create 10 conversations for User A with different users
        for ($i = 1; $i <= 10; $i++) {
            $peer = User::create([
                'name' => "Peer User $i",
                'email' => "peer$i@example.com",
                'password' => bcrypt('password123'),
            ]);

            $conv = Conversation::create([
                'type' => 'direct',
                'user_one_id' => min($this->userA->id, $peer->id),
                'user_two_id' => max($this->userA->id, $peer->id),
            ]);

            $conv->participants()->createMany([
                ['user_id' => $this->userA->id],
                ['user_id' => $peer->id],
            ]);

            $msg = Message::create([
                'conversation_id' => $conv->id,
                'sender_id' => $peer->id,
                'type' => 'text',
                'body' => "Message in conversation $i",
            ]);

            $conv->update(['last_message_id' => $msg->id, 'last_message_at' => now()]);
        }

        $queries = $this->captureQueries(function () {
            $response = $this->actingAs($this->userA, 'sanctum')->getJson('/api/chat/conversations');
            $response->assertOk();
            $response->assertJsonCount(10, 'data');
        });

        // Expected queries:
        // 1. count(*) pagination
        // 2. select conversations with unread_count subquery
        // 3. eager load participants with users
        // 4. eager load lastMessage
        // Total <= 5 queries for 10 conversations!
        $this->assertLessThanOrEqual(5, count($queries), "Conversations list query count must be <= 5 for 10 conversations");
    }

    /**
     * 4. Safe caching and invalidation for Genres:
     */
    public function test_genres_caching_and_invalidation(): void
    {
        Cache::forget(Genre::CACHE_KEY);

        // 1. First request: Cache Miss -> queries DB
        $queriesMiss = $this->captureQueries(function () {
            $response = $this->getJson('/api/genres');
            $response->assertOk();
            $response->assertJsonFragment(['slug' => 'hanh-dong']);
        });
        $this->assertGreaterThan(0, count($queriesMiss), 'Initial request must query database');
        $this->assertTrue(Cache::has(Genre::CACHE_KEY), 'Cache key must be populated after first request');

        // 2. Second request: Cache Hit -> 0 DB queries!
        $queriesHit = $this->captureQueries(function () {
            $response = $this->getJson('/api/genres');
            $response->assertOk();
            $response->assertJsonFragment(['slug' => 'hanh-dong']);
        });
        $this->assertEquals(0, count($queriesHit), 'Cache hit must execute 0 database queries');

        // 3. Invalidation via Model Event: creating a new genre flushes cache
        $newGenre = Genre::create([
            'name' => 'Phiêu Lưu',
            'slug' => 'phieu-luu',
            'is_active' => true,
        ]);
        $this->assertFalse(Cache::has(Genre::CACHE_KEY), 'Cache must be invalidated when a genre is created');

        // 4. Re-populate and test update invalidation
        $this->getJson('/api/genres');
        $this->assertTrue(Cache::has(Genre::CACHE_KEY));

        $newGenre->update(['name' => 'Phiêu Lưu Kỳ Ảo']);
        $this->assertFalse(Cache::has(Genre::CACHE_KEY), 'Cache must be invalidated when a genre is updated');

        // 5. Re-populate and test delete invalidation
        $this->getJson('/api/genres');
        $this->assertTrue(Cache::has(Genre::CACHE_KEY));

        $newGenre->delete();
        $this->assertFalse(Cache::has(Genre::CACHE_KEY), 'Cache must be invalidated when a genre is deleted');

        // 6. Explicit / Bulk flush verification
        $this->getJson('/api/genres');
        $this->assertTrue(Cache::has(Genre::CACHE_KEY));
        Genre::flushCache();
        $this->assertFalse(Cache::has(Genre::CACHE_KEY), 'Genre::flushCache() must invalidate cache for bulk updates');
    }

    /**
     * 5. Safe caching and invalidation for Tags:
     */
    public function test_tags_caching_and_invalidation(): void
    {
        Cache::forget(Tag::CACHE_KEY);

        // Cache Miss
        $queriesMiss = $this->captureQueries(function () {
            $response = $this->getJson('/api/tags');
            $response->assertOk();
            $response->assertJsonFragment(['slug' => 'shounen']);
        });
        $this->assertGreaterThan(0, count($queriesMiss));
        $this->assertTrue(Cache::has(Tag::CACHE_KEY));

        // Cache Hit
        $queriesHit = $this->captureQueries(function () {
            $response = $this->getJson('/api/tags');
            $response->assertOk();
            $response->assertJsonFragment(['slug' => 'shounen']);
        });
        $this->assertEquals(0, count($queriesHit), 'Cache hit on tags must execute 0 database queries');

        // Invalidation via Model Event
        $newTag = Tag::create([
            'name' => 'Isekai',
            'slug' => 'isekai',
        ]);
        $this->assertFalse(Cache::has(Tag::CACHE_KEY), 'Cache must be invalidated when a tag is created');

        // Bulk / explicit flush
        $this->getJson('/api/tags');
        $this->assertTrue(Cache::has(Tag::CACHE_KEY));
        Tag::flushCache();
        $this->assertFalse(Cache::has(Tag::CACHE_KEY), 'Tag::flushCache() must invalidate cache for bulk updates');
    }

    /**
     * 6. Safe caching and invalidation for Banners:
     */
    public function test_banners_caching_and_invalidation(): void
    {
        Cache::forget(Banner::CACHE_KEY);

        $comic = Comic::create([
            'title' => 'Banner Comic',
            'slug' => 'banner-comic',
            'author_name' => 'Author',
            'description' => 'Description',
            'user_id' => $this->creator->id,
            'cover_path' => 'placeholders/cover.webp',
            'banner_path' => 'placeholders/banner.webp',
        ]);
        $comic->approval_status = 'approved';
        $comic->save();

        $banner = Banner::create([
            'comic_id' => $comic->id,
            'desktop_banner_path' => 'banners/desktop.webp',
            'mobile_banner_path' => 'banners/mobile.webp',
            'is_active' => true,
        ]);

        // Cache Miss
        $queriesMiss = $this->captureQueries(function () {
            $response = $this->getJson('/api/banners');
            $response->assertOk();
            $response->assertJsonFragment(['desktop_banner_path' => 'banners/desktop.webp']);
        });
        $this->assertGreaterThan(0, count($queriesMiss));
        $this->assertTrue(Cache::has(Banner::CACHE_KEY));

        // Cache Hit
        $queriesHit = $this->captureQueries(function () {
            $response = $this->getJson('/api/banners');
            $response->assertOk();
            $response->assertJsonFragment(['desktop_banner_path' => 'banners/desktop.webp']);
        });
        $this->assertEquals(0, count($queriesHit), 'Cache hit on banners must execute 0 database queries');

        // Model Event invalidation
        $banner->update(['title_override' => 'New Title']);
        $this->assertFalse(Cache::has(Banner::CACHE_KEY), 'Cache must be invalidated when banner is updated');

        // Comic approval status change invalidation
        $this->getJson('/api/banners');
        $this->assertTrue(Cache::has(Banner::CACHE_KEY));

        $comic->approval_status = 'rejected';
        $comic->save();
        $this->assertFalse(Cache::has(Banner::CACHE_KEY), 'Banner cache must be invalidated when comic approval changes');

        // Explicit / bulk flush
        $this->getJson('/api/banners');
        $this->assertTrue(Cache::has(Banner::CACHE_KEY));
        Banner::flushCache();
        $this->assertFalse(Cache::has(Banner::CACHE_KEY), 'Banner::flushCache() must invalidate cache for bulk operations');
    }

    /**
     * 7. Safe query embedding caching with model/dimension/normalized-query key:
     */
    public function test_ai_query_embedding_caching_and_hit(): void
    {
        $mockEmbeddingService = new class implements EmbeddingServiceInterface {
            public int $callCount = 0;

            public function embed(string $text, ?int $dimensions = null): ?array
            {
                $this->callCount++;
                return array_fill(0, 768, 0.05);
            }

            public function isConfigured(): bool
            {
                return true;
            }
        };

        config(['ai.enabled' => true]);
        config(['ai.gemini.embedding_model' => 'gemini-embedding-001']);
        config(['ai.embedding_dimensions' => 768]);

        $fallbackService = app(FallbackSearchService::class);
        $aiSearchService = new AiSearchService($fallbackService, $mockEmbeddingService);

        $query = '  kiem hiep   chuyen sinh ';
        $normalizedQuery = 'kiem hiep   chuyen sinh';
        $hash = hash('sha256', mb_strtolower(trim($normalizedQuery)));
        $expectedKey = "ai:query_embedding:gemini-embedding-001:768:{$hash}";

        Cache::forget($expectedKey);

        // First call: calls embed()
        $aiSearchService->searchHybrid($query);
        $this->assertEquals(1, $mockEmbeddingService->callCount, 'First search must call embedding service');
        $this->assertTrue(Cache::has($expectedKey), 'Embedding vector must be stored in cache');

        // Second call with same normalized query: cache hit, no call to embed()!
        $aiSearchService->searchHybrid('kiem hiep   chuyen sinh');
        $this->assertEquals(1, $mockEmbeddingService->callCount, 'Second search must hit cache and NOT call embed() again');

        // Third call with whitespace variation: same normalized query -> cache hit!
        $aiSearchService->searchHybrid('  kiem hiep   chuyen sinh   ');
        $this->assertEquals(1, $mockEmbeddingService->callCount, 'Whitespace variation must normalize to same cache key');
    }

    /**
     * 8. Zero-Cache Isolation Test:
     * - Proves User A's unlocked VIP chapter or wallet balance is NEVER leaked to User B or Guest via cache.
     * - VIP unlock status and wallet balance are dynamically evaluated per request.
     */
    public function test_vip_chapter_and_wallet_zero_cache_isolation_between_users(): void
    {
        $this->seedPerformanceFixture(1, 10);
        $comic = Comic::where('slug', 'sieu-pham-truyen-1')->first();
        $vipChapter = Chapter::where('comic_id', $comic->id)->where('chapter_number', '8.00')->first();
        $this->assertTrue((bool) $vipChapter->is_vip);

        // Setup wallets
        $walletA = Wallet::create(['user_id' => $this->userA->id, 'balance' => 485]);
        $walletB = Wallet::create(['user_id' => $this->userB->id, 'balance' => 10]);

        $txA = \App\Models\CoinTransaction::create([
            'wallet_id' => $walletA->id,
            'user_id' => $this->userA->id,
            'type' => 'chapter_unlock',
            'amount' => -15,
            'balance_before' => 500,
            'balance_after' => 485,
            'reference_type' => 'chapter',
            'reference_id' => $vipChapter->id,
            'idempotency_key' => 'tx-perf-1',
            'description' => 'Mở khóa chương 8',
            'created_at' => now(),
        ]);

        // User A unlocks VIP chapter 8
        ChapterUnlock::create([
            'user_id' => $this->userA->id,
            'comic_id' => $comic->id,
            'chapter_id' => $vipChapter->id,
            'transaction_id' => $txA->id,
            'price_paid' => 15,
            'unlocked_at' => now(),
        ]);

        // 1. User A requests Chapter 8 -> unlocked: true, images visible
        $responseA = $this->actingAs($this->userA, 'sanctum')->getJson("/api/comics/{$comic->slug}/chapters/8");
        $responseA->assertOk();
        $responseA->assertJsonPath('data.is_vip', true);
        $responseA->assertJsonPath('data.is_unlocked', true);
        $responseA->assertJsonCount(3, 'data.images');

        // 2. User B requests Chapter 8 immediately after User A -> MUST BE LOCKED, images empty!
        $responseB = $this->actingAs($this->userB, 'sanctum')->getJson("/api/comics/{$comic->slug}/chapters/8");
        $responseB->assertOk();
        $responseB->assertJsonPath('data.is_vip', true);
        $responseB->assertJsonPath('data.is_unlocked', false);
        $responseB->assertJsonPath('data.user_balance', 10);
        $responseB->assertJsonCount(0, 'data.images');

        // 3. Guest (unauthenticated) requests Chapter 8 -> MUST BE LOCKED, images empty!
        $responseGuest = $this->getJson("/api/comics/{$comic->slug}/chapters/8");
        $responseGuest->assertOk();
        $responseGuest->assertJsonPath('data.is_vip', true);
        $responseGuest->assertJsonPath('data.is_unlocked', false);
        $responseGuest->assertJsonCount(0, 'data.images');

        // 4. Wallet endpoints isolation: User A balance != User B balance
        $walletRespA = $this->actingAs($this->userA, 'sanctum')->getJson('/api/wallet');
        $walletRespA->assertOk();
        $walletRespA->assertJsonPath('data.balance', 485);

        $walletRespB = $this->actingAs($this->userB, 'sanctum')->getJson('/api/wallet');
        $walletRespB->assertOk();
        $walletRespB->assertJsonPath('data.balance', 10);
    }

    /**
     * 9. Reverb Event Payload analysis and strict whitelist verification test:
     * - Verifies that MessageSent and ConversationUpdated broadcastWith() strictly contains whitelisted keys.
     * - Verifies that loaded relations and unwhitelisted model attributes are cleanly stripped.
     * - Verifies that sensitive user credentials and internal timestamps are never exposed.
     */
    public function test_reverb_events_payload_integrity_and_minimal_serialization(): void
    {
        $conv = Conversation::create([
            'type' => 'direct',
            'user_one_id' => min($this->userA->id, $this->userB->id),
            'user_two_id' => max($this->userA->id, $this->userB->id),
        ]);

        $message = Message::create([
            'conversation_id' => $conv->id,
            'sender_id' => $this->userA->id,
            'type' => 'text',
            'body' => 'Secret message body',
            'client_message_id' => 'cli-12345',
        ]);

        // Deliberately attach relations and unwhitelisted attributes to model before event instantiation
        $message->setRelation('sender', $this->userA);
        $message->setRelation('conversation', $conv);
        $message->setAttribute('dummy_internal_flag', 'leaked_internal_state');

        // ---------------------------------------------------------------------
        // 1. MessageSent event verification
        // ---------------------------------------------------------------------
        $sentEvent = new MessageSent($message);
        $payload = $sentEvent->broadcastWith();

        $this->assertEquals('MessageSent', $sentEvent->broadcastAs());
        $this->assertFalse($sentEvent->message->relationLoaded('sender'), 'sender relation must be stripped by withoutRelations()');
        $this->assertFalse($sentEvent->message->relationLoaded('conversation'), 'conversation relation must be stripped by withoutRelations()');

        $expectedNestedKeys = [
            'id',
            'conversation_id',
            'sender_id',
            'type',
            'body',
            'client_message_id',
            'created_at',
        ];
        sort($expectedNestedKeys);

        $actualNestedKeys = array_keys($payload['message']);
        sort($actualNestedKeys);
        $this->assertEquals($expectedNestedKeys, $actualNestedKeys, 'MessageSent nested message payload must strictly match whitelist keys');

        $expectedRootKeys = array_merge($expectedNestedKeys, ['message']);
        sort($expectedRootKeys);
        $actualRootKeys = array_keys($payload);
        sort($actualRootKeys);
        $this->assertEquals($expectedRootKeys, $actualRootKeys, 'MessageSent root broadcast payload must strictly match whitelist keys');

        // Negative assertions: unwhitelisted and sensitive fields must NOT exist anywhere in payload
        $this->assertArrayNotHasKey('dummy_internal_flag', $payload);
        $this->assertArrayNotHasKey('dummy_internal_flag', $payload['message']);
        $this->assertArrayNotHasKey('updated_at', $payload);
        $this->assertArrayNotHasKey('updated_at', $payload['message']);
        $this->assertArrayNotHasKey('sender', $payload);
        $this->assertArrayNotHasKey('sender', $payload['message']);
        $this->assertArrayNotHasKey('conversation', $payload);
        $this->assertArrayNotHasKey('conversation', $payload['message']);

        $serializedJson = json_encode($payload);
        $this->assertStringNotContainsString('alice@example.com', $serializedJson);
        $this->assertStringNotContainsString('password', $serializedJson);
        $this->assertStringNotContainsString('remember_token', $serializedJson);
        $this->assertStringNotContainsString('leaked_internal_state', $serializedJson);

        // ---------------------------------------------------------------------
        // 2. ConversationUpdated event verification
        // ---------------------------------------------------------------------
        $conversationUpdatedEvent = new \App\Events\ConversationUpdated(
            $conv->id,
            $this->userB->id,
            $message,
            3
        );
        $convPayload = $conversationUpdatedEvent->broadcastWith();

        $this->assertEquals('ConversationUpdated', $conversationUpdatedEvent->broadcastAs());
        $this->assertFalse($conversationUpdatedEvent->message->relationLoaded('sender'), 'sender relation must be stripped from ConversationUpdated');
        $this->assertFalse($conversationUpdatedEvent->message->relationLoaded('conversation'), 'conversation relation must be stripped from ConversationUpdated');

        $expectedConvRootKeys = ['conversation_id', 'last_message', 'unread_count'];
        sort($expectedConvRootKeys);
        $actualConvRootKeys = array_keys($convPayload);
        sort($actualConvRootKeys);
        $this->assertEquals($expectedConvRootKeys, $actualConvRootKeys, 'ConversationUpdated root payload must strictly contain only conversation_id, last_message, unread_count');

        $actualLastMessageKeys = array_keys($convPayload['last_message']);
        sort($actualLastMessageKeys);
        $this->assertEquals($expectedNestedKeys, $actualLastMessageKeys, 'ConversationUpdated last_message payload must strictly match whitelist keys');

        $this->assertArrayNotHasKey('dummy_internal_flag', $convPayload['last_message']);
        $this->assertArrayNotHasKey('updated_at', $convPayload['last_message']);
        $this->assertArrayNotHasKey('sender', $convPayload['last_message']);
        $this->assertArrayNotHasKey('conversation', $convPayload['last_message']);

        $convSerializedJson = json_encode($convPayload);
        $this->assertStringNotContainsString('alice@example.com', $convSerializedJson);
        $this->assertStringNotContainsString('password', $convSerializedJson);
        $this->assertStringNotContainsString('remember_token', $convSerializedJson);
        $this->assertStringNotContainsString('leaked_internal_state', $convSerializedJson);

        // ---------------------------------------------------------------------
        // 3. MessagesRead event verification
        // ---------------------------------------------------------------------
        $readEvent = new MessagesRead($conv->id, $this->userB->id, $message->id, now()->toISOString());
        $readPayload = $readEvent->broadcastWith();

        $this->assertEquals('MessagesRead', $readEvent->broadcastAs());
        $expectedReadKeys = ['conversation_id', 'user_id', 'last_read_message_id', 'last_read_at'];
        sort($expectedReadKeys);
        $actualReadKeys = array_keys($readPayload);
        sort($actualReadKeys);
        $this->assertEquals($expectedReadKeys, $actualReadKeys, 'MessagesRead payload must strictly contain only scalar read tracking fields');

        // ---------------------------------------------------------------------
        // 4. Message Model visible attributes verification
        // ---------------------------------------------------------------------
        $modelArray = $message->withoutRelations()->toArray();
        $actualModelKeys = array_keys($modelArray);
        sort($actualModelKeys);
        $this->assertEquals($expectedNestedKeys, $actualModelKeys, 'Message Eloquent model serialization must strictly respect $visible whitelist');
        $this->assertArrayNotHasKey('updated_at', $modelArray);
    }
}
