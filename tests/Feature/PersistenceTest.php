<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SyncRun;
use App\Models\Wallpaper;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_dates_are_stored_without_time_or_timezone_conversion(): void
    {
        $date = CarbonImmutable::parse('2026-08-31 00:30:00', 'Pacific/Auckland');
        $wallpaper = Wallpaper::factory()->create(['source_date' => $date]);
        $run = SyncRun::factory()->create(['requested_date' => $date]);

        self::assertSame('2026-08-31', DB::table('wallpapers')->where('id', $wallpaper->id)->value('source_date'));
        self::assertSame('2026-08-31', DB::table('sync_runs')->where('id', $run->id)->value('requested_date'));
    }

    public function test_wallpaper_factory_persists_metadata_and_raw_json_types(): void
    {
        foreach (['{}', '[]', 'null', '42', '"text"'] as $payload) {
            $wallpaper = Wallpaper::factory()->create([
                'source_date' => '2026-08-31',
                'raw_payload' => $payload,
            ]);

            self::assertTrue(Str::isUlid($wallpaper->id));
            self::assertSame('2026-08-31', $wallpaper->fresh()->source_date->toDateString());
            self::assertEquals(json_decode($payload, false, 512, JSON_THROW_ON_ERROR), json_decode($wallpaper->fresh()->raw_payload, false, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function test_wallpaper_source_identity_is_unique(): void
    {
        $wallpaper = Wallpaper::factory()->create([
            'source_item_id' => 'same-item',
            'source_date' => '2026-08-31',
        ]);

        $this->expectException(QueryException::class);

        Wallpaper::factory()->create([
            'source' => $wallpaper->source,
            'market' => $wallpaper->market,
            'source_date' => $wallpaper->source_date,
            'source_item_id' => $wallpaper->source_item_id,
        ]);
    }

    public function test_sync_runs_keep_each_attempt_and_distinguish_missing_document_from_json_null(): void
    {
        $missing = SyncRun::factory()->create([
            'requested_date' => '2026-08-31',
            'status' => 'not_found',
            'response_document' => null,
            'finished_at' => null,
        ]);
        $jsonNull = SyncRun::factory()->create([
            'requested_date' => '2026-08-31',
            'status' => 'failed',
            'response_document' => 'null',
            'error_code' => 'UPSTREAM_ERROR',
            'error_message' => 'Synthetic failure',
        ]);

        self::assertTrue(Str::isUlid($missing->id));
        self::assertSame('2026-08-31', $jsonNull->fresh()->requested_date->toDateString());
        self::assertNull($missing->fresh()->response_document);
        self::assertSame('null', $jsonNull->fresh()->response_document);
        self::assertNull($missing->fresh()->finished_at);
        self::assertSame(2, SyncRun::query()->count());
    }

    public function test_non_utc_event_times_are_stored_as_utc_instants(): void
    {
        $wallpaper = Wallpaper::factory()->create([
            'retrieved_at' => '2026-08-31T16:15:00+08:00',
        ]);
        $run = SyncRun::factory()->create([
            'started_at' => '2026-08-31T16:15:00+08:00',
            'finished_at' => '2026-08-31T17:15:00+08:00',
        ]);

        self::assertSame('2026-08-31T08:15:00.000000Z', $wallpaper->fresh()->retrieved_at->toISOString());
        self::assertSame('2026-08-31T08:15:00.000000Z', $run->fresh()->started_at->toISOString());
        self::assertSame('2026-08-31T09:15:00.000000Z', $run->fresh()->finished_at->toISOString());
    }

    public function test_postgresql_schema_and_session_semantics(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only JSONB, timestamptz, and catalog assertions.');
        }

        self::assertSame('UTC', DB::selectOne("SELECT current_setting('TimeZone') AS timezone")->timezone);

        $columns = collect(DB::select("SELECT table_name, column_name, data_type, udt_name, is_nullable
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name IN ('wallpapers', 'sync_runs')"))
            ->keyBy(fn (object $column): string => $column->table_name.'.'.$column->column_name);

        foreach (['wallpapers.source_date', 'sync_runs.requested_date'] as $key) {
            self::assertSame('date', $columns[$key]->data_type);
        }

        foreach (['wallpapers.retrieved_at', 'wallpapers.created_at', 'wallpapers.updated_at', 'sync_runs.started_at', 'sync_runs.finished_at'] as $key) {
            self::assertSame('timestamp with time zone', $columns[$key]->data_type);
        }

        foreach (['wallpapers.raw_payload', 'sync_runs.response_document'] as $key) {
            self::assertSame('jsonb', $columns[$key]->udt_name);
        }

        self::assertSame('NO', $columns['wallpapers.raw_payload']->is_nullable);
        self::assertSame('YES', $columns['sync_runs.response_document']->is_nullable);
        self::assertFalse($columns->has('sync_runs.created_at'));

        $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'wallpapers'"))
            ->pluck('indexname');
        self::assertContains('wallpapers_source_identity_unique', $indexes);
        self::assertContains('wallpapers_source_date_index', $indexes);
        self::assertContains('wallpapers_source_source_date_index', $indexes);

        $syncRunIndexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'sync_runs'"))
            ->pluck('indexname');
        self::assertContains('sync_runs_requested_date_status_index', $syncRunIndexes);

        $wallpaper = Wallpaper::factory()->create(['raw_payload' => '{}']);
        self::assertSame('object', DB::table('wallpapers')->where('id', $wallpaper->id)->selectRaw('jsonb_typeof(raw_payload) AS json_type')->first()->json_type);

        $run = SyncRun::factory()->create(['response_document' => 'null']);
        self::assertSame('null', DB::table('sync_runs')->where('id', $run->id)->selectRaw('jsonb_typeof(response_document) AS json_type')->first()->json_type);
    }
}
