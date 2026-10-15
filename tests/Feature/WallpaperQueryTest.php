<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SyncRun;
use App\Models\Wallpaper;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallpaperQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_date_matches_the_frozen_fixture_and_does_not_write(): void
    {
        $fixture = $this->fixture('exact-date-success');
        $this->seedPublicRecord($fixture['data'][0]);
        Wallpaper::factory()->create(['source_date' => '2026-08-30']);
        SyncRun::factory()->create();
        $before = DB::table('wallpapers')->orderBy('id')->get()->toJson();

        $this->getJson('/api/v1/wallpapers?date=2026-08-31')
            ->assertOk()->assertExactJson($fixture);

        self::assertSame($before, DB::table('wallpapers')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('sync_runs', 1);
    }

    public function test_range_matches_the_frozen_fixture_with_nullable_public_fields(): void
    {
        $fixture = $this->fixture('range-success');
        foreach (array_reverse($fixture['data']) as $record) {
            $this->seedPublicRecord($record);
        }
        Wallpaper::factory()->create(['source_date' => '2026-07-31']);
        Wallpaper::factory()->create(['source_date' => '2026-09-01']);

        $this->getJson('/api/v1/wallpapers?from=2026-08-01&to=2026-08-31')
            ->assertOk()->assertExactJson($fixture);
    }

    public function test_empty_exact_date_matches_the_frozen_fixture(): void
    {
        $fixture = $this->fixture('empty-success');

        $this->getJson('/api/v1/wallpapers?date='.$fixture['meta']['query']['date'])
            ->assertOk()->assertExactJson($fixture);
    }

    public function test_range_includes_both_endpoints_and_uses_id_for_same_day_ties(): void
    {
        $older = Wallpaper::factory()->create(['source_date' => '2026-08-01']);
        $low = Wallpaper::factory()->create(['id' => '01K41V6QAZ0000000000000000', 'source_date' => '2026-08-31']);
        $high = Wallpaper::factory()->create(['id' => '01K41V6QAZ0000000000000001', 'source_date' => '2026-08-31']);
        Wallpaper::factory()->create(['source_date' => '2026-07-31']);
        Wallpaper::factory()->create(['source_date' => '2026-09-01']);

        $this->getJson('/api/v1/wallpapers?from=2026-08-01&to=2026-08-31')
            ->assertOk()->assertJsonPath('data.*.id', [$high->id, $low->id, $older->id])
            ->assertJsonPath('meta', ['query' => ['date' => null, 'from' => '2026-08-01', 'to' => '2026-08-31'], 'count' => 3]);
        $this->getJson('/api/v1/wallpapers?from=2026-08-31&to=2026-08-31')
            ->assertOk()->assertJsonPath('data.*.id', [$high->id, $low->id]);
    }

    public function test_wide_range_has_no_business_limit_and_dates_are_not_timezone_converted(): void
    {
        config(['app.timezone' => 'Pacific/Auckland']);
        Wallpaper::factory()->create(['source_date' => '0001-01-01']);
        Wallpaper::factory()->create(['source_date' => '9999-12-31']);

        $this->getJson('/api/v1/wallpapers?from=0001-01-01&to=9999-12-31')
            ->assertOk()->assertJsonPath('data.*.sourceDate', ['9999-12-31', '0001-01-01'])
            ->assertJsonPath('meta.count', 2);
        $this->getJson('/api/v1/wallpapers?date=0001-01-01')
            ->assertOk()->assertJsonPath('data.0.sourceDate', '0001-01-01');
    }

    public function test_empty_range_is_successful_without_an_accept_header(): void
    {
        $this->get('/api/v1/wallpapers?from=2028-02-29&to=2028-02-29')
            ->assertOk()->assertExactJson([
                'data' => [],
                'meta' => ['query' => ['date' => null, 'from' => '2028-02-29', 'to' => '2028-02-29'], 'count' => 0],
            ]);
    }

    public function test_unknown_parameters_do_not_become_filters(): void
    {
        Wallpaper::factory()->create(['source' => 'synthetic', 'market' => 'fr-FR']);

        $this->getJson('/api/v1/wallpapers?date=2026-08-31&source=bing&market=en-US&limit=0&page=2')
            ->assertOk()->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.source', 'synthetic')->assertJsonPath('data.0.market', 'fr-FR');
    }

    public function test_request_body_cannot_supply_or_override_query_parameters(): void
    {
        $this->json('GET', '/api/v1/wallpapers', ['date' => '2026-08-31'])
            ->assertStatus(422)->assertJsonPath('error.details.0.code', 'QUERY_MODE_REQUIRED');
        $this->json('GET', '/api/v1/wallpapers?date=2026-08-31', ['date' => 'invalid', 'from' => '2026-01-01'])
            ->assertOk()->assertJsonPath('meta.query.date', '2026-08-31');
    }

    public function test_model_stores_only_the_calendar_date_without_converting_its_timezone(): void
    {
        foreach (['2026-08-31', CarbonImmutable::parse('2026-08-31T00:30:00+14:00')] as $value) {
            $wallpaper = Wallpaper::factory()->create(['source_date' => $value]);

            self::assertSame('2026-08-31', DB::table('wallpapers')->where('id', $wallpaper->id)->value('source_date'));
            self::assertInstanceOf(CarbonImmutable::class, $wallpaper->fresh()->source_date);
            self::assertSame('2026-08-31', $wallpaper->fresh()->source_date->toDateString());
        }

        $this->getJson('/api/v1/wallpapers?date=2026-08-31')->assertOk()->assertJsonPath('meta.count', 2);
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_query_uses_safe_ordered_contract_errors(string $query, array $errors): void
    {
        $messages = [
            'QUERY_MODE_REQUIRED' => 'Supply either date or both from and to.',
            'QUERY_MODE_CONFLICT' => 'Use either date or from/to, not both.',
            'RANGE_ENDPOINT_REQUIRED' => 'Both from and to are required for a date range.',
            'INVALID_DATE' => 'The date must be a real calendar date in YYYY-MM-DD format.',
            'RANGE_ORDER_INVALID' => 'The from date must be on or before the to date.',
        ];
        $details = array_map(fn (array $error): array => [
            'field' => $error[0], 'code' => $error[1], 'message' => $messages[$error[1]],
        ], $errors);

        $this->get('/api/v1/wallpapers'.$query)->assertStatus(422)->assertExactJson([
            'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'The request parameters are invalid.', 'details' => $details],
        ]);
        $this->assertDatabaseCount('wallpapers', 0);
        $this->assertDatabaseCount('sync_runs', 0);
    }

    public static function invalidQueries(): iterable
    {
        yield 'missing' => ['', [['query', 'QUERY_MODE_REQUIRED']]];
        yield 'only from takes precedence over format' => ['?from=bad', [['to', 'RANGE_ENDPOINT_REQUIRED']]];
        yield 'only to' => ['?to=', [['from', 'RANGE_ENDPOINT_REQUIRED']]];
        yield 'mixed modes precede formats' => ['?date=bad&from=bad&to=bad', [['query', 'QUERY_MODE_CONFLICT']]];
        yield 'date with empty from' => ['?date=2026-08-31&from=', [['query', 'QUERY_MODE_CONFLICT']]];
        yield 'date with to' => ['?date=2026-08-31&to=2026-08-31', [['query', 'QUERY_MODE_CONFLICT']]];
        yield 'both invalid dates are ordered' => ['?to=bad&from=bad', [['from', 'INVALID_DATE'], ['to', 'INVALID_DATE']]];
        yield 'invalid to' => ['?from=2026-08-31&to=2026-02-30', [['to', 'INVALID_DATE']]];
        yield 'reversed' => ['?from=2026-09-01&to=2026-08-31', [['query', 'RANGE_ORDER_INVALID']]];

        foreach (['', '2026-02-29', '1900-02-29', '2026-04-31', '2026-13-01', '2026-00-01', '0000-01-01', '10000-01-01', '26-08-31', '2026-8-31', '2026-08-31T00:00:00Z', ' 2026-08-31 ', "2026-08-31\n", "2026-08-31' OR 1=1"] as $date) {
            yield 'invalid date '.json_encode($date) => ['?'.http_build_query(['date' => $date]), [['date', 'INVALID_DATE']]];
        }
        yield 'array exact date' => ['?date[]=2026-08-31', [['date', 'INVALID_DATE']]];
        yield 'array range' => ['?from[x]=2026-08-31&to=2026-08-31', [['from', 'INVALID_DATE']]];
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/Contracts/api/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedPublicRecord(array $record): void
    {
        Wallpaper::factory()->create([
            'id' => $record['id'], 'source' => $record['source'], 'market' => $record['market'],
            'source_date' => $record['sourceDate'], 'title' => $record['title'],
            'copyright_text' => $record['copyright'], 'copyright_link' => $record['copyrightLink'],
            'image_url' => $record['imageUrl'], 'download_url' => $record['downloadUrl'],
            'raw_payload' => '{"internal":"must stay private"}',
        ]);
    }
}
