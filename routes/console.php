<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// File locks work across processes on the single scheduler host without cache tables.
Schedule::useCache('file');

Schedule::call(fn (): bool => Artisan::call('wallpapers:sync', [
    '--date' => CarbonImmutable::now('UTC')->subDay()->toDateString(),
]) === Command::SUCCESS)
    ->name('wallpapers:sync-daily')
    ->dailyAt('12:00')
    ->timezone('UTC')
    ->withoutOverlapping();
