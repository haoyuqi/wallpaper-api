<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('wallpapers', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('wallpapers.requests_per_minute')),
        )->by($request->ip()));
    }
}
