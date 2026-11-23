<?php

namespace Tests\Feature;

use App\Exceptions\NonRetryableEmbeddingException;
use App\Exceptions\TransientEmbeddingException;
use App\Jobs\GenerateComicEmbeddingJob;
use App\Models\Comic;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use App\Services\Contracts\EmbeddingServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class GenerateComicEmbeddingJobTest extends TestCase
{
    use RefreshDatabase;

    protected User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        \Illuminate\Support\Facades\Config::set('ai.enabled', true);
        $this->creator = User::factory()->create(['role' => 'creator']);
    }

    protected function createApprovedComic(array $attributes = []): Comic
    {
        return Comic::forceCreate(array_merge([
            'title' => 'Thám Tử Lừng Danh',
            'slug' => 'tham-tu-lung-danh-' . uniqid(),
            'alternative_titles' => 'Detective Conan',
            'author_name' => 'Aoyama Gosho',
            'user_id' => $this->creator->id,
            'description' => 'Câu chuyện về thám tử thiếu niên Shinichi bị teo nhỏ thành Conan phá án.',
            'cover_path' => 'comics/covers/conan.jpg',
            'age_rating' => '13+',
            'status' => 'ongoing',
            'approval_status' => 'approved',
            'ai_summary' => 'Thám tử bị teo nhỏ thành học sinh tiểu học, đấu tranh với tổ chức áo đen.',
        ], $attributes));
    }

    public function test_approved_comic_is_processed_and_vector_with_hash_saved_atomically(): void
    {
        $comic = $this->createApprovedComic();
        $genre = Genre::create(['name' => 'Trinh Thám', 'slug' => 'trinh-tham']);
        $tag = Tag::create(['name' => 'Phá án', 'slug' => 'pha-an']);
        $comic->genres()->attach($genre->id);
        $comic->tags()->attach($tag->id);

        $mockVector = array_fill(0, 768, 0.036);

        $mockService = Mockery::mock(EmbeddingServiceInterface::class);
        $mockService->shouldReceive('embed')
            ->once()
            ->withArgs(function ($corpus) {
                return str_contains($corpus, 'Thám Tử Lừng Danh')
                    && str_contains($corpus, 'Trinh Thám')
                    && str_contains($corpus, 'Phá án')
                    && str_contains($corpus, 'tổ chức áo đen');
            })
            ->andReturn($mockVector);

        $job = new GenerateComicEmbeddingJob($comic->id);
        $job->handle($mockService);

        $comic->refresh();
        $this->assertNotNull($comic->embedding);
        $this->assertCount(768, $comic->embedding);
        $this->assertNotNull($comic->embedding_hash);
        $this->assertEquals(64, strlen($comic->embedding_hash));
    }

    public function test_draft_or_pending_comic_does_not_call_embedding_service(): void
    {
        $comic = $this->createApprovedComic(['approval_status' => 'pending']);

        $mockService = Mockery::mock(EmbeddingServiceInterface::class);
        $mockService->shouldNotReceive('embed');

        $job = new GenerateComicEmbeddingJob($comic->id);
        $job->handle($mockService);

        $comic->refresh();
        $this->assertNull($comic->embedding);
        $this->assertNull($comic->embedding_hash);
    }

    public function test_non_existent_comic_is_handled_safely(): void
    {
        $mockService = Mockery::mock(EmbeddingServiceInterface::class);
        $mockService->shouldNotReceive('embed');

        $job = new GenerateComicEmbeddingJob(999999);
        $job->handle($mockService);

        $this->assertTrue(true); // Exits cleanly without exceptions
    }

    public function test_matching_hash_skips_embedding_service_call(): void
    {
        $comic = $this->createApprovedComic();
        $job = new GenerateComicEmbeddingJob($comic->id);
        $corpus = $job->buildCorpus($comic);
        $hash = hash('sha256', $corpus);

        $comic->embedding = array_fill(0, 768, 0.036);
        $comic->embedding_hash = $hash;
        $comic->save();

        $mockService = Mockery::mock(EmbeddingServiceInterface::class);
        $mockService->shouldNotReceive('embed');

        $job->handle($mockService);

        $this->assertEquals($hash, $comic->fresh()->embedding_hash);
    }

    public function test_transient_error_rethrows_exception_for_queue_retry(): void
    {
        $comic = $this->createApprovedComic();

        $mockService = Mockery::mock(EmbeddingServiceInterface::class);
        $mockService->shouldReceive('embed')
            ->once()
            ->andThrow(new TransientEmbeddingException('Rate limit exceeded (HTTP 429)'));

        $this->expectException(TransientEmbeddingException::class);

        $job = new GenerateComicEmbeddingJob($comic->id);
        $job->handle($mockService);
    }

    public function test_non_retryable_error_fails_job_without_rethrow(): void
    {
        $comic = $this->createApprovedComic();

        $mockService = Mockery::mock(EmbeddingServiceInterface::class);
        $mockService->shouldReceive('embed')
            ->once()
            ->andThrow(new NonRetryableEmbeddingException('Bad Request (HTTP 400)'));

        $job = Mockery::mock(GenerateComicEmbeddingJob::class, [$comic->id])->makePartial();
        $job->shouldReceive('fail')
            ->once()
            ->with(Mockery::type(NonRetryableEmbeddingException::class));

        $job->handle($mockService);

        $comic->refresh();
        $this->assertNull($comic->embedding);
    }

    public function test_lock_failure_releases_job_back_to_queue(): void
    {
        $comic = $this->createApprovedComic();

        // Simulate another process holding the lock
        $lock = Cache::lock("comic_embedding_{$comic->id}", 60);
        $lock->get();

        $mockService = Mockery::mock(EmbeddingServiceInterface::class);
        $mockService->shouldNotReceive('embed');

        $job = Mockery::mock(GenerateComicEmbeddingJob::class, [$comic->id])->makePartial();
        $job->shouldReceive('release')
            ->once()
            ->with(10);

        try {
            $job->handle($mockService);
        } finally {
            $lock->release();
        }
    }

    public function test_stale_vector_discarded_and_new_job_dispatched_if_content_changed_in_flight(): void
    {
        Queue::fake();

        $comic = $this->createApprovedComic(['title' => 'Tên Ban Đầu']);
        $mockVector = array_fill(0, 768, 0.036);

        $mockService = Mockery::mock(EmbeddingServiceInterface::class);
        $mockService->shouldReceive('embed')
            ->once()
            ->andReturnUsing(function () use ($comic, $mockVector) {
                // Simulate content modification during in-flight API call
                $comic->title = 'Tên Đã Bị Thay Đổi Giữa Chừng';
                $comic->save();
                return $mockVector;
            });

        $job = new GenerateComicEmbeddingJob($comic->id);
        $job->handle($mockService);

        // Vector should NOT be written because content changed
        $comic->refresh();
        $this->assertNull($comic->embedding);

        // A new job should have been dispatched for the updated content
        Queue::assertPushed(GenerateComicEmbeddingJob::class, function ($pushedJob) use ($comic) {
            return $pushedJob->comicId === $comic->id;
        });
    }

    public function test_failed_job_does_not_affect_comic_visibility_or_approval_status(): void
    {
        $comic = $this->createApprovedComic();

        $mockService = Mockery::mock(EmbeddingServiceInterface::class);
        $mockService->shouldReceive('embed')
            ->andThrow(new NonRetryableEmbeddingException('Malformed vector response'));

        $job = Mockery::mock(GenerateComicEmbeddingJob::class, [$comic->id])->makePartial();
        $job->shouldReceive('fail')->once();

        $job->handle($mockService);

        $comic->refresh();
        $this->assertEquals('approved', $comic->approval_status);
        $this->assertEquals('ongoing', $comic->status);
        $this->assertTrue(Comic::where('approval_status', 'approved')->where('id', $comic->id)->exists());
    }
}
