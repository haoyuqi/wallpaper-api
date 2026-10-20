<?php

namespace Database\Seeders;

use App\Models\Wallpaper;
use Illuminate\Database\Seeder;

final class SmokeTestSeeder extends Seeder
{
    public function run(): void
    {
        Wallpaper::query()->updateOrCreate(['id' => '01K41V6QAZ0000000000000000'], [
            'source' => 'bing',
            'source_item_id' => 'smoke-test-private-source-id',
            'market' => 'en-US',
            'source_date' => '2026-08-31',
            'title' => 'Mountain lake at sunrise',
            'copyright_text' => 'Example © Photographer',
            'copyright_link' => 'https://www.bing.com/search?q=example',
            'image_url' => 'https://www.bing.com/th?id=example_1920x1080.jpg',
            'download_url' => 'https://www.bing.com/th?id=example_UHD.jpg',
            'schema_version' => '1.0',
            'raw_payload' => '{"private":"smoke-test-raw-payload"}',
            'retrieved_at' => '2026-08-31T12:00:00Z',
        ]);
    }
}
