<?php

namespace App\Providers;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Policies\ChapterPolicy;
use App\Policies\ComicPolicy;
use App\Policies\CommentPolicy;
use App\Policies\PostPolicy;
use App\Policies\ReportPolicy;
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
        //
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

        Relation::morphMap([
            'post' => Post::class,
            'comment' => Comment::class,
            'comic' => Comic::class,
            'chapter' => Chapter::class,
            'user' => User::class,
        ]);
    }
}
