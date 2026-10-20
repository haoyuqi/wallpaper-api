<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SyncRunFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $source
 * @property string $market
 * @property CarbonImmutable $requested_date
 * @property string $status
 * @property ?string $schema_version
 * @property ?string $response_document
 * @property ?string $error_code
 * @property ?string $error_message
 * @property CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 */
#[UseFactory(SyncRunFactory::class)]
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory, HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'source',
        'market',
        'requested_date',
        'status',
        'schema_version',
        'response_document',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_date' => 'immutable_date',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return Attribute<CarbonImmutable, DateTimeInterface|string> */
    protected function startedAt(): Attribute
    {
        return Attribute::make(
            set: fn (DateTimeInterface|string $value): string => CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s'),
        );
    }

    /** @return Attribute<CarbonImmutable, DateTimeInterface|string> */
    protected function requestedDate(): Attribute
    {
        return Attribute::make(
            set: fn (DateTimeInterface|string $value): string => CarbonImmutable::parse($value)->format('Y-m-d'),
        );
    }

    /** @return Attribute<?CarbonImmutable, DateTimeInterface|string|null> */
    protected function finishedAt(): Attribute
    {
        return Attribute::make(
            set: fn (DateTimeInterface|string|null $value): ?string => $value === null
                ? null
                : CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s'),
        );
    }
}
