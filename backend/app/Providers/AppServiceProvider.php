<?php

namespace App\Providers;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Policies\ChapterPolicy;
use App\Policies\ComicPolicy;
use App\Policies\CommentPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\PostPolicy;
use App\Policies\ReportPolicy;
use App\Services\AiSearchService;
use App\Services\ChapterUnlockService;
use App\Services\ComicPurchaseService;
use App\Services\Contracts\AiSearchServiceInterface;
use App\Services\Contracts\ChapterUnlockServiceInterface;
use App\Services\Contracts\ComicPurchaseServiceInterface;
use App\Services\Contracts\EmbeddingServiceInterface;
use App\Services\Contracts\WalletServiceInterface;
use App\Services\FallbackSearchService;
use App\Services\GeminiEmbeddingService;
use App\Services\GeminiQueryUnderstandingService;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FallbackSearchService::class, function () {
            return new FallbackSearchService();
        });

        $this->app->singleton(AiSearchServiceInterface::class, function ($app) {
            return new AiSearchService(
                $app->make(FallbackSearchService::class),
                $app->make(EmbeddingServiceInterface::class)
            );
        });

        $this->app->singleton(GeminiQueryUnderstandingService::class, function () {
            return new GeminiQueryUnderstandingService();
        });

        $this->app->singleton(GeminiEmbeddingService::class, function () {
            return new GeminiEmbeddingService();
        });

        $this->app->singleton(EmbeddingServiceInterface::class, function ($app) {
            return $app->make(GeminiEmbeddingService::class);
        });

        $this->app->singleton(WalletServiceInterface::class, function () {
            return new WalletService();
        });

        $this->app->singleton(ChapterUnlockServiceInterface::class, function ($app) {
            return new ChapterUnlockService($app->make(WalletServiceInterface::class));
        });

        $this->app->singleton(ComicPurchaseServiceInterface::class, function ($app) {
            return new ComicPurchaseService($app->make(WalletServiceInterface::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Comic::class, ComicPolicy::class);
        Gate::policy(Chapter::class, ChapterPolicy::class);
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(Comment::class, CommentPolicy::class);
        Gate::policy(Report::class, ReportPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);

        Relation::morphMap([
            'post' => Post::class,
            'comment' => Comment::class,
            'comic' => Comic::class,
            'chapter' => Chapter::class,
            'user' => User::class,
        ]);
    }
}
