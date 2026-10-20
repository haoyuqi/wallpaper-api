<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Responses\ApiErrorResponse;
use App\Support\CalendarDate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class WallpaperQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<never, never> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    /** @return list<callable(Validator): void> */
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
                if (! CalendarDate::isCanonical($query[$field])) {
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
        throw new HttpResponseException(ApiErrorResponse::validation($validator->errors()->messages()));
    }
}
