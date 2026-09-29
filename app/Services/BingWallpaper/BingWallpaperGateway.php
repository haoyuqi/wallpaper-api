<?php

declare(strict_types=1);

namespace App\Services\BingWallpaper;

use Closure;
use LengthException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;
use UnexpectedValueException;

final class BingWallpaperGateway
{
    private const MAX_STDOUT_BYTES = 1048576;

    private const MAX_STDERR_BYTES = 65536;

    /** @param (Closure(array<int, string>): Process)|null $processFactory */
    public function __construct(
        private readonly ?Closure $processFactory = null,
        private readonly ComposerDocumentValidator $validator = new ComposerDocumentValidator,
        private readonly float $timeoutSeconds = 10.0,
    ) {}

    public function fetch(string $date): BingWallpaperResult
    {
        if (! ComposerDocumentValidator::isCanonicalDate($date)) {
            return BingWallpaperResult::failed($date, 'invalid_date');
        }

        $command = [PHP_BINARY, base_path('artisan'), 'bing:wallpaper', '--date='.$date, '--no-ansi', '--no-interaction'];
        $process = null;
        $stdout = '';
        $stderrBytes = 0;

        try {
            $process = $this->processFactory === null
                ? new Process($command, base_path())
                : ($this->processFactory)($command);
            $process->setTimeout($this->timeoutSeconds);
            $process->disableOutput();
            $exitCode = $process->run(static function (string $type, string $chunk) use (&$stdout, &$stderrBytes): void {
                if ($type === Process::OUT) {
                    if (strlen($chunk) > self::MAX_STDOUT_BYTES - strlen($stdout)) {
                        throw new LengthException('Process stdout exceeds the limit.');
                    }

                    $stdout .= $chunk;
                } else {
                    if (strlen($chunk) > self::MAX_STDERR_BYTES - $stderrBytes) {
                        throw new LengthException('Process stderr exceeds the limit.');
                    }

                    $stderrBytes += strlen($chunk);
                }
            });
        } catch (ProcessTimedOutException) {
            return BingWallpaperResult::failed($date, 'timeout');
        } catch (LengthException) {
            if ($process?->isRunning()) {
                $process->stop(0);
            }

            return BingWallpaperResult::failed($date, 'output_limit');
        } catch (Throwable) {
            if ($process?->isRunning()) {
                $process->stop(0);
            }

            return BingWallpaperResult::failed($date, 'process_failure');
        }

        if ($stderrBytes !== 0) {
            return BingWallpaperResult::failed($date, 'stderr_output');
        }

        try {
            return $this->validator->validate($stdout, $exitCode, $date);
        } catch (UnexpectedValueException) {
            return BingWallpaperResult::failed($date, 'invalid_document');
        }
    }
}
