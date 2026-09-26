<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SyncRun> */
class SyncRunFactory extends Factory
{
    protected $model = SyncRun::class;

    public function definition(): array
    {
        return [
            'source' => 'bing',
            'market' => 'en-US',
            'requested_date' => '2026-08-31',
            'status' => 'success',
            'schema_version' => '1.0',
            'response_document' => json_encode((object) ['status' => 'found'], JSON_THROW_ON_ERROR),
            'error_code' => null,
            'error_message' => null,
            'started_at' => CarbonImmutable::now('UTC'),
            'finished_at' => CarbonImmutable::now('UTC'),
        ];
    }
}
