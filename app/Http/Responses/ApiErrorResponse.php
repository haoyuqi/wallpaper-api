<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ApiErrorResponse
{
    private const VALIDATION_MESSAGES = [
        'QUERY_MODE_REQUIRED' => 'Supply either date or both from and to.',
        'QUERY_MODE_CONFLICT' => 'Use either date or from/to, not both.',
        'RANGE_ENDPOINT_REQUIRED' => 'Both from and to are required for a date range.',
        'INVALID_DATE' => 'The date must be a real calendar date in YYYY-MM-DD format.',
        'RANGE_ORDER_INVALID' => 'The from date must be on or before the to date.',
    ];

    public static function normalize(Response $response): Response
    {
        $status = $response->getStatusCode();
        if ($status < 400) {
            return $response;
        }

        $headers = $response->headers->all();
        unset($headers['content-type'], $headers['content-length']);

        if ($status === 422 && $response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (($data['error']['code'] ?? null) === 'VALIDATION_ERROR'
                && is_array($data['error']['details'] ?? null)) {
                $messages = [];
                foreach ($data['error']['details'] as $detail) {
                    if (is_array($detail) && is_string($detail['field'] ?? null) && is_string($detail['code'] ?? null)) {
                        $messages[$detail['field']][] = $detail['code'];
                    }
                }

                return self::validation($messages, $headers);
            }
        }

        [$code, $message] = match ($status) {
            401 => ['UNAUTHENTICATED', 'Authentication is required.'],
            403 => ['FORBIDDEN', 'Access is forbidden.'],
            404 => ['NOT_FOUND', 'The requested resource was not found.'],
            405 => ['METHOD_NOT_ALLOWED', 'The request method is not allowed.'],
            422 => ['VALIDATION_ERROR', 'The request parameters are invalid.'],
            429 => ['TOO_MANY_REQUESTS', 'Too many requests.'],
            503 => ['SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.'],
            default => $status >= 500
                ? ['INTERNAL_ERROR', 'An internal error occurred.']
                : ['HTTP_ERROR', 'The request could not be completed.'],
        };

        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }

    /** @param array<string, list<string>> $messages */
    public static function validation(array $messages, array $headers = []): JsonResponse
    {
        $error = ['code' => 'VALIDATION_ERROR', 'message' => 'The request parameters are invalid.'];
        $details = [];
        foreach (['date', 'from', 'to', 'query'] as $field) {
            foreach ($messages[$field] ?? [] as $code) {
                if (isset(self::VALIDATION_MESSAGES[$code])) {
                    $details[] = ['field' => $field, 'code' => $code, 'message' => self::VALIDATION_MESSAGES[$code]];
                }
            }
        }

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], 422, $headers);
    }
}
