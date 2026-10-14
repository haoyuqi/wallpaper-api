<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ApiExceptionTest extends TestCase
{
    #[DataProvider('debugModes')]
    public function test_database_failures_are_safe_even_in_debug_mode(bool $debug): void
    {
        config(['app.debug' => $debug]);
        Log::spy();
        DB::shouldReceive('connection')->andReturnUsing(fn () => throw new QueryException('testing', 'select secret_column from private_table where token = ?', ['synthetic-secret'], new RuntimeException('Synthetic database credentials')));

        $this->getJson('/api/v1/wallpapers?date=2026-08-31')
            ->assertStatus(500)
            ->assertExactJson(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An internal error occurred.']]);

        Log::shouldHaveReceived('error')->once()->with('API request failed.', [
            'error_code' => 'INTERNAL_ERROR',
            'exception_type' => QueryException::class,
        ]);
    }

    #[DataProvider('httpErrors')]
    public function test_http_errors_keep_status_and_required_headers_without_internal_messages(int $status, string $code, string $message): void
    {
        config(['app.debug' => true]);
        Route::get('/api/test-error', fn () => throw new HttpException($status, 'Synthetic sensitive diagnostic', null, ['Retry-After' => '60', 'Allow' => 'GET', 'Content-Type' => 'text/html', 'Content-Length' => '1']));

        $this->get('/api/test-error')
            ->assertStatus($status)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Retry-After', '60')
            ->assertHeader('Allow', 'GET')
            ->assertExactJson(['error' => compact('code', 'message')]);
    }

    public function test_unknown_api_route_uses_the_safe_envelope(): void
    {
        $this->getJson('/api/does-not-exist')->assertNotFound()
            ->assertExactJson(['error' => ['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.']]);
    }

    public function test_unexpected_application_errors_use_the_same_safe_envelope(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        Route::get('/api/test-error', fn () => throw new RuntimeException('Synthetic sensitive diagnostic'));

        $this->getJson('/api/test-error')->assertStatus(500)
            ->assertExactJson(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An internal error occurred.']]);
        Log::shouldHaveReceived('error')->once()->with('API request failed.', [
            'error_code' => 'INTERNAL_ERROR',
            'exception_type' => RuntimeException::class,
        ]);
    }

    public function test_explicit_error_responses_are_sanitized(): void
    {
        Route::get('/api/test-error', fn () => throw new HttpResponseException(new JsonResponse(['message' => 'Synthetic sensitive diagnostic'], 500)));

        $this->getJson('/api/test-error')->assertStatus(500)
            ->assertExactJson(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An internal error occurred.']]);
    }

    public function test_authentication_exceptions_keep_their_status(): void
    {
        Route::get('/api/test-error', fn () => throw new AuthenticationException('Synthetic sensitive diagnostic'));

        $this->getJson('/api/test-error')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_validation_details_are_rebuilt_from_public_codes_only(): void
    {
        Route::get('/api/test-error', fn () => new JsonResponse(['error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Synthetic sensitive diagnostic',
            'details' => [
                ['field' => 'date', 'code' => 'INVALID_DATE', 'message' => 'Synthetic sensitive diagnostic'],
                ['field' => 'secret_field', 'code' => 'INVALID_DATE', 'message' => 'Synthetic sensitive diagnostic'],
                ['field' => 'query', 'code' => 'UNREVIEWED_ERROR', 'message' => 'Synthetic sensitive diagnostic'],
            ],
        ]], 422));

        $this->getJson('/api/test-error')->assertUnprocessable()
            ->assertExactJson(json_decode(file_get_contents(base_path('tests/Fixtures/Contracts/api/validation-error.json')), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_exceptions_with_custom_render_methods_are_sanitized(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        Route::get('/api/test-error', function (): never {
            throw new class extends RuntimeException
            {
                public function render(): JsonResponse
                {
                    return new JsonResponse(['message' => 'Synthetic sensitive diagnostic'], 500);
                }
            };
        });

        $this->getJson('/api/test-error')->assertStatus(500)
            ->assertExactJson(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An internal error occurred.']]);
    }

    public function test_framework_validation_errors_do_not_echo_unreviewed_messages(): void
    {
        Route::get('/api/test-error', fn () => throw ValidationException::withMessages(['field' => 'Synthetic sensitive diagnostic']));

        $this->getJson('/api/test-error')->assertUnprocessable()
            ->assertExactJson(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'The request parameters are invalid.']]);
    }

    public function test_wrong_method_keeps_the_allow_header(): void
    {
        $this->postJson('/api/v1/wallpapers')->assertStatus(405)
            ->assertHeader('Allow', 'GET, HEAD')
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    }

    public function test_existing_validation_details_remain_available_in_debug_mode(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/v1/wallpapers?date=2026-02-30')->assertUnprocessable()
            ->assertExactJson(json_decode(file_get_contents(base_path('tests/Fixtures/Contracts/api/validation-error.json')), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_non_api_responses_keep_framework_behavior(): void
    {
        $this->get('/does-not-exist')->assertNotFound()->assertHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public static function debugModes(): array
    {
        return [[false], [true]];
    }

    public static function httpErrors(): array
    {
        return [
            [401, 'UNAUTHENTICATED', 'Authentication is required.'],
            [403, 'FORBIDDEN', 'Access is forbidden.'],
            [404, 'NOT_FOUND', 'The requested resource was not found.'],
            [405, 'METHOD_NOT_ALLOWED', 'The request method is not allowed.'],
            [422, 'VALIDATION_ERROR', 'The request parameters are invalid.'],
            [429, 'TOO_MANY_REQUESTS', 'Too many requests.'],
            [500, 'INTERNAL_ERROR', 'An internal error occurred.'],
            [503, 'SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.'],
        ];
    }
}
