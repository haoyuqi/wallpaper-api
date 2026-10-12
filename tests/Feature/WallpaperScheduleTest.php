<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SyncRun;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Cache\FileStore;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallpaperScheduleTest extends TestCase
{
    use RefreshDatabase;

    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDirectory = sys_get_temp_dir().'/wallpaper-schedule-'.Str::uuid();
        config([
            'cache.stores.file.path' => $this->cacheDirectory,
            'cache.stores.file.lock_path' => $this->cacheDirectory,
        ]);
        Cache::forgetDriver('file');
        $this->app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        (new Filesystem)->deleteDirectory($this->cacheDirectory);
        parent::tearDown();
    }

    public function test_exactly_one_daily_wallpaper_event_is_listed(): void
    {
        $event = $this->event();
        self::assertSame('0 12 * * *', $event->expression);
        self::assertSame('UTC', $event->timezone);
        self::assertTrue($event->withoutOverlapping);
        self::assertSame(1440, $event->expiresAt);
        $this->artisan('schedule:list')->assertSuccessful()->expectsOutputToContain('wallpapers:sync-daily');
    }

    #[DataProvider('calendarBoundaries')]
    public function test_date_is_computed_at_execution_in_utc(string $instant, string $expected): void
    {
        config(['app.timezone' => 'Pacific/Auckland']);
        $event = $this->event();
        $date = null;
        Artisan::registerCommand(new ClosureCommand('wallpapers:sync {--date=}', function () use (&$date): int {
            $date = $this->option('date');

            return 0;
        }));
        $this->freezeAt($instant);
        self::assertTrue($event->isDue($this->app));
        $event->run($this->app);
        self::assertSame($expected, $date);
        self::assertSame(0, $event->exitCode);
    }

    public static function calendarBoundaries(): iterable
    {
        yield 'new year with timezone offset' => ['2027-01-02T01:00:00+13:00', '2026-12-31'];
        yield 'leap day' => ['2028-03-01T12:00:00Z', '2028-02-29'];
        yield 'US DST starts' => ['2026-03-08T12:00:00Z', '2026-03-07'];
        yield 'US DST ends' => ['2026-11-01T12:00:00Z', '2026-10-31'];
    }

    public function test_task_is_not_due_outside_utc_noon(): void
    {
        $this->freezeAt('2026-10-05T11:59:00Z');
        self::assertFalse($this->event()->isDue($this->app));
        $this->freezeAt('2026-10-05T12:01:00Z');
        self::assertFalse($this->event()->isDue($this->app));
    }

    public function test_independent_file_lock_skips_execution_without_releasing_owner_lock(): void
    {
        $event = $this->event();
        $store = new FileStore(new Filesystem, $this->cacheDirectory);
        $lock = $store->lock($event->mutexName(), 60);
        self::assertTrue($lock->get());
        $called = false;
        Artisan::registerCommand(new ClosureCommand('wallpapers:sync {--date=}', function () use (&$called): int {
            $called = true;

            return 0;
        }));

        $event->run($this->app);

        self::assertFalse($called);
        self::assertTrue($event->skippedBecauseOverlapping);
        self::assertFalse($store->lock($event->mutexName(), 60)->get());
        self::assertTrue($lock->release());
    }

    #[DataProvider('commandExitCodes')]
    public function test_mutex_is_released_and_exit_status_is_preserved(int $exitCode): void
    {
        $event = $this->event();
        $failed = false;
        $event->onFailure(function () use (&$failed): void {
            $failed = true;
        });
        Artisan::registerCommand(new ClosureCommand('wallpapers:sync {--date=}', fn (): int => $exitCode));

        $event->run($this->app);

        self::assertSame($exitCode, $event->exitCode);
        self::assertSame($exitCode !== 0, $failed);
        $store = new FileStore(new Filesystem, $this->cacheDirectory);
        $lock = $store->lock($event->mutexName(), 60);
        self::assertTrue($lock->get());
        self::assertTrue($lock->release());
    }

    public static function commandExitCodes(): iterable
    {
        yield 'success' => [0];
        yield 'failure' => [1];
    }

    public function test_scheduled_source_failure_has_a_correlated_audit_and_safe_log(): void
    {
        $this->freezeAt('2026-09-01T12:00:00Z');
        Log::spy();
        $json = file_get_contents(base_path('tests/Fixtures/Contracts/composer/error-v1.0.json'));
        Artisan::registerCommand(new ClosureCommand('bing:wallpaper {--date=}', function () use ($json): int {
            $this->output->write($json);

            return 1;
        }));

        $event = $this->event();
        $event->run($this->app);

        self::assertSame(1, $event->exitCode);
        $run = SyncRun::query()->sole();
        self::assertSame('failed', $run->status);
        self::assertSame('2026-08-31', $run->requested_date->toDateString());
        Log::shouldHaveReceived('error')->once()->with('Wallpaper synchronization failed.', [
            'source' => 'bing',
            'market' => 'en-US',
            'requested_date' => '2026-08-31',
            'error_code' => 'SOURCE_ERROR',
            'sync_run_id' => $run->id,
        ]);
    }

    private function event(): CallbackEvent
    {
        $events = $this->app->make(Schedule::class)->events();
        self::assertCount(1, $events);
        self::assertInstanceOf(CallbackEvent::class, $events[0]);

        return $events[0];
    }

    private function freezeAt(string $instant): void
    {
        $now = CarbonImmutable::parse($instant);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }
}
