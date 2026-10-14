<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Integrations\Bing\BingWallpaperGateway;
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
            $syncRun = $service->sync($date);
        } catch (Throwable) {
            Log::error('Wallpaper synchronization failed.', [
                'source' => BingWallpaperGateway::SOURCE,
                'market' => BingWallpaperGateway::MARKET,
                'requested_date' => $date,
                'error_code' => 'AUDIT_UNAVAILABLE',
                'sync_run_id' => null,
            ]);
            $this->error('Synchronization failed; the attempt could not be recorded.');

            return self::FAILURE;
        }

        if ($syncRun->status === 'failed') {
            Log::error('Wallpaper synchronization failed.', [
                'source' => $syncRun->source,
                'market' => $syncRun->market,
                'requested_date' => $date,
                'error_code' => $syncRun->error_code,
                'sync_run_id' => $syncRun->id,
            ]);
            $this->error("Synchronization failed ({$syncRun->error_code}); sync run: {$syncRun->id}.");

            return self::FAILURE;
        }

        $this->info("Synchronization {$syncRun->status}; sync run: {$syncRun->id}.");

        return self::SUCCESS;
    }
}
