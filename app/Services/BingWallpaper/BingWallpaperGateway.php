<?php

declare(strict_types=1);

namespace App\Services\BingWallpaper;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;
use UnexpectedValueException;

final class BingWallpaperGateway
{
    public function fetch(string $date): BingWallpaperResult
    {
        if (! ComposerDocumentValidator::isCanonicalDate($date)) {
            return BingWallpaperResult::failed($date, 'invalid_date');
        }

        $output = new BufferedOutput;

        try {
            $exitCode = Artisan::call('bing:wallpaper', [
                '--date' => $date,
                '--no-ansi' => true,
                '--no-interaction' => true,
            ], $output);
        } catch (Throwable) {
            return BingWallpaperResult::failed($date, 'command_failure');
        }

        try {
            return (new ComposerDocumentValidator)->validate($output->fetch(), $exitCode, $date);
        } catch (UnexpectedValueException) {
            return BingWallpaperResult::failed($date, 'invalid_document');
        }
    }
}
