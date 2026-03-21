<?php

namespace App\Providers;

use App\Models\Director;
use App\Models\Carrera;
use App\Observers\DirectorObserver;
use App\Observers\CarreraObserver;
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
        Director::observe(DirectorObserver::class);
        Carrera::observe(CarreraObserver::class);
    }
}
