<?php

declare(strict_types=1);

namespace App\Services\BingWallpaper;

use App\Models\SyncRun;
use App\Models\Wallpaper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class WallpaperSyncService
{
    public function __construct(private readonly BingWallpaperGateway $gateway) {}

    public function sync(string $date): SyncRun
    {
        if (! ComposerDocumentValidator::isCanonicalDate($date)) {
            throw new InvalidArgumentException('A real date in YYYY-MM-DD format is required.');
        }

        $startedAt = CarbonImmutable::now('UTC');
        $result = $this->gateway->fetch($date);
        $attributes = [
            'source' => 'bing',
            'market' => 'en-US',
            'requested_date' => $date,
            'schema_version' => $result->schemaVersion,
            'response_document' => $result->responseDocument,
            'started_at' => $startedAt,
        ];

        try {
            return DB::transaction(function () use ($result, $attributes): SyncRun {
                if ($result->status === 'found') {
                    $data = $result->data;
                    Wallpaper::query()->upsert([
                        'source' => 'bing',
                        'market' => 'en-US',
                        'source_date' => $data['sourceDate'],
                        'source_item_id' => $data['sourceItemId'],
                        'title' => $data['title'],
                        'copyright_text' => $data['copyright'],
                        'copyright_link' => $data['copyrightLink'],
                        'image_url' => $data['imageUrl'],
                        'download_url' => $data['downloadUrl'],
                        'schema_version' => $result->schemaVersion,
                        'raw_payload' => json_encode($result->rawPayload, JSON_THROW_ON_ERROR),
                        'retrieved_at' => $result->retrievedAt->format('Y-m-d H:i:s'),
                    ], ['source', 'market', 'source_date', 'source_item_id'], [
                        'title', 'copyright_text', 'copyright_link', 'image_url', 'download_url',
                        'schema_version', 'raw_payload', 'retrieved_at', 'updated_at',
                    ]);
                }

                [$code, $message] = match ($result->status) {
                    'found', 'not_found' => [null, null],
                    'error' => ['SOURCE_ERROR', 'The wallpaper source returned an error.'],
                    default => match ($result->failureCategory) {
                        'command_failure' => ['COMMAND_FAILURE', 'The metadata command could not complete.'],
                        'invalid_document' => ['INVALID_DOCUMENT', 'The metadata document did not satisfy the contract.'],
                        default => ['INGESTION_FAILURE', 'The metadata lookup could not complete.'],
                    },
                };

                return SyncRun::query()->create($attributes + [
                    'status' => match ($result->status) {
                        'found' => 'success',
                        'not_found' => 'not_found',
                        default => 'failed',
                    },
                    'error_code' => $code,
                    'error_message' => $message,
                    'finished_at' => CarbonImmutable::now('UTC'),
                ]);
            });
        } catch (Throwable) {
            // The transaction has rolled back before the failure audit is written.
            return SyncRun::query()->create(array_replace($attributes, [
                'schema_version' => null,
                'response_document' => null,
                'status' => 'failed',
                'error_code' => 'PERSISTENCE_FAILURE',
                'error_message' => 'The synchronization transaction could not be saved.',
                'finished_at' => CarbonImmutable::now('UTC'),
            ]));
        }
    }
}
