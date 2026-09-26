<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SyncRun;
use App\Models\Wallpaper;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallpaper_factory_persists_metadata_and_raw_json_types(): void
    {
        foreach (['{}', '[]', 'null', '42', '"text"'] as $payload) {
            $wallpaper = Wallpaper::factory()->create([
                'source_date' => '2026-08-31',
                'raw_payload' => $payload,
            ]);

            $this->assertTrue(Str::isUlid($wallpaper->id));
            $this->assertSame('2026-08-31', $wallpaper->fresh()->source_date->toDateString());
            $this->assertEquals(json_decode($payload, false, 512, JSON_THROW_ON_ERROR), json_decode($wallpaper->fresh()->raw_payload, false, 512, JSON_THROW_ON_ERROR));
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

        $this->assertTrue(Str::isUlid($missing->id));
        $this->assertSame('2026-08-31', $jsonNull->fresh()->requested_date->toDateString());
        $this->assertNull($missing->fresh()->response_document);
        $this->assertSame('null', $jsonNull->fresh()->response_document);
        $this->assertNull($missing->fresh()->finished_at);
        $this->assertSame(2, SyncRun::query()->count());
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

        $this->assertSame('2026-08-31T08:15:00.000000Z', $wallpaper->fresh()->retrieved_at->toISOString());
        $this->assertSame('2026-08-31T08:15:00.000000Z', $run->fresh()->started_at->toISOString());
        $this->assertSame('2026-08-31T09:15:00.000000Z', $run->fresh()->finished_at->toISOString());
    }

    public function test_postgresql_schema_and_session_semantics(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only JSONB, timestamptz, and catalog assertions.');
        }

        $this->assertSame('UTC', DB::selectOne("SELECT current_setting('TimeZone') AS timezone")->timezone);

        $columns = collect(DB::select("SELECT table_name, column_name, data_type, udt_name, is_nullable
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name IN ('wallpapers', 'sync_runs')"))
            ->keyBy(fn (object $column): string => $column->table_name.'.'.$column->column_name);

        foreach (['wallpapers.source_date', 'sync_runs.requested_date'] as $key) {
            $this->assertSame('date', $columns[$key]->data_type);
        }

        foreach (['wallpapers.retrieved_at', 'wallpapers.created_at', 'wallpapers.updated_at', 'sync_runs.started_at', 'sync_runs.finished_at'] as $key) {
            $this->assertSame('timestamp with time zone', $columns[$key]->data_type);
        }

        foreach (['wallpapers.raw_payload', 'sync_runs.response_document'] as $key) {
            $this->assertSame('jsonb', $columns[$key]->udt_name);
        }

        $this->assertSame('NO', $columns['wallpapers.raw_payload']->is_nullable);
        $this->assertSame('YES', $columns['sync_runs.response_document']->is_nullable);
        $this->assertFalse($columns->has('sync_runs.created_at'));

        $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'wallpapers'"))
            ->pluck('indexname');
        $this->assertContains('wallpapers_source_identity_unique', $indexes);
        $this->assertContains('wallpapers_source_date_index', $indexes);
        $this->assertContains('wallpapers_source_source_date_index', $indexes);

        $wallpaper = Wallpaper::factory()->create(['raw_payload' => '{}']);
        $this->assertSame('object', DB::table('wallpapers')->where('id', $wallpaper->id)->selectRaw('jsonb_typeof(raw_payload) AS json_type')->first()->json_type);

        $run = SyncRun::factory()->create(['response_document' => 'null']);
        $this->assertSame('null', DB::table('sync_runs')->where('id', $run->id)->selectRaw('jsonb_typeof(response_document) AS json_type')->first()->json_type);
    }
}
