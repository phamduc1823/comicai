<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verify all 12 business tables exist after running migrations.
     */
    public function test_all_12_business_tables_exist(): void
    {
        $expectedTables = [
            'users',
            'genres',
            'tags',
            'comics',
            'comic_genre',
            'comic_tag',
            'chapters',
            'chapter_images',
            'follows',
            'bookmarks',
            'reading_histories',
            'banners',
        ];

        foreach ($expectedTables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} should exist in database.");
        }
    }

    /**
     * Verify users business columns.
     */
    public function test_users_table_has_business_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'id', 'name', 'email', 'password', 'role', 'avatar_path', 'is_active', 'created_at', 'updated_at',
        ]));
    }

    /**
     * Verify comics business columns.
     */
    public function test_comics_table_has_business_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('comics', [
            'id', 'title', 'slug', 'author_name', 'user_id', 'description',
            'cover_path', 'banner_path', 'age_rating', 'status', 'approval_status',
            'approved_by', 'approved_at', 'rejection_reason', 'pending_changes',
            'views_count', 'follows_count', 'is_featured', 'ai_summary', 'embedding',
        ]));
    }

    /**
     * Verify chapters business columns.
     */
    public function test_chapters_table_has_business_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('chapters', [
            'id', 'comic_id', 'chapter_number', 'title', 'slug', 'status',
            'rejection_reason', 'views_count', 'created_at', 'updated_at',
        ]));
    }

    /**
     * Verify reading_histories business columns.
     */
    public function test_reading_histories_table_has_business_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('reading_histories', [
            'id', 'user_id', 'comic_id', 'chapter_id', 'updated_at',
        ]));
    }
}
