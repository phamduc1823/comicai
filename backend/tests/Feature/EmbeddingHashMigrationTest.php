<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmbeddingHashMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_comics_table_has_embedding_and_embedding_hash_columns(): void
    {
        $this->assertTrue(Schema::hasTable('comics'), 'comics table must exist');
        $this->assertTrue(Schema::hasColumn('comics', 'embedding'), 'comics must have embedding column');
        $this->assertTrue(Schema::hasColumn('comics', 'embedding_hash'), 'comics must have embedding_hash column');
    }

    public function test_migration_down_drops_embedding_hash_column(): void
    {
        $migration = require database_path('migrations/2026_10_28_000002_add_embedding_hash_to_comics_table.php');

        // Execute rollback on isolated test database
        $migration->down();

        $this->assertFalse(Schema::hasColumn('comics', 'embedding_hash'), 'embedding_hash must be dropped on rollback');
        $this->assertTrue(Schema::hasColumn('comics', 'embedding'), 'embedding column must be preserved');

        // Re-apply up to keep test environment clean
        $migration->up();
        $this->assertTrue(Schema::hasColumn('comics', 'embedding_hash'));
    }
}
