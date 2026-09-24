<?php

namespace App\Http\Requests\Client;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

// Validates the query string of the public
// GET /spas/{uuid}/therapists/{staffUuid}/days-off lookup, which the client
// booking calendar calls for the window it can show (today + 60 days).
// Unauthenticated like the rest of the /spas/* lookups; the span cap keeps a
// stranger from asking for years of dates in one request.
class PublicTherapistDaysOffRequest extends FormRequest
{
    private const MAX_SPAN_DAYS = 92;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $span = Carbon::parse($this->input('from'))
                    ->diffInDays(Carbon::parse($this->input('to')));

                if ($span > self::MAX_SPAN_DAYS) {
                    $validator->errors()->add(
                        'to',
                        'The date range may not be longer than ' . self::MAX_SPAN_DAYS . ' days.',
                    );
                }
            },
        ];
    }
}
