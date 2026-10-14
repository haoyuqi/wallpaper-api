<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NormalizeApiErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        // Routes can return HttpResponseException responses without invoking
        // Laravel's exception handler. Normalize those at the HTTP boundary too.
        $response = $next($request);

        return $request->is('api/*') ? ApiErrorResponse::normalize($response) : $response;
    }
}
