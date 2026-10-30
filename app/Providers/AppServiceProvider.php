<?php

namespace App\Providers;

use App\Models\JournalLine;
use App\Observers\JournalLineObserver;
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
        JournalLine::observe(JournalLineObserver::class);
    }
}
