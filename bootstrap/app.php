<?php

declare(strict_types=1);

use App\Http\Middleware\NormalizeApiErrors;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(NormalizeApiErrors::class);
        // Calendar-date query values must be canonical, not silently trimmed.
        $middleware->trimStrings(except: [
            fn (Request $request): bool => $request->is('api/v1/wallpapers'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // Do not put SQL, bindings, request values or exception messages into API logs.
        $exceptions->report(function (Throwable $exception): ?bool {
            if (! request()->is('api/*')) {
                return null;
            }

            Log::error('API request failed.', [
                'error_code' => 'INTERNAL_ERROR',
                'exception_type' => $exception::class,
            ]);

            return false;
        });

        $exceptions->render(function (Throwable $exception, Request $request): ?Response {
            if (! $request->is('api/*')) {
                return null;
            }

            $response = $exception instanceof HttpResponseException ? $exception->getResponse() : null;
            $status = $response?->getStatusCode() ?? match (true) {
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                $exception instanceof AuthenticationException => 401,
                $exception instanceof ValidationException => $exception->status,
                default => 500,
            };

            // Do not resolve the response factory: it may initialize a database
            // session even when the database itself is unavailable.
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

            return ApiErrorResponse::normalize($response ?? new Response('', $status, $headers));
        });

        // Also sanitize exceptions that supply their own render() response.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            return $request->is('api/*') ? ApiErrorResponse::normalize($response) : $response;
        });
    })->create();
