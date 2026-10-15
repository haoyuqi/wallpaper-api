<?php

declare(strict_types=1);

use App\Http\Controllers\WallpaperController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:wallpapers')->group(function (): void {
    Route::get('/wallpapers', WallpaperController::class);
});
