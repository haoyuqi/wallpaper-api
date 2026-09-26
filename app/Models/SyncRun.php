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

#[UseFactory(SyncRunFactory::class)]
class SyncRun extends Model
{
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

    protected function startedAt(): Attribute
    {
        return Attribute::make(
            set: fn (DateTimeInterface|string $value): string => CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s'),
        );
    }

    protected function finishedAt(): Attribute
    {
        return Attribute::make(
            set: fn (DateTimeInterface|string|null $value): ?string => $value === null
                ? null
                : CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s'),
        );
    }
}
