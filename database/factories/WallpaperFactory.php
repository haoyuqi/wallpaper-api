<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Wallpaper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Wallpaper> */
class WallpaperFactory extends Factory
{
    protected $model = Wallpaper::class;

    public function definition(): array
    {
        return [
            'source' => 'bing',
            'source_item_id' => (string) Str::uuid(),
            'market' => 'en-US',
            'source_date' => '2026-08-31',
            'title' => 'Synthetic wallpaper',
            'copyright_text' => 'Synthetic test copyright',
            'copyright_link' => null,
            'image_url' => 'https://example.test/wallpaper.jpg',
            'download_url' => null,
            'schema_version' => '1.0',
            'raw_payload' => json_encode((object) ['image' => 'synthetic'], JSON_THROW_ON_ERROR),
            'retrieved_at' => CarbonImmutable::now('UTC'),
        ];
    }
}
