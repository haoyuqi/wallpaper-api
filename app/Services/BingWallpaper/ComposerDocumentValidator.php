<?php

declare(strict_types=1);

namespace App\Services\BingWallpaper;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use stdClass;
use UnexpectedValueException;

final class ComposerDocumentValidator
{
    public function validate(string $output, int $exitCode, string $requestedDate): BingWallpaperResult
    {
        if (! self::isCanonicalDate($requestedDate)) {
            throw new UnexpectedValueException('Invalid requested date.');
        }

        if (! str_ends_with($output, "\n")) {
            throw new UnexpectedValueException('Missing document newline.');
        }

        try {
            $document = json_decode($output, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('Invalid JSON document.');
        }

        if (! $document instanceof stdClass) {
            throw new UnexpectedValueException('Invalid document root.');
        }

        $version = self::field($document, 'schemaVersion');
        if (! is_string($version) || preg_match('/^1\.[0-9]+$/D', $version) !== 1) {
            throw new UnexpectedValueException('Unsupported schema version.');
        }

        $status = self::field($document, 'status');
        $query = self::field($document, 'query');
        $data = self::field($document, 'data');
        $rawPayload = self::field($document, 'rawPayload');
        $error = self::field($document, 'error');
        $retrievedAt = self::field($document, 'retrievedAt');

        if (! $query instanceof stdClass
            || self::field($query, 'source') !== 'bing'
            || self::field($query, 'market') !== 'en-US'
            || self::field($query, 'date') !== $requestedDate
            || ! is_string($retrievedAt)
            || ! self::isUtcTimestamp($retrievedAt)) {
            throw new UnexpectedValueException('Invalid query or retrieval time.');
        }

        if ($status === 'found' && $exitCode === 0 && $error === null && $data instanceof stdClass) {
            $normalized = self::wallpaperData($data, $requestedDate);
        } elseif ($status === 'not_found' && $exitCode === 0 && $data === null && $error === null) {
            $normalized = null;
        } elseif ($status === 'error' && in_array($exitCode, [1, 2], true) && $data === null && $error instanceof stdClass) {
            $normalized = null;
            $error = self::technicalError($error);
        } else {
            throw new UnexpectedValueException('Invalid status and exit code combination.');
        }

        return BingWallpaperResult::accepted(
            $status,
            $requestedDate,
            $normalized,
            $rawPayload,
            $error,
            DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $retrievedAt, new DateTimeZone('UTC')),
            $version,
            $output,
        );
    }

    public static function isCanonicalDate(string $date): bool
    {
        if (preg_match('/^(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private static function isUtcTimestamp(string $value): bool
    {
        if (preg_match('/^(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d\TH:i:s\Z') === $value;
    }

    /**
     * @return array{sourceDate: string, sourceItemId: string, title: ?string, copyright: ?string, copyrightLink: ?string, imageUrl: string, downloadUrl: ?string}
     */
    private static function wallpaperData(stdClass $data, string $requestedDate): array
    {
        $sourceDate = self::field($data, 'sourceDate');
        $sourceItemId = self::field($data, 'sourceItemId');
        $title = self::field($data, 'title');
        $copyright = self::field($data, 'copyright');
        $copyrightLink = self::field($data, 'copyrightLink');
        $imageUrl = self::field($data, 'imageUrl');
        $downloadUrl = self::field($data, 'downloadUrl');

        if (! is_string($sourceDate)
            || ! self::isCanonicalDate($sourceDate)
            || $sourceDate !== $requestedDate
            || ! self::isNonEmptyString($sourceItemId)
            || ! self::isNullableString($title)
            || ! self::isNullableString($copyright)
            || ! self::isNullableHttpsUrl($copyrightLink)
            || ! self::isHttpsUrl($imageUrl)
            || ! self::isNullableHttpsUrl($downloadUrl)) {
            throw new UnexpectedValueException('Invalid wallpaper data.');
        }

        return compact('sourceDate', 'sourceItemId', 'title', 'copyright', 'copyrightLink', 'imageUrl', 'downloadUrl');
    }

    /** @return array{code: string, message: string, retryable: bool} */
    private static function technicalError(stdClass $error): array
    {
        $code = self::field($error, 'code');
        $message = self::field($error, 'message');
        $retryable = self::field($error, 'retryable');

        if (! self::isNonEmptyString($code) || ! self::isNonEmptyString($message) || ! is_bool($retryable)) {
            throw new UnexpectedValueException('Invalid technical error.');
        }

        return compact('code', 'message', 'retryable');
    }

    private static function field(stdClass $object, string $name): mixed
    {
        if (! property_exists($object, $name)) {
            throw new UnexpectedValueException('Missing required field.');
        }

        return $object->{$name};
    }

    private static function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function isNullableString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }

    private static function isHttpsUrl(mixed $value): bool
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && parse_url($value, PHP_URL_SCHEME) === 'https'
            && is_string(parse_url($value, PHP_URL_HOST));
    }

    private static function isNullableHttpsUrl(mixed $value): bool
    {
        return $value === null || self::isHttpsUrl($value);
    }
}
