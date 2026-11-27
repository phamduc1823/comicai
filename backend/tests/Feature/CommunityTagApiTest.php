<?php

namespace Tests\Feature;

use App\Models\Comic;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use App\Services\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommunityTagApiTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private User $reader;
    private Genre $genre;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->creator = User::factory()->create([
            'role' => 'creator',
            'is_active' => true,
        ]);

        $this->reader = User::factory()->create([
            'role' => 'reader',
            'is_active' => true,
        ]);

        $this->genre = Genre::create([
            'name' => 'Hành động',
            'slug' => 'hanh-dong',
            'description' => 'Mô tả thể loại hành động',
            'is_active' => true,
        ]);
    }

    /**
     * Test normalization removes accents, hash symbol, whitespace and lowercases.
     */
    public function test_normalization_rules_for_community_tags(): void
    {
        $this->assertSame('hanhdonggiatgan', TagService::normalizeCommunityTag('#Hành Động Giật Gân'));
        $this->assertSame('sieutromdangcap', TagService::normalizeCommunityTag('#Siêu Trộm Đẳng Cấp'));
        $this->assertSame('xuyenkhong', TagService::normalizeCommunityTag('Xuyên Không'));
        $this->assertSame('mainbaodao', TagService::normalizeCommunityTag('   #main-bao-dao!   '));
        $this->assertSame('phuchuyen', TagService::normalizeCommunityTag('phục huyên'));
        $this->assertSame('ancom', TagService::normalizeCommunityTag('###Ăn Cơm###'));
    }

    /**
     * Test autocomplete prioritizes prefix match over substring match.
     */
    public function test_tag_suggestions_prefix_matches_first(): void
    {
        Tag::create(['name' => 'coancom', 'slug' => 'coancom']);
        Tag::create(['name' => 'ancomngonlam', 'slug' => 'ancomngonlam']);
        Tag::create(['name' => 'sieuancom', 'slug' => 'sieuancom']);
        Tag::create(['name' => 'khonglienquan', 'slug' => 'khonglienquan']);

        $response = $this->getJson('/api/tags/suggestions?q=ancom');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(3, $data);
        // Prefix match must be first
        $this->assertSame('ancomngonlam', $data[0]['name']);
        // Substring matches follow
        $names = array_column($data, 'name');
        $this->assertContains('coancom', $names);
        $this->assertContains('sieuancom', $names);
        $this->assertNotContains('khonglienquan', $names);
    }

    /**
     * Test autocomplete returns max 10 suggestions.
     */
    public function test_tag_suggestions_capped_at_10(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            Tag::create([
                'name' => sprintf('testtag%02d', $i),
                'slug' => sprintf('testtag%02d', $i),
            ]);
        }

        $response = $this->getJson('/api/tags/suggestions?q=testtag');

        $response->assertOk();
        $response->assertJsonCount(10, 'data');
    }

    /**
     * Test autocomplete excludes specified already selected tag IDs.
     */
    public function test_tag_suggestions_excludes_selected_tag_ids(): void
    {
        $t1 = Tag::create(['name' => 'tagmot', 'slug' => 'tagmot']);
        $t2 = Tag::create(['name' => 'taghai', 'slug' => 'taghai']);
        $t3 = Tag::create(['name' => 'tagba', 'slug' => 'tagba']);

        $response = $this->getJson("/api/tags/suggestions?q=tag&exclude={$t1->id},{$t2->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('tagba', $data[0]['name']);
    }

    /**
     * Test empty query string returns empty data without querying.
     */
    public function test_tag_suggestions_with_empty_query_returns_empty(): void
    {
        Tag::create(['name' => 'kiemthuat', 'slug' => 'kiemthuat']);

        $response = $this->getJson('/api/tags/suggestions?q=');

        $response->assertOk();
        $response->assertExactJson([
            'status' => 'success',
            'data' => [],
        ]);
    }

    /**
     * Test creator can create a new community tag via POST /api/creator/tags.
     */
    public function test_creator_can_create_new_community_tag(): void
    {
        $response = $this->actingAs($this->creator, 'sanctum')
            ->postJson('/api/creator/tags', [
                'name' => '#Main Bá Đạo',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.name', 'mainbadao');
        $response->assertJsonPath('data.slug', 'mainbadao');
        $response->assertJsonPath('is_new', true);

        $this->assertDatabaseHas('tags', [
            'name' => 'mainbadao',
            'slug' => 'mainbadao',
        ]);
    }

    /**
     * Test creating tag that matches existing system tag reuses existing tag without duplication.
     */
    public function test_community_tag_reuses_existing_system_tag(): void
    {
        $systemTag = Tag::create([
            'name' => 'Xuyên không',
            'slug' => 'xuyen-khong',
            'description' => 'Hệ thống du hành xuyên không',
        ]);

        $response = $this->actingAs($this->creator, 'sanctum')
            ->postJson('/api/creator/tags', [
                'name' => 'Xuyên Không',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.id', $systemTag->id);
        $response->assertJsonPath('data.name', 'Xuyên không');
        $response->assertJsonPath('data.slug', 'xuyen-khong');
        $response->assertJsonPath('is_new', false);

        // Verify no duplicate record was created
        $this->assertSame(1, Tag::count());
    }

    /**
     * Test creating tag that matches existing community tag reuses it.
     */
    public function test_community_tag_reuses_existing_community_tag(): void
    {
        $existing = Tag::create([
            'name' => 'hanhdonggiatgan',
            'slug' => 'hanhdonggiatgan',
        ]);

        $response = $this->actingAs($this->creator, 'sanctum')
            ->postJson('/api/creator/tags', [
                'name' => '#Hành Động Giật Gân',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $existing->id);
        $response->assertJsonPath('is_new', false);
        $this->assertSame(1, Tag::count());
    }

    /**
     * Test tag creation validation rejects short or invalid characters.
     */
    public function test_community_tag_validation_rejects_invalid_short_tag(): void
    {
        $response = $this->actingAs($this->creator, 'sanctum')
            ->postJson('/api/creator/tags', [
                'name' => '###',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');

        $response2 = $this->actingAs($this->creator, 'sanctum')
            ->postJson('/api/creator/tags', [
                'name' => 'a',
            ]);

        $response2->assertStatus(422);
        $response2->assertJsonValidationErrors('name');
    }

    /**
     * Test non-creator role cannot create community tag.
     */
    public function test_non_creator_cannot_create_community_tag(): void
    {
        $response = $this->actingAs($this->reader, 'sanctum')
            ->postJson('/api/creator/tags', [
                'name' => 'tagmoitoan',
            ]);

        $response->assertStatus(403);
    }

    /**
     * Test comic creation enforces max 10 tags limit.
     */
    public function test_comic_creation_rejects_more_than_10_tags(): void
    {
        $tags = [];
        for ($i = 1; $i <= 11; $i++) {
            $tags[] = Tag::create([
                'name' => "tag{$i}",
                'slug' => "tag{$i}",
            ])->id;
        }

        $file = UploadedFile::fake()->image('cover.jpg', 600, 900);

        $response = $this->actingAs($this->creator, 'sanctum')
            ->postJson('/api/creator/comics', [
                'title' => 'Truyện Test Giới Hạn Tag',
                'author_name' => 'Tác Giả',
                'age_rating' => '13+',
                'status' => 'ongoing',
                'genres' => [$this->genre->id],
                'tags' => $tags, // 11 tags
                'cover' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tags');
    }

    /**
     * Test comic creation rejects duplicate tag ids in payload.
     */
    public function test_comic_creation_rejects_duplicate_tag_ids(): void
    {
        $tag = Tag::create(['name' => 'tagduyest', 'slug' => 'tagduyest']);
        $file = UploadedFile::fake()->image('cover.jpg', 600, 900);

        $response = $this->actingAs($this->creator, 'sanctum')
            ->postJson('/api/creator/comics', [
                'title' => 'Truyện Test Duplicate Tag',
                'author_name' => 'Tác Giả',
                'age_rating' => '13+',
                'status' => 'ongoing',
                'genres' => [$this->genre->id],
                'tags' => [$tag->id, $tag->id], // Duplicated
                'cover' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tags.0');
    }

    /**
     * Test comic creation successfully accepts up to 10 mixed tags.
     */
    public function test_comic_creation_accepts_valid_tags_up_to_10(): void
    {
        $tagIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $tagIds[] = Tag::create([
                'name' => "hopletag{$i}",
                'slug' => "hopletag{$i}",
            ])->id;
        }

        $file = UploadedFile::fake()->image('cover.jpg', 600, 900);

        $response = $this->actingAs($this->creator, 'sanctum')
            ->postJson('/api/creator/comics', [
                'title' => 'Truyện Hợp Lệ 5 Tags',
                'author_name' => 'Tác Giả',
                'age_rating' => '13+',
                'status' => 'ongoing',
                'genres' => [$this->genre->id],
                'tags' => $tagIds,
                'cover' => $file,
            ]);

        $response->assertStatus(201);
        $comicId = $response->json('data.id');
        $comic = Comic::with('tags')->findOrFail($comicId);
        $this->assertCount(5, $comic->tags);
    }
}
