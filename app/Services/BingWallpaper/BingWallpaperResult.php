<?php

declare(strict_types=1);

namespace App\Services\BingWallpaper;

use DateTimeImmutable;

final readonly class BingWallpaperResult
{
    /**
     * @param  array{sourceDate: string, sourceItemId: string, title: ?string, copyright: ?string, copyrightLink: ?string, imageUrl: string, downloadUrl: ?string}|null  $data
     * @param  array{code: string, message: string, retryable: bool}|null  $error
     */
    private function __construct(
        public string $status,
        public string $date,
        public ?array $data,
        public mixed $rawPayload,
        public ?array $error,
        public ?DateTimeImmutable $retrievedAt,
        public ?string $failureCategory,
    ) {}

    /**
     * @param  array{sourceDate: string, sourceItemId: string, title: ?string, copyright: ?string, copyrightLink: ?string, imageUrl: string, downloadUrl: ?string}|null  $data
     * @param  array{code: string, message: string, retryable: bool}|null  $error
     */
    public static function accepted(
        string $status,
        string $date,
        ?array $data,
        mixed $rawPayload,
        ?array $error,
        DateTimeImmutable $retrievedAt,
    ): self {
        return new self($status, $date, $data, $rawPayload, $error, $retrievedAt, null);
    }

    public static function failed(string $date, string $category): self
    {
        return new self('failure', $date, null, null, null, null, $category);
    }
}
