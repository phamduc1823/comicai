<?php

namespace Tests\Feature;

use App\Models\Tag;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test GET /api/tags returns all tags sorted by name.
     */
    public function test_tags_endpoint_returns_all_tags_sorted_by_name(): void
    {
        Tag::create([
            'name' => 'Quái vật',
            'slug' => 'quai-vat',
            'description' => 'Đối đầu với dã thú hung hãn.',
        ]);

        Tag::create([
            'name' => 'Băng đảng',
            'slug' => 'bang-dang',
            'description' => 'Thế giới ngầm và bang hội.',
        ]);

        Tag::create([
            'name' => 'Kiếm thuật',
            'slug' => 'kiem-thuat',
            'description' => 'Kỹ năng sử dụng kiếm pháp xuất chúng.',
        ]);

        $response = $this->getJson('/api/tags');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');

        // Check exact whitelist structure
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'slug',
                    'description',
                ],
            ],
        ]);

        // Verify sorted alphabetically by name
        $data = $response->json('data');
        $this->assertSame('Băng đảng', $data[0]['name']);
        $this->assertSame('Kiếm thuật', $data[1]['name']);
        $this->assertSame('Quái vật', $data[2]['name']);

        // Verify no internal attributes are leaked
        $this->assertArrayNotHasKey('created_at', $data[0]);
        $this->assertArrayNotHasKey('updated_at', $data[0]);
    }

    /**
     * Test GET /api/tags returns empty data array when no tags exist.
     */
    public function test_tags_endpoint_returns_empty_when_no_tags(): void
    {
        $response = $this->getJson('/api/tags');

        $response->assertOk();
        $response->assertExactJson(['data' => []]);
    }

    /**
     * Test GET /api/tags with full seeded database.
     */
    public function test_tags_endpoint_with_database_seeder(): void
    {
        $this->seed(DatabaseSeeder::class);

        $response = $this->getJson('/api/tags');

        $response->assertOk();
        $response->assertJsonCount(12, 'data');
    }
}
