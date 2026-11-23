<?php

namespace App\Console\Commands;

use App\Jobs\GenerateComicEmbeddingJob;
use App\Models\Comic;
use Illuminate\Console\Command;

class GenerateComicEmbeddingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'comics:generate-embeddings
                            {--chunk=50 : Number of records to process per chunk}
                            {--force : Force re-generation for all approved comics even if already embedded}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Batch dispatch embedding generation jobs for approved comics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawChunk = $this->option('chunk');
        if (! is_numeric($rawChunk) || (int) $rawChunk <= 0) {
            $this->error('Chunk size must be a positive integer greater than 0.');
            return 1;
        }

        $chunk = (int) $rawChunk;
        $force = (bool) $this->option('force');

        $query = Comic::query()->where('approval_status', 'approved');

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('embedding')
                  ->orWhereNull('embedding_hash');
            });
        }

        $total = $query->count();

        $this->info("Found {$total} approved comics to process.");

        if ($total === 0) {
            $this->info('No comics require embedding generation.');
            return 0;
        }

        $dispatched = 0;

        $query->chunkById($chunk, function ($comics) use (&$dispatched) {
            foreach ($comics as $comic) {
                GenerateComicEmbeddingJob::dispatch($comic->id);
                $dispatched++;
            }
        });

        $this->info("Dispatched {$dispatched} embedding jobs to queue.");

        return 0;
    }
}
