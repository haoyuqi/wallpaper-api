<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WallpaperFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(WallpaperFactory::class)]
class Wallpaper extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'source',
        'source_item_id',
        'market',
        'source_date',
        'title',
        'copyright_text',
        'copyright_link',
        'image_url',
        'download_url',
        'schema_version',
        'raw_payload',
        'retrieved_at',
    ];

    protected function casts(): array
    {
        return [
            'source_date' => 'immutable_date',
            'retrieved_at' => 'immutable_datetime',
        ];
    }

    protected function retrievedAt(): Attribute
    {
        return Attribute::make(
            set: fn (DateTimeInterface|string $value): string => CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s'),
        );
    }
}
