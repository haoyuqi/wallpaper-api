<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BingWallpaper\ComposerDocumentValidator;
use App\Services\BingWallpaper\WallpaperSyncService;
use Illuminate\Console\Command;
use Throwable;

final class SyncWallpapers extends Command
{
    protected $signature = 'wallpapers:sync {--date= : Date to synchronize in YYYY-MM-DD format}';

    protected $description = 'Synchronize Bing wallpaper metadata and record the attempt';

    public function handle(WallpaperSyncService $service): int
    {
        $date = $this->option('date');

        if (! is_string($date) || ! ComposerDocumentValidator::isCanonicalDate($date)) {
            $this->error('The --date option must be a real date in YYYY-MM-DD format.');

            return self::INVALID;
        }

        try {
            $run = $service->sync($date);
        } catch (Throwable) {
            $this->error('Synchronization failed; the attempt could not be recorded.');

            return self::FAILURE;
        }

        if ($run->status === 'failed') {
            $this->error("Synchronization failed ({$run->error_code}); sync run: {$run->id}.");

            return self::FAILURE;
        }

        $this->info("Synchronization {$run->status}; sync run: {$run->id}.");

        return self::SUCCESS;
    }
}
