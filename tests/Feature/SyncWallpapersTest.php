<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SyncRun;
use App\Models\Wallpaper;
use Haoyuqi\DownloadBingWallpaper\Contracts\WallpaperMetadataProvider;
use Haoyuqi\DownloadBingWallpaper\Data\MetadataResult;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

final class SyncWallpapersTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_runs_the_real_package_command_and_persists_metadata(): void
    {
        $document = $this->document();
        $data = (array) $document->data;
        $this->mock(WallpaperMetadataProvider::class)
            ->shouldReceive('retrieve')->once()->with('2026-08-31')
            ->andReturn(MetadataResult::found($data, $document->rawPayload));

        [$exitCode, $output] = $this->sync();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Synchronization success;', $output);
        $wallpaper = Wallpaper::query()->sole();
        self::assertTrue(Str::isUlid($wallpaper->id));
        self::assertSame('bing', $wallpaper->source);
        self::assertSame('en-US', $wallpaper->market);
        self::assertSame($data['sourceItemId'], $wallpaper->source_item_id);
        self::assertSame('2026-08-31', $wallpaper->source_date->toDateString());
        self::assertSame($data['title'], $wallpaper->title);
        self::assertSame($data['copyright'], $wallpaper->copyright_text);
        self::assertSame($data['copyrightLink'], $wallpaper->copyright_link);
        self::assertSame($data['imageUrl'], $wallpaper->image_url);
        self::assertSame($data['downloadUrl'], $wallpaper->download_url);
        self::assertEquals($document->rawPayload, json_decode($wallpaper->raw_payload, false, 512, JSON_THROW_ON_ERROR));
        $run = SyncRun::query()->sole();
        self::assertSame('success', $run->status);
        self::assertSame('2026-08-31', $run->requested_date->toDateString());
        self::assertNotNull($run->finished_at);
        self::assertGreaterThanOrEqual($run->started_at, $run->finished_at);
        self::assertNull($run->error_code);
    }

    public function test_repeated_sync_updates_metadata_without_changing_identity(): void
    {
        $document = $this->document();
        $this->registerDocument($document);
        self::assertSame(0, $this->sync()[0]);
        $original = Wallpaper::query()->sole();

        $document->data->title = 'Updated title';
        $document->retrievedAt = '2026-09-01T01:02:03Z';
        $document->schemaVersion = '1.1';
        $document->optionalField = (object) ['future' => true];
        $this->registerDocument($document);
        self::assertSame(0, $this->sync()[0]);

        $updated = Wallpaper::query()->sole();
        self::assertSame($original->id, $updated->id);
        self::assertEquals($original->created_at, $updated->created_at);
        self::assertSame('Updated title', $updated->title);
        self::assertSame('1.1', $updated->schema_version);
        self::assertSame('2026-09-01T01:02:03.000000Z', $updated->retrieved_at->toISOString());
        self::assertSame(2, SyncRun::query()->count());
        $run = SyncRun::query()->where('schema_version', '1.1')->sole();
        self::assertEquals($document, json_decode($run->response_document, false, 512, JSON_THROW_ON_ERROR));
    }

    public function test_deduplication_uses_the_complete_source_identity(): void
    {
        $document = $this->document();
        $this->registerDocument($document);
        self::assertSame(0, $this->sync()[0]);
        $document->data->sourceItemId = 'another-item';
        $this->registerDocument($document);
        self::assertSame(0, $this->sync()[0]);
        $document->query->date = '2026-08-30';
        $document->data->sourceDate = '2026-08-30';
        $this->registerDocument($document);
        self::assertSame(0, $this->sync('2026-08-30')[0]);

        self::assertSame(3, Wallpaper::query()->count());
        self::assertSame(3, SyncRun::query()->count());
    }

    public function test_not_found_records_an_attempt_without_creating_a_wallpaper(): void
    {
        $document = $this->document('not-found-v1.0.json');
        $this->registerDocument($document);
        self::assertSame(0, $this->sync('1900-01-01')[0]);

        self::assertSame(0, Wallpaper::query()->count());
        $run = SyncRun::query()->sole();
        self::assertSame('not_found', $run->status);
        self::assertNull($run->error_code);
        self::assertEquals($document, json_decode($run->response_document, false, 512, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('validDates')]
    public function test_valid_dates_have_no_product_level_range_limit(string $date): void
    {
        $document = $this->document('not-found-v1.0.json');
        $document->query->date = $date;
        $this->registerDocument($document);

        self::assertSame(0, $this->sync($date)[0]);
        self::assertSame($date, SyncRun::query()->sole()->requested_date->toDateString());
    }

    public static function validDates(): iterable
    {
        yield 'first supported year' => ['0001-01-01'];
        yield 'last supported year' => ['9999-12-31'];
        yield 'leap day' => ['2000-02-29'];
    }

    public function test_not_found_does_not_delete_an_existing_record(): void
    {
        $wallpaper = Wallpaper::factory()->create(['source_date' => '1900-01-01']);
        $this->registerDocument($this->document('not-found-v1.0.json'));

        self::assertSame(0, $this->sync('1900-01-01')[0]);
        self::assertSame($wallpaper->id, Wallpaper::query()->sole()->id);
        self::assertSame('not_found', SyncRun::query()->sole()->status);
    }

    public function test_source_errors_are_audited_with_safe_diagnostics(): void
    {
        $document = $this->document('error-v1.0.json');
        $document->error->message = 'sensitive diagnostic';
        $this->registerDocument($document, 1);
        [$exitCode, $output] = $this->sync();

        self::assertSame(1, $exitCode);
        self::assertStringNotContainsString('sensitive diagnostic', $output);
        self::assertSame(0, Wallpaper::query()->count());
        $run = SyncRun::query()->sole();
        self::assertSame('failed', $run->status);
        self::assertSame('SOURCE_ERROR', $run->error_code);
        self::assertSame('The wallpaper source returned an error.', $run->error_message);
        self::assertEquals($document, json_decode($run->response_document, false, 512, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('invalidDocuments')]
    public function test_invalid_documents_are_not_persisted(string $fixture, int $exitCode): void
    {
        $this->registerDocument($this->document($fixture), $exitCode);
        self::assertSame(1, $this->sync()[0]);

        self::assertSame(0, Wallpaper::query()->count());
        $run = SyncRun::query()->sole();
        self::assertSame('failed', $run->status);
        self::assertSame('INVALID_DOCUMENT', $run->error_code);
        self::assertNull($run->schema_version);
        self::assertNull($run->response_document);
    }

    public static function invalidDocuments(): iterable
    {
        yield 'unsupported version' => ['unsupported-v2.0.json', 0];
        yield 'exit mismatch' => ['found-v1.0.json', 1];
    }

    public function test_command_exceptions_are_audited_without_leaking_details(): void
    {
        Artisan::registerCommand(new ClosureCommand('bing:wallpaper {--date=}', function (): int {
            throw new RuntimeException('sensitive diagnostic');
        }));
        [$exitCode, $output] = $this->sync();

        self::assertSame(1, $exitCode);
        self::assertStringNotContainsString('sensitive diagnostic', $output);
        self::assertSame('COMMAND_FAILURE', SyncRun::query()->sole()->error_code);
        self::assertSame(0, Wallpaper::query()->count());
    }

    public function test_success_audit_failure_rolls_back_wallpaper_and_records_failure(): void
    {
        SyncRun::creating(function (SyncRun $run): void {
            if ($run->status === 'success') {
                throw new RuntimeException('sensitive database details');
            }
        });
        $this->registerDocument($this->document());
        [$exitCode, $output] = $this->sync();

        self::assertSame(1, $exitCode);
        self::assertStringNotContainsString('sensitive database details', $output);
        self::assertSame(0, Wallpaper::query()->count());
        $run = SyncRun::query()->sole();
        self::assertSame('failed', $run->status);
        self::assertSame('PERSISTENCE_FAILURE', $run->error_code);
        self::assertNotNull($run->finished_at);
        self::assertNull($run->response_document);
    }

    public function test_failed_update_preserves_the_existing_wallpaper(): void
    {
        $document = $this->document();
        $this->registerDocument($document);
        self::assertSame(0, $this->sync()[0]);
        $original = Wallpaper::query()->sole();
        SyncRun::creating(function (SyncRun $run): void {
            if ($run->status === 'success') {
                throw new RuntimeException('failure after upsert');
            }
        });
        $document->data->title = 'Must roll back';
        $this->registerDocument($document);
        self::assertSame(1, $this->sync()[0]);

        self::assertSame($original->id, Wallpaper::query()->sole()->id);
        self::assertSame($original->title, $original->fresh()->title);
        self::assertSame(2, SyncRun::query()->count());
        self::assertSame(1, SyncRun::query()->where('status', 'failed')->count());
    }

    public function test_unavailable_audit_storage_returns_a_safe_failure(): void
    {
        SyncRun::creating(function (): void {
            throw new RuntimeException('sensitive database details');
        });
        $this->registerDocument($this->document());
        [$exitCode, $output] = $this->sync();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('the attempt could not be recorded', $output);
        self::assertStringNotContainsString('sensitive database details', $output);
        self::assertSame(0, Wallpaper::query()->count());
        self::assertSame(0, SyncRun::query()->count());
    }

    public function test_postgresql_write_failure_rolls_back_and_records_safe_failure(): void
    {
        if (Wallpaper::query()->getConnection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL enforces the VARCHAR length constraint.');
        }

        $document = $this->document();
        $document->data->sourceItemId = str_repeat('x', 256);
        $this->registerDocument($document);
        [$exitCode, $output] = $this->sync();

        self::assertSame(1, $exitCode);
        self::assertSame(0, Wallpaper::query()->count());
        self::assertSame('PERSISTENCE_FAILURE', SyncRun::query()->sole()->error_code);
        self::assertStringNotContainsString('SQLSTATE', $output);
    }

    public function test_postgresql_failure_audit_does_not_repeat_an_unstorable_schema_version(): void
    {
        if (Wallpaper::query()->getConnection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL enforces the VARCHAR length constraint.');
        }

        $document = $this->document();
        $document->schemaVersion = '1.'.str_repeat('1', 254);
        $this->registerDocument($document);

        self::assertSame(1, $this->sync()[0]);
        self::assertSame(0, Wallpaper::query()->count());
        $run = SyncRun::query()->sole();
        self::assertSame('PERSISTENCE_FAILURE', $run->error_code);
        self::assertNull($run->schema_version);
        self::assertNull($run->response_document);
    }

    public function test_raw_json_value_types_survive_ingestion(): void
    {
        foreach (['{}', '[]', 'null', '42', '"text"'] as $json) {
            $document = $this->document();
            $document->rawPayload = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $this->registerDocument($document);
            self::assertSame(0, $this->sync()[0]);

            $stored = json_decode(Wallpaper::query()->sole()->raw_payload, false, 512, JSON_THROW_ON_ERROR);
            self::assertEquals($document->rawPayload, $stored);
            self::assertSame(get_debug_type($document->rawPayload), get_debug_type($stored));
        }
        self::assertSame(5, SyncRun::query()->count());
    }

    #[DataProvider('unpersistableDocuments')]
    public function test_postgresql_failure_audit_does_not_repeat_unpersistable_json(string $fixture, int $producerExitCode): void
    {
        if (Wallpaper::query()->getConnection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL JSONB rejects escaped null characters.');
        }

        $document = $this->document($fixture);
        $document->rawPayload = (object) ['note' => "sensitive\0diagnostic"];
        $this->registerDocument($document, $producerExitCode);
        [$exitCode, $output] = $this->sync($document->query->date);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('PERSISTENCE_FAILURE', $output);
        self::assertStringNotContainsString('SQLSTATE', $output);
        self::assertStringNotContainsString('sensitive', $output);
        self::assertSame(0, Wallpaper::query()->count());
        $run = SyncRun::query()->sole();
        self::assertSame('failed', $run->status);
        self::assertSame('PERSISTENCE_FAILURE', $run->error_code);
        self::assertSame($document->query->date, $run->requested_date->toDateString());
        self::assertNull($run->schema_version);
        self::assertNull($run->response_document);
        self::assertNotNull($run->finished_at);
    }

    public static function unpersistableDocuments(): iterable
    {
        yield 'found' => ['found-v1.0.json', 0];
        yield 'not found' => ['not-found-v1.0.json', 0];
        yield 'source error' => ['error-v1.0.json', 1];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_input_does_not_fetch_or_write(?string $date): void
    {
        $this->mock(WallpaperMetadataProvider::class)->shouldNotReceive('retrieve');
        [$exitCode, $output] = $this->sync($date);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('real date in YYYY-MM-DD format', $output);
        self::assertSame(0, Wallpaper::query()->count());
        self::assertSame(0, SyncRun::query()->count());
    }

    public static function invalidDates(): iterable
    {
        yield 'missing' => [null];
        yield 'impossible date' => ['2026-02-30'];
        yield 'wrong format' => ['2026-8-31'];
        yield 'zero year' => ['0000-01-01'];
    }

    private function sync(?string $date = '2026-08-31'): array
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('wallpapers:sync', $date === null ? [] : ['--date' => $date], $output);

        return [$exitCode, $output->fetch()];
    }

    private function registerDocument(object $document, int $exitCode = 0): void
    {
        $json = json_encode($document, JSON_THROW_ON_ERROR)."\n";
        Artisan::registerCommand(new ClosureCommand('bing:wallpaper {--date=}', function () use ($json, $exitCode): int {
            $this->output->write($json, false, OutputInterface::OUTPUT_RAW);

            return $exitCode;
        }));
    }

    private function document(string $fixture = 'found-v1.0.json'): object
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Contracts/composer/'.$fixture)), false, 512, JSON_THROW_ON_ERROR);
    }
}
