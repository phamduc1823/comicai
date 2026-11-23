<?php

namespace Tests\Feature;

use App\Jobs\GenerateComicEmbeddingJob;
use App\Models\Comic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerateComicEmbeddingsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->creator = User::factory()->create(['role' => 'creator']);
    }

    protected function createComic(string $approvalStatus, ?array $embedding = null, ?string $hash = null): Comic
    {
        return Comic::forceCreate([
            'title' => 'Bộ Truyện ' . uniqid(),
            'slug' => 'bo-truyen-' . uniqid(),
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'comics/covers/sample.jpg',
            'approval_status' => $approvalStatus,
            'status' => 'ongoing',
            'embedding' => $embedding,
            'embedding_hash' => $hash,
        ]);
    }

    public function test_command_rejects_non_positive_chunk_sizes(): void
    {
        $this->artisan('comics:generate-embeddings', ['--chunk' => 0])
            ->expectsOutput('Chunk size must be a positive integer greater than 0.')
            ->assertExitCode(1);

        $this->artisan('comics:generate-embeddings', ['--chunk' => -5])
            ->expectsOutput('Chunk size must be a positive integer greater than 0.')
            ->assertExitCode(1);

        $this->artisan('comics:generate-embeddings', ['--chunk' => 'invalid'])
            ->expectsOutput('Chunk size must be a positive integer greater than 0.')
            ->assertExitCode(1);
    }

    public function test_command_only_selects_approved_comics_without_embeddings_by_default(): void
    {
        Queue::fake();

        // 1. Approved without embedding (Should be picked)
        $approvedNeedsEmbedding = $this->createComic('approved', null, null);

        // 2. Approved with embedding but null hash (Should be picked)
        $approvedNeedsHash = $this->createComic('approved', [0.1, 0.2], null);

        // 3. Approved with embedding and hash (Should NOT be picked)
        $approvedComplete = $this->createComic('approved', [0.1, 0.2], 'some-valid-sha256-hash');

        // 4. Draft / Pending / Rejected without embedding (Should NOT be picked)
        $draft = $this->createComic('draft', null, null);
        $pending = $this->createComic('pending', null, null);
        $rejected = $this->createComic('rejected', null, null);

        $this->artisan('comics:generate-embeddings', ['--chunk' => 10])
            ->expectsOutput('Found 2 approved comics to process.')
            ->expectsOutput('Dispatched 2 embedding jobs to queue.')
            ->assertExitCode(0);

        Queue::assertPushed(GenerateComicEmbeddingJob::class, 2);
        Queue::assertPushed(GenerateComicEmbeddingJob::class, fn ($j) => $j->comicId === $approvedNeedsEmbedding->id);
        Queue::assertPushed(GenerateComicEmbeddingJob::class, fn ($j) => $j->comicId === $approvedNeedsHash->id);
        Queue::assertNotPushed(GenerateComicEmbeddingJob::class, fn ($j) => $j->comicId === $approvedComplete->id);
        Queue::assertNotPushed(GenerateComicEmbeddingJob::class, fn ($j) => $j->comicId === $draft->id);
        Queue::assertNotPushed(GenerateComicEmbeddingJob::class, fn ($j) => $j->comicId === $pending->id);
        Queue::assertNotPushed(GenerateComicEmbeddingJob::class, fn ($j) => $j->comicId === $rejected->id);

        Http::assertNothingSent();
    }

    public function test_command_force_option_dispatches_all_approved_comics(): void
    {
        Queue::fake();

        // Approved with embedding and hash
        $approvedComplete = $this->createComic('approved', [0.1, 0.2], 'valid-hash');
        // Approved without embedding
        $approvedIncomplete = $this->createComic('approved', null, null);
        // Pending
        $pending = $this->createComic('pending', null, null);

        $this->artisan('comics:generate-embeddings', ['--force' => true, '--chunk' => 50])
            ->expectsOutput('Found 2 approved comics to process.')
            ->expectsOutput('Dispatched 2 embedding jobs to queue.')
            ->assertExitCode(0);

        Queue::assertPushed(GenerateComicEmbeddingJob::class, 2);
        Queue::assertPushed(GenerateComicEmbeddingJob::class, fn ($j) => $j->comicId === $approvedComplete->id);
        Queue::assertPushed(GenerateComicEmbeddingJob::class, fn ($j) => $j->comicId === $approvedIncomplete->id);
        Queue::assertNotPushed(GenerateComicEmbeddingJob::class, fn ($j) => $j->comicId === $pending->id);
    }

    public function test_command_handles_empty_dataset_gracefully(): void
    {
        Queue::fake();

        $this->artisan('comics:generate-embeddings')
            ->expectsOutput('Found 0 approved comics to process.')
            ->expectsOutput('No comics require embedding generation.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
