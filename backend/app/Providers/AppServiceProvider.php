<?php

namespace App\Providers;

use App\Models\Chapter;
use App\Models\Comic;
use App\Policies\ChapterPolicy;
use App\Policies\ComicPolicy;
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
    }
}
