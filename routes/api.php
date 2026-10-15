<?php

declare(strict_types=1);

use App\Http\Controllers\WallpaperController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/wallpapers', WallpaperController::class)->middleware('throttle:wallpapers');
