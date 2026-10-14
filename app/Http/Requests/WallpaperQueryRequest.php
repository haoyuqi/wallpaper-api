<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class WallpaperQueryRequest extends FormRequest
{
    private const MESSAGES = [
        'QUERY_MODE_REQUIRED' => 'Supply either date or both from and to.',
        'QUERY_MODE_CONFLICT' => 'Use either date or from/to, not both.',
        'RANGE_ENDPOINT_REQUIRED' => 'Both from and to are required for a date range.',
        'INVALID_DATE' => 'The date must be a real calendar date in YYYY-MM-DD format.',
        'RANGE_ORDER_INVALID' => 'The from date must be on or before the to date.',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function validationData(): array
    {
        return $this->query();
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $query = $this->query();
            $hasDate = array_key_exists('date', $query);
            $hasFrom = array_key_exists('from', $query);
            $hasTo = array_key_exists('to', $query);

            // Mode errors precede date validation, even for empty supplied values.
            if ($hasDate && ($hasFrom || $hasTo)) {
                $validator->errors()->add('query', 'QUERY_MODE_CONFLICT');

                return;
            }

            if (! $hasDate && ! $hasFrom && ! $hasTo) {
                $validator->errors()->add('query', 'QUERY_MODE_REQUIRED');

                return;
            }

            if (! $hasDate && (! $hasFrom || ! $hasTo)) {
                $validator->errors()->add($hasFrom ? 'to' : 'from', 'RANGE_ENDPOINT_REQUIRED');

                return;
            }

            foreach ($hasDate ? ['date'] : ['from', 'to'] as $field) {
                if (! $this->isCanonicalDate($query[$field])) {
                    $validator->errors()->add($field, 'INVALID_DATE');
                }
            }

            if (! $hasDate && $validator->errors()->isEmpty() && strcmp($query['from'], $query['to']) > 0) {
                $validator->errors()->add('query', 'RANGE_ORDER_INVALID');
            }
        }];
    }

    protected function failedValidation(Validator $validator): void
    {
        $details = [];
        foreach ($validator->errors()->messages() as $field => $codes) {
            foreach ($codes as $code) {
                $details[] = ['field' => $field, 'code' => $code, 'message' => self::MESSAGES[$code]];
            }
        }

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'The request parameters are invalid.',
                'details' => $details,
            ],
        ], 422));
    }

    private function isCanonicalDate(mixed $date): bool
    {
        if (! is_string($date) || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
