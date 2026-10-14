<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Wallpaper;
use App\Services\WallpaperQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallpaperQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_can_query_without_an_http_request_and_only_selects_public_columns(): void
    {
        $first = Wallpaper::factory()->create(['source_date' => '2026-08-01']);
        $last = Wallpaper::factory()->create(['source_date' => '2026-08-31']);
        Wallpaper::factory()->create(['source_date' => '2026-09-01']);
        $service = app(WallpaperQueryService::class);

        self::assertSame([$last->id], $service->forDate('2026-08-31')->modelKeys());
        $range = $service->between('2026-08-01', '2026-08-31');
        self::assertSame([$last->id, $first->id], $range->modelKeys());
        self::assertArrayNotHasKey('raw_payload', $range->first()->getAttributes());
        self::assertArrayNotHasKey('source_item_id', $range->first()->getAttributes());
        $this->assertDatabaseCount('wallpapers', 3);
        $this->assertDatabaseCount('sync_runs', 0);
    }

    #[DataProvider('invalidQueries')]
    public function test_service_rejects_invalid_input_from_non_http_callers(string $method, array $dates): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(WallpaperQueryService::class)->{$method}(...$dates);
    }

    public static function invalidQueries(): array
    {
        return [
            ['forDate', ['2026-02-30']],
            ['forDate', ['0000-01-01']],
            ['between', ['invalid', '2026-08-31']],
            ['between', ['2026-08-01', 'invalid']],
            ['between', ['2026-09-01', '2026-08-31']],
        ];
    }
}
