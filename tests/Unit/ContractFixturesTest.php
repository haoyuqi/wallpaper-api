<?php

declare(strict_types=1);

namespace Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ContractFixturesTest extends TestCase
{
    /**
     * @dataProvider fixtureProvider
     *
     * @throws JsonException
     */
    public function test_contract_fixture_is_valid_json(string $fixture): void
    {
        $contents = file_get_contents($fixture);

        self::assertIsString($contents);
        self::assertIsArray(json_decode($contents, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function fixtureProvider(): iterable
    {
        $fixtures = glob(__DIR__.'/../Fixtures/Contracts/{api,composer}/*.json', GLOB_BRACE);

        self::assertIsArray($fixtures);
        self::assertCount(9, $fixtures);

        foreach ($fixtures as $fixture) {
            yield basename($fixture) => [$fixture];
        }
    }

    /** @throws JsonException */
    public function test_composer_compatibility_examples_are_explicit(): void
    {
        $compatible = $this->decode('composer/found-v1.1-compatible.json');
        $unsupported = $this->decode('composer/unsupported-v2.0.json');

        self::assertSame('1.1', $compatible['schemaVersion']);
        self::assertSame('download-bing-wallpaper', $compatible['producer']);
        self::assertSame('2.0', $unsupported['schemaVersion']);
    }

    /** @throws JsonException */
    public function test_raw_payload_fixture_distinguishes_objects_from_arrays(): void
    {
        $contents = file_get_contents(
            __DIR__.'/../Fixtures/Contracts/composer/not-found-v1.0.json',
        );

        self::assertIsString($contents);

        $response = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $response);
        self::assertSame([], $response->rawPayload->images);
        self::assertInstanceOf(stdClass::class, $response->rawPayload->metadata);
    }

    /** @throws JsonException */
    public function test_empty_api_example_has_a_successful_empty_collection(): void
    {
        $response = $this->decode('api/empty-success.json');

        self::assertSame([], $response['data']);
        self::assertSame(0, $response['meta']['count']);
    }

    /** @throws JsonException */
    public function test_validation_example_has_the_fixed_error_shape(): void
    {
        $response = $this->decode('api/validation-error.json');

        self::assertSame('VALIDATION_ERROR', $response['error']['code']);
        self::assertSame(
            ['field', 'code', 'message'],
            array_keys($response['error']['details'][0]),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decode(string $path): array
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/Contracts/'.$path);

        self::assertIsString($contents);

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
