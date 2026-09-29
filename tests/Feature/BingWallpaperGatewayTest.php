<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\BingWallpaper\BingWallpaperGateway;
use Closure;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BingWallpaperGatewayTest extends TestCase
{
    public function test_installed_command_is_registered_and_emits_a_safe_invalid_date_error(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'bing:wallpaper',
            '--date=2026-02-30',
            '--no-ansi',
            '--no-interaction',
        ], base_path());
        $process->setTimeout(10);

        self::assertSame(2, $process->run());
        self::assertSame('', $process->getErrorOutput());

        $document = json_decode($process->getOutput(), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.0', $document->schemaVersion);
        self::assertSame('error', $document->status);
        self::assertSame('INVALID_DATE', $document->error->code);
        self::assertSame('2026-02-30', $document->query->date);
    }

    public function test_gateway_invokes_an_argument_array_and_accepts_valid_output(): void
    {
        $seenCommand = null;
        $output = $this->fixture('found-v1.0.json');
        $factory = static function (array $command) use (&$seenCommand, $output): Process {
            $seenCommand = $command;

            return self::scriptProcess('fwrite(STDOUT, base64_decode('.var_export(base64_encode($output), true).'));');
        };

        $result = (new BingWallpaperGateway($factory))->fetch('2026-08-31');

        self::assertSame([PHP_BINARY, base_path('artisan'), 'bing:wallpaper', '--date=2026-08-31', '--no-ansi', '--no-interaction'], $seenCommand);
        self::assertSame('found', $result->status);
        self::assertSame('https://www.bing.com/th?id=example_1920x1080.jpg', $result->data['imageUrl']);
        self::assertNull($result->failureCategory);
    }

    public function test_gateway_accepts_producer_error_without_reclassifying_it_as_transport_failure(): void
    {
        $result = (new BingWallpaperGateway($this->factoryForOutput($this->fixture('error-v1.0.json'), 1)))->fetch('2026-08-31');

        self::assertSame('error', $result->status);
        self::assertSame('UPSTREAM_UNAVAILABLE', $result->error['code']);
        self::assertNull($result->failureCategory);
    }

    public function test_invalid_request_date_never_launches_a_process(): void
    {
        $factory = static function (): Process {
            self::fail('The process must not launch.');
        };

        $result = (new BingWallpaperGateway($factory))->fetch('2026-02-30');

        self::assertSame('failure', $result->status);
        self::assertSame('invalid_date', $result->failureCategory);
    }

    public function test_stderr_invalidates_an_otherwise_valid_document_without_exposing_it(): void
    {
        $output = $this->fixture('found-v1.0.json');
        $script = 'fwrite(STDOUT, base64_decode('.var_export(base64_encode($output), true).')); fwrite(STDERR, "sensitive diagnostic");';

        $result = (new BingWallpaperGateway(static fn (array $command): Process => self::scriptProcess($script)))->fetch('2026-08-31');

        self::assertSame('stderr_output', $result->failureCategory);
        self::assertNull($result->data);
        self::assertNull($result->rawPayload);
        self::assertNull($result->error);
    }

    public function test_gateway_classifies_malformed_output_and_exit_mismatch(): void
    {
        $malformed = (new BingWallpaperGateway($this->factoryForOutput("{}\n{}\n")))->fetch('2026-08-31');
        $mismatched = (new BingWallpaperGateway($this->factoryForOutput($this->fixture('found-v1.0.json'), 1)))->fetch('2026-08-31');

        self::assertSame('invalid_document', $malformed->failureCategory);
        self::assertSame('invalid_document', $mismatched->failureCategory);
    }

    public function test_gateway_bounds_output_and_classifies_launch_failure_and_timeout(): void
    {
        $tooMuch = (new BingWallpaperGateway(static fn (array $command): Process => self::scriptProcess('fwrite(STDOUT, str_repeat("x", 1048577));')))->fetch('2026-08-31');
        $launchFailure = (new BingWallpaperGateway(static function (array $command): Process {
            throw new \RuntimeException('sensitive process detail');
        }))->fetch('2026-08-31');
        $timeout = (new BingWallpaperGateway(
            static fn (array $command): Process => self::scriptProcess('usleep(500000);'),
            timeoutSeconds: 0.1,
        ))->fetch('2026-08-31');

        self::assertSame('output_limit', $tooMuch->failureCategory);
        self::assertSame('process_failure', $launchFailure->failureCategory);
        self::assertSame('timeout', $timeout->failureCategory);
        self::assertNull($launchFailure->error);
    }

    /** @return Closure(array<int, string>): Process */
    private function factoryForOutput(string $output, int $exitCode = 0): Closure
    {
        $script = 'fwrite(STDOUT, base64_decode('.var_export(base64_encode($output), true).')); exit('.$exitCode.');';

        return static fn (array $command): Process => self::scriptProcess($script);
    }

    private static function scriptProcess(string $script): Process
    {
        return new Process([PHP_BINARY, '-r', $script], base_path());
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Contracts/composer/'.$name));
        self::assertIsString($contents);

        return $contents;
    }
}
