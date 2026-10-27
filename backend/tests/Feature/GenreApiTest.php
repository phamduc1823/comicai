<?php

namespace Tests\Feature;

use App\Models\Genre;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenreApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test GET /api/genres returns only active genres sorted by name.
     */
    public function test_genres_endpoint_returns_only_active_genres_sorted_by_name(): void
    {
        Genre::create([
            'name' => 'Tình Cảm',
            'slug' => 'romance',
            'description' => 'Truyện tình cảm lãng mạn.',
            'is_active' => true,
        ]);

        Genre::create([
            'name' => 'Hành Động',
            'slug' => 'action',
            'description' => 'Truyện hành động kịch tính.',
            'is_active' => true,
        ]);

        Genre::create([
            'name' => 'Thể Loại Ẩn',
            'slug' => 'hidden-genre',
            'description' => 'Thể loại thử nghiệm đang tạm ẩn.',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/genres');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

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

        // Verify sorted alphabetically by name ('Hành Động' before 'Tình Cảm')
        $data = $response->json('data');
        $this->assertSame('Hành Động', $data[0]['name']);
        $this->assertSame('Tình Cảm', $data[1]['name']);

        // Verify inactive genre is excluded
        $slugs = array_column($data, 'slug');
        $this->assertNotContains('hidden-genre', $slugs);

        // Verify no internal attributes are leaked
        $this->assertArrayNotHasKey('is_active', $data[0]);
        $this->assertArrayNotHasKey('created_at', $data[0]);
        $this->assertArrayNotHasKey('updated_at', $data[0]);
    }

    /**
     * Test GET /api/genres returns empty data array when no active genres exist.
     */
    public function test_genres_endpoint_returns_empty_when_no_active_genres(): void
    {
        Genre::create([
            'name' => 'Thể Loại Ẩn',
            'slug' => 'hidden-genre',
            'description' => 'Tạm ẩn.',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/genres');

        $response->assertOk();
        $response->assertExactJson(['data' => []]);
    }

    /**
     * Test GET /api/genres with full seeded database.
     */
    public function test_genres_endpoint_with_database_seeder(): void
    {
        $this->seed(DatabaseSeeder::class);

        $response = $this->getJson('/api/genres');

        $response->assertOk();
        $response->assertJsonCount(8, 'data');
    }
}
