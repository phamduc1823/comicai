<?php

namespace App\Jobs;

use App\Exceptions\NonRetryableEmbeddingException;
use App\Exceptions\TransientEmbeddingException;
use App\Models\Comic;
use App\Services\Contracts\EmbeddingServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateComicEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $comicId;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(int $comicId)
    {
        $this->comicId = $comicId;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    /**
     * Execute the job.
     */
    public function handle(EmbeddingServiceInterface $embeddingService): void
    {
        // 1. Fetch comic: strictly approved
        $comic = Comic::with(['genres', 'tags'])
            ->where('id', $this->comicId)
            ->where('approval_status', 'approved')
            ->first();

        if (! $comic) {
            Log::info("GenerateComicEmbeddingJob: Comic {$this->comicId} not found or not approved. Skipping.");
            return;
        }

        // 2. Check if AI embedding is enabled per CAI-002F and config/ai.php
        if (! config('ai.enabled', false)) {
            Log::info("GenerateComicEmbeddingJob: AI embedding is disabled by configuration (ai.enabled=false). Skipping.");
            return;
        }

        // 2. Build corpus from approved data ONLY (never pending_changes)
        $corpus = $this->buildCorpus($comic);
        if (empty(trim($corpus))) {
            Log::info("GenerateComicEmbeddingJob: Comic {$this->comicId} has empty corpus. Skipping.");
            return;
        }

        // 3. Compute SHA-256 hash from the exact corpus to be embedded
        $hash = hash('sha256', $corpus);

        // 4. Idempotency check: if embedding exists and hash matches, skip
        if ($comic->embedding_hash === $hash && ! empty($comic->embedding)) {
            Log::info("GenerateComicEmbeddingJob: Comic {$this->comicId} embedding is already up to date. Skipping.");
            return;
        }

        // 5. Concurrency lock to prevent duplicate concurrent executions
        $lock = Cache::lock("comic_embedding_{$this->comicId}", 60);
        if (! $lock->get()) {
            Log::warning("GenerateComicEmbeddingJob: Lock already held for Comic {$this->comicId}. Releasing back to queue.");
            $this->release(10);
            return;
        }

        try {
            // 6. Call embedding service
            $vector = $embeddingService->embed($corpus);

            if ($vector === null) {
                Log::info("GenerateComicEmbeddingJob: Embedding service returned null (unconfigured/empty). Skipping update.");
                return;
            }

            // 7. Verify comic content did not change during in-flight API call
            $freshComic = Comic::with(['genres', 'tags'])
                ->where('id', $this->comicId)
                ->where('approval_status', 'approved')
                ->first();

            if (! $freshComic) {
                Log::warning("GenerateComicEmbeddingJob: Comic {$this->comicId} unapproved or deleted while embedding was in-flight. Discarding vector.");
                return;
            }

            $freshCorpus = $this->buildCorpus($freshComic);
            $freshHash = hash('sha256', $freshCorpus);

            if ($freshHash !== $hash) {
                Log::warning("GenerateComicEmbeddingJob: Comic {$this->comicId} content changed during API call. Discarding stale vector and dispatching new job.");
                self::dispatch($this->comicId);
                return;
            }

            // 8. Atomic update: save vector and hash together
            $freshComic->embedding = $vector;
            $freshComic->embedding_hash = $hash;
            $freshComic->save();

            Log::info("GenerateComicEmbeddingJob: Successfully updated embedding for Comic {$this->comicId}.");

        } catch (TransientEmbeddingException $e) {
            Log::warning("GenerateComicEmbeddingJob: Transient error for Comic {$this->comicId} (attempt {$this->attempts()}): {$e->getMessage()}");
            throw $e;
        } catch (NonRetryableEmbeddingException $e) {
            Log::error("GenerateComicEmbeddingJob: Permanent error for Comic {$this->comicId}: {$e->getMessage()}. Failing job.");
            $this->fail($e);
        } finally {
            $lock->release();
        }
    }

    /**
     * Build corpus text strictly from approved comic data.
     */
    public function buildCorpus(Comic $comic): string
    {
        $genres = $comic->genres ? $comic->genres->pluck('name')->filter()->implode(', ') : '';
        $tags = $comic->tags ? $comic->tags->pluck('name')->filter()->implode(', ') : '';

        $parts = [
            "Tiêu đề: {$comic->title}",
            ! empty($comic->alternative_titles) ? "Tên khác: {$comic->alternative_titles}" : null,
            "Tác giả: {$comic->author_name}",
            ! empty($genres) ? "Thể loại: {$genres}" : null,
            ! empty($tags) ? "Đặc điểm: {$tags}" : null,
            ! empty($comic->description) ? "Cốt truyện: {$comic->description}" : null,
            ! empty($comic->ai_summary) ? "Bối cảnh & sự kiện: {$comic->ai_summary}" : null,
        ];

        return implode("\n", array_filter($parts));
    }
}
