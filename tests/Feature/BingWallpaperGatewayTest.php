<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\BingWallpaper\BingWallpaperGateway;
use Haoyuqi\DownloadBingWallpaper\Contracts\WallpaperMetadataProvider;
use Haoyuqi\DownloadBingWallpaper\Data\MetadataResult;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

final class BingWallpaperGatewayTest extends TestCase
{
    public function test_installed_command_emits_a_safe_invalid_date_error(): void
    {
        self::assertSame(2, Artisan::call('bing:wallpaper', ['--date' => '2026-02-30']));

        $document = json_decode(Artisan::output(), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.0', $document->schemaVersion);
        self::assertSame('error', $document->status);
        self::assertSame('INVALID_DATE', $document->error->code);
    }

    public function test_gateway_calls_the_installed_command_without_network_access(): void
    {
        $fixture = json_decode($this->fixture('found-v1.0.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->mock(WallpaperMetadataProvider::class)
            ->shouldReceive('retrieve')->once()->with('2026-08-31')
            ->andReturn(MetadataResult::found($fixture['data'], $fixture['rawPayload']));

        $result = (new BingWallpaperGateway)->fetch('2026-08-31');

        self::assertSame('found', $result->status);
        self::assertSame($fixture['data'], $result->data);
        self::assertNull($result->failureCategory);
    }

    public function test_gateway_accepts_producer_error(): void
    {
        $this->registerOutput($this->fixture('error-v1.0.json'), 1);
        $result = (new BingWallpaperGateway)->fetch('2026-08-31');

        self::assertSame('error', $result->status);
        self::assertSame('UPSTREAM_UNAVAILABLE', $result->error['code']);
        self::assertNull($result->failureCategory);
    }

    public function test_invalid_date_never_calls_artisan(): void
    {
        Artisan::shouldReceive('call')->never();

        $result = (new BingWallpaperGateway)->fetch('2026-02-30');

        self::assertSame('failure', $result->status);
        self::assertSame('invalid_date', $result->failureCategory);
    }

    public function test_gateway_rejects_diagnostics_without_exposing_them(): void
    {
        $this->registerOutput($this->fixture('found-v1.0.json')."sensitive diagnostic\n");
        $result = (new BingWallpaperGateway)->fetch('2026-08-31');

        self::assertSame('invalid_document', $result->failureCategory);
        self::assertNull($result->data);
        self::assertNull($result->rawPayload);
        self::assertNull($result->error);
    }

    public function test_gateway_rejects_malformed_output_and_exit_mismatch(): void
    {
        $this->registerOutput("{}\n{}\n");
        $malformed = (new BingWallpaperGateway)->fetch('2026-08-31');
        $this->registerOutput($this->fixture('found-v1.0.json'), 1);
        $mismatched = (new BingWallpaperGateway)->fetch('2026-08-31');

        self::assertSame('invalid_document', $malformed->failureCategory);
        self::assertSame('invalid_document', $mismatched->failureCategory);
    }

    public function test_gateway_does_not_reuse_output_from_a_previous_call(): void
    {
        $this->registerOutput($this->fixture('found-v1.0.json'));
        self::assertSame('found', (new BingWallpaperGateway)->fetch('2026-08-31')->status);

        $this->registerOutput('');
        self::assertSame('invalid_document', (new BingWallpaperGateway)->fetch('2026-08-31')->failureCategory);
    }

    public function test_gateway_sanitizes_command_exceptions(): void
    {
        Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException('sensitive command detail'));

        $result = (new BingWallpaperGateway)->fetch('2026-08-31');

        self::assertSame('command_failure', $result->failureCategory);
        self::assertNull($result->error);
        self::assertNull($result->rawPayload);
    }

    private function registerOutput(string $output, int $exitCode = 0): void
    {
        Artisan::registerCommand(new ClosureCommand('bing:wallpaper {--date=}', function () use ($output, $exitCode): int {
            $this->output->write($output, false, OutputInterface::OUTPUT_RAW);

            return $exitCode;
        }));
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Contracts/composer/'.$name));
        self::assertIsString($contents);

        return $contents;
    }
}
