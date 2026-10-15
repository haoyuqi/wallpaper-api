<?php

namespace Tests\Feature;

use App\Models\Wallpaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PublicApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_cors_allows_public_reads_without_credentials(): void
    {
        $this->withHeader('Origin', 'https://gallery.example')->getJson('/api/v1/wallpapers?date=2026-08-31')
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials')
            ->assertHeader('Access-Control-Expose-Headers', 'Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset');
    }

    public function test_configured_origins_allow_only_matching_origins_including_preflights(): void
    {
        config(['cors.allowed_origins' => ['https://gallery.example', 'https://second.example']]);

        $this->withHeader('Origin', 'https://gallery.example')->getJson('/api/v1/wallpapers?date=2026-08-31')
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://gallery.example');
        $this->withHeader('Origin', 'https://other.example')->getJson('/api/v1/wallpapers?date=2026-08-31')
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->withHeader('Origin', 'null')->getJson('/api/v1/wallpapers?date=2026-08-31')
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->options('/api/v1/wallpapers', [], [
            'Origin' => 'https://gallery.example',
            'Access-Control-Request-Method' => 'GET',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://gallery.example')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, HEAD');
        $this->options('/api/v1/wallpapers', [], [
            'Origin' => 'https://other.example',
            'Access-Control-Request-Method' => 'GET',
        ])->assertNoContent()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_single_origin_configuration_never_echoes_an_unapproved_origin(): void
    {
        config(['cors.allowed_origins' => ['https://gallery.example']]);

        // The CORS library emits the sole configured origin as a cacheable value.
        $this->withHeader('Origin', 'https://other.example')->getJson('/api/v1/wallpapers?date=2026-08-31')
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://gallery.example');
    }

    public function test_empty_origin_list_disables_cross_origin_read_permission(): void
    {
        config(['cors.allowed_origins' => []]);
        $this->withHeader('Origin', 'https://gallery.example')->getJson('/api/v1/wallpapers?date=2026-08-31')
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_preflight_does_not_consume_the_read_budget(): void
    {
        config(['wallpapers.requests_per_minute' => 1]);
        $this->options('/api/v1/wallpapers', [], [
            'Origin' => 'https://gallery.example',
            'Access-Control-Request-Method' => 'GET',
        ])->assertNoContent();

        $this->getJson('/api/v1/wallpapers?date=2026-08-31')->assertOk();
        $this->getJson('/api/v1/wallpapers?date=2026-08-31')->assertStatus(429);
    }

    public function test_rate_limit_is_shared_by_query_modes_and_returns_a_safe_cors_response(): void
    {
        config(['wallpapers.requests_per_minute' => 2]);
        $this->getJson('/api/v1/wallpapers?date=2026-08-31')->assertOk()
            ->assertHeader('X-RateLimit-Limit', '2')->assertHeader('X-RateLimit-Remaining', '1');
        $this->getJson('/api/v1/wallpapers?from=0001-01-01&to=9999-12-31')->assertOk();
        $response = $this->withHeader('Origin', 'https://gallery.example')
            ->getJson('/api/v1/wallpapers?date=2026-08-30');
        $response->assertStatus(429)->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertExactJson(['error' => ['code' => 'TOO_MANY_REQUESTS', 'message' => 'Too many requests.']]);
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        self::assertGreaterThan(0, (int) $response->headers->get('X-RateLimit-Reset'));

        $this->travel(61)->seconds();
        $this->getJson('/api/v1/wallpapers?date=2026-08-31')->assertOk();
    }

    public function test_invalid_queries_and_head_requests_consume_budget_but_other_ips_are_independent(): void
    {
        config(['wallpapers.requests_per_minute' => 1]);
        $this->getJson('/api/v1/wallpapers?date=bad')->assertUnprocessable();
        $this->getJson('/api/v1/wallpapers?date=2026-08-31', ['X-Forwarded-For' => '192.0.2.1'])
            ->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->head('/api/v1/wallpapers?date=2026-08-31')->assertOk();
        $this->getJson('/api/v1/wallpapers?date=2026-08-31')->assertStatus(429);
        $this->get('/up')->assertOk()->assertHeaderMissing('X-RateLimit-Limit');
    }

    public function test_allowed_origins_can_read_validation_errors(): void
    {
        $this->withHeader('Origin', 'https://gallery.example')->getJson('/api/v1/wallpapers?date=bad')
            ->assertUnprocessable()->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_allowed_origins_can_read_safe_server_errors(): void
    {
        config(['app.debug' => true]);
        DB::shouldReceive('connection')->andThrow(new \RuntimeException('Synthetic sensitive diagnostic'));
        $this->withHeader('Origin', 'https://gallery.example')->getJson('/api/v1/wallpapers?date=2026-08-31')->assertStatus(500)
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertExactJson(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An internal error occurred.']]);
    }

    public function test_broad_queries_select_only_public_columns_and_never_fetch_client_urls(): void
    {
        Http::preventStrayRequests();
        foreach (range(1, 10) as $batch) {
            Wallpaper::factory()->count(100)->create([
                'raw_payload' => json_encode(['private' => str_repeat('x', 4096)], JSON_THROW_ON_ERROR),
            ]);
        }
        DB::enableQueryLog();

        $response = $this->getJson('/api/v1/wallpapers?from=0001-01-01&to=9999-12-31&url=http://127.0.0.1/private&imageUrl=http://169.254.169.254/');

        $response->assertOk()->assertJsonCount(1000, 'data')->assertJsonPath('meta.count', 1000);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertCount(1, $queries);
        self::assertStringNotContainsString('raw_payload', $queries[0]['query']);
        self::assertStringNotContainsString('select *', $queries[0]['query']);
        self::assertStringNotContainsString('private', $response->getContent());
        Http::assertNothingSent();
        $this->assertDatabaseCount('sync_runs', 0);
    }
}
