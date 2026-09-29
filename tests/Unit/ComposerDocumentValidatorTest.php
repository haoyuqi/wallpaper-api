<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BingWallpaper\ComposerDocumentValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

final class ComposerDocumentValidatorTest extends TestCase
{
    #[DataProvider('acceptedFixtures')]
    public function test_accepts_contract_fixtures(string $fixture, int $exitCode, string $date, string $status): void
    {
        $result = (new ComposerDocumentValidator)->validate($this->fixture($fixture), $exitCode, $date);

        self::assertSame($status, $result->status);
        self::assertSame($date, $result->date);
        self::assertNull($result->failureCategory);
        self::assertSame('2026-09-01T00:00:00Z', $result->retrievedAt?->format('Y-m-d\TH:i:s\Z'));

        if ($status === 'found') {
            self::assertSame($date, $result->data['sourceDate']);
            self::assertSame('0123456789abcdef0123456789abcdef', $result->data['sourceItemId']);
        } elseif ($status === 'error') {
            self::assertSame('UPSTREAM_UNAVAILABLE', $result->error['code']);
            self::assertTrue($result->error['retryable']);
        } else {
            self::assertNull($result->data);
            self::assertNull($result->error);
        }
    }

    public static function acceptedFixtures(): iterable
    {
        yield 'found' => ['found-v1.0.json', 0, '2026-08-31', 'found'];
        yield 'compatible minor' => ['found-v1.1-compatible.json', 0, '2026-08-31', 'found'];
        yield 'not found' => ['not-found-v1.0.json', 0, '1900-01-01', 'not_found'];
        yield 'producer error' => ['error-v1.0.json', 1, '2026-08-31', 'error'];
        yield 'invalid input error' => ['error-v1.0.json', 2, '2026-08-31', 'error'];
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_invalid_required_fields(string $path, mixed $value): void
    {
        $document = json_decode($this->fixture('found-v1.0.json'), false, 512, JSON_THROW_ON_ERROR);
        $target = $document;
        $parts = explode('.', $path);
        $last = array_pop($parts);
        foreach ($parts as $part) {
            $target = $target->{$part};
        }
        $target->{$last} = $value;

        $this->expectException(UnexpectedValueException::class);
        (new ComposerDocumentValidator)->validate(json_encode($document, JSON_THROW_ON_ERROR)."\n", 0, '2026-08-31');
    }

    public static function invalidFields(): iterable
    {
        yield 'unsupported major' => ['schemaVersion', '2.0'];
        yield 'malformed version' => ['schemaVersion', '1.x'];
        yield 'missing version type' => ['schemaVersion', null];
        yield 'unknown status' => ['status', 'pending'];
        yield 'query object required' => ['query', []];
        yield 'wrong source' => ['query.source', 'other'];
        yield 'wrong market' => ['query.market', 'en-GB'];
        yield 'mismatched query date' => ['query.date', '2026-08-30'];
        yield 'data object required' => ['data', []];
        yield 'substituted source date' => ['data.sourceDate', '2026-08-30'];
        yield 'invalid source date' => ['data.sourceDate', '2026-02-30'];
        yield 'empty source item id' => ['data.sourceItemId', '   '];
        yield 'invalid title' => ['data.title', 3];
        yield 'invalid copyright' => ['data.copyright', false];
        yield 'insecure copyright URL' => ['data.copyrightLink', 'http://example.com'];
        yield 'invalid image URL' => ['data.imageUrl', 'https://'];
        yield 'insecure download URL' => ['data.downloadUrl', 'http://example.com'];
        yield 'non-null found error' => ['error', new stdClass];
        yield 'non-UTC timestamp' => ['retrievedAt', '2026-09-01T08:00:00+08:00'];
        yield 'invalid timestamp' => ['retrievedAt', '2026-02-30T00:00:00Z'];
    }

    public function test_rejects_missing_fields_and_multiple_documents(): void
    {
        $document = json_decode($this->fixture('found-v1.0.json'), false, 512, JSON_THROW_ON_ERROR);
        unset($document->rawPayload);

        $validator = new ComposerDocumentValidator;
        foreach ([json_encode($document, JSON_THROW_ON_ERROR)."\n", "{}\n{}\n", "not json\n", '{}', '[]'."\n"] as $output) {
            try {
                $validator->validate($output, 0, '2026-08-31');
                self::fail('Invalid output was accepted.');
            } catch (UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_rejects_missing_required_fields_at_every_level(): void
    {
        foreach (['schemaVersion', 'status', 'query', 'data', 'rawPayload', 'error', 'retrievedAt', 'query.source', 'query.market', 'query.date', 'data.sourceDate', 'data.sourceItemId', 'data.title', 'data.copyright', 'data.copyrightLink', 'data.imageUrl', 'data.downloadUrl'] as $path) {
            $document = json_decode($this->fixture('found-v1.0.json'), false, 512, JSON_THROW_ON_ERROR);
            $target = $document;
            $parts = explode('.', $path);
            $last = array_pop($parts);
            foreach ($parts as $part) {
                $target = $target->{$part};
            }
            unset($target->{$last});

            try {
                (new ComposerDocumentValidator)->validate(json_encode($document, JSON_THROW_ON_ERROR)."\n", 0, '2026-08-31');
                self::fail('Missing '.$path.' was accepted.');
            } catch (UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_rejects_invalid_not_found_and_technical_error_shapes(): void
    {
        $cases = [
            ['not-found-v1.0.json', 0, '1900-01-01', 'data', new stdClass],
            ['not-found-v1.0.json', 0, '1900-01-01', 'error', new stdClass],
            ['error-v1.0.json', 1, '2026-08-31', 'error.code', ''],
            ['error-v1.0.json', 1, '2026-08-31', 'error.message', null],
            ['error-v1.0.json', 1, '2026-08-31', 'error.retryable', 'true'],
            ['error-v1.0.json', 1, '2026-08-31', 'data', new stdClass],
        ];

        foreach ($cases as [$fixture, $exitCode, $date, $path, $value]) {
            $document = json_decode($this->fixture($fixture), false, 512, JSON_THROW_ON_ERROR);
            $target = $document;
            $parts = explode('.', $path);
            $last = array_pop($parts);
            foreach ($parts as $part) {
                $target = $target->{$part};
            }
            $target->{$last} = $value;

            try {
                (new ComposerDocumentValidator)->validate(json_encode($document, JSON_THROW_ON_ERROR)."\n", $exitCode, $date);
                self::fail('Invalid '.$path.' was accepted.');
            } catch (UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    #[DataProvider('invalidPairings')]
    public function test_rejects_exit_and_status_conflicts(string $fixture, int $exitCode, string $date): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new ComposerDocumentValidator)->validate($this->fixture($fixture), $exitCode, $date);
    }

    public static function invalidPairings(): iterable
    {
        yield 'found exit 1' => ['found-v1.0.json', 1, '2026-08-31'];
        yield 'not found exit 2' => ['not-found-v1.0.json', 2, '1900-01-01'];
        yield 'error exit 0' => ['error-v1.0.json', 0, '2026-08-31'];
        yield 'error exit 3' => ['error-v1.0.json', 3, '2026-08-31'];
        yield 'unsupported fixture' => ['unsupported-v2.0.json', 0, '2026-08-31'];
    }

    #[DataProvider('rawShapes')]
    public function test_preserves_raw_json_value_shape(mixed $raw, string $expectedType): void
    {
        $document = json_decode($this->fixture('found-v1.0.json'), false, 512, JSON_THROW_ON_ERROR);
        $document->rawPayload = $raw;

        $result = (new ComposerDocumentValidator)->validate(json_encode($document, JSON_THROW_ON_ERROR)."\n", 0, '2026-08-31');
        self::assertSame($expectedType, get_debug_type($result->rawPayload));
    }

    public static function rawShapes(): iterable
    {
        yield 'empty object' => [new stdClass, 'stdClass'];
        yield 'empty array' => [[], 'array'];
        yield 'string' => ['value', 'string'];
        yield 'integer' => [42, 'int'];
        yield 'null' => [null, 'null'];
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/Contracts/composer/'.$name);
        self::assertIsString($contents);

        return $contents;
    }
}
