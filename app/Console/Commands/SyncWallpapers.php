<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WallpaperSyncService;
use App\Support\CalendarDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncWallpapers extends Command
{
    protected $signature = 'wallpapers:sync {--date= : Date to synchronize in YYYY-MM-DD format}';

    protected $description = 'Synchronize Bing wallpaper metadata and record the attempt';

    public function handle(WallpaperSyncService $service): int
    {
        $date = $this->option('date');

        if (! CalendarDate::isCanonical($date)) {
            $this->error('The --date option must be a real date in YYYY-MM-DD format.');

            return self::INVALID;
        }

        try {
            $run = $service->sync($date);
        } catch (Throwable) {
            Log::error('Wallpaper synchronization failed.', [
                'source' => 'bing',
                'market' => 'en-US',
                'requested_date' => $date,
                'error_code' => 'AUDIT_UNAVAILABLE',
                'sync_run_id' => null,
            ]);
            $this->error('Synchronization failed; the attempt could not be recorded.');

            return self::FAILURE;
        }

        if ($run->status === 'failed') {
            Log::error('Wallpaper synchronization failed.', [
                'source' => $run->source,
                'market' => $run->market,
                'requested_date' => $date,
                'error_code' => $run->error_code,
                'sync_run_id' => $run->id,
            ]);
            $this->error("Synchronization failed ({$run->error_code}); sync run: {$run->id}.");

            return self::FAILURE;
        }

        $this->info("Synchronization {$run->status}; sync run: {$run->id}.");

        return self::SUCCESS;
    }
}
