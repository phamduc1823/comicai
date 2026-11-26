<?php

namespace Tests\Feature;

use App\Models\Comic;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGenreTagApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->reader = User::factory()->create(['role' => 'reader']);
    }

    public function test_non_admin_cannot_access_genres_or_tags_management(): void
    {
        $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/admin/genres')
            ->assertStatus(403);

        $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/admin/tags')
            ->assertStatus(403);
    }

    public function test_admin_can_list_create_update_and_delete_genres(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/genres', [
                'name' => 'Hành Động Kỳ Ảo',
                'description' => 'Mô tả thể loại',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Hành Động Kỳ Ảo')
            ->assertJsonPath('data.slug', 'hanh-dong-ky-ao');

        $genreId = $response->json('data.id');

        // Update
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/genres/{$genreId}", [
                'name' => 'Hành Động Phiêu Lưu',
                'description' => 'Mô tả mới',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Hành Động Phiêu Lưu');

        // Toggle Status
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/genres/{$genreId}/toggle-status")
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        // Delete when no comic relation
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/genres/{$genreId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('genres', ['id' => $genreId]);
    }

    public function test_cannot_delete_genre_with_associated_comics(): void
    {
        $genre = Genre::create([
            'name' => 'Kiếm Hiệp',
            'slug' => 'kiem-hiep',
        ]);

        $comic = Comic::create([
            'title' => 'Võ Lâm Truyền Kỳ',
            'slug' => 'vo-lam-truyen-ky',
            'author_name' => 'Kim Dung',
            'user_id' => $this->admin->id,
            'cover_path' => 'covers/test.webp',
            'status' => 'ongoing',
        ]);

        $comic->genres()->attach($genre->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/genres/{$genre->id}");

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('genres', ['id' => $genre->id]);
    }

    public function test_admin_can_manage_tags_and_safe_delete_guard(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/tags', [
                'name' => 'Trọng Sinh',
                'description' => 'Nhân vật được tái sinh',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Trọng Sinh')
            ->assertJsonPath('data.slug', 'trong-sinh');

        $tagId = $response->json('data.id');

        // Cannot delete if comic attached
        $comic = Comic::create([
            'title' => 'Trọng Sinh Đô Thị',
            'slug' => 'trong-sinh-do-thi',
            'author_name' => 'Vô Danh',
            'user_id' => $this->admin->id,
            'cover_path' => 'covers/test.webp',
            'status' => 'ongoing',
        ]);
        $comic->tags()->attach($tagId);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/tags/{$tagId}")
            ->assertStatus(422);

        // Detach and delete safely
        $comic->tags()->detach($tagId);
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/tags/{$tagId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('tags', ['id' => $tagId]);
    }
}
