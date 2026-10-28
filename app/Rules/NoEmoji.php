<?php

namespace App\Rules;

use App\Support\Emoji;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// For the fields that identify a person or sign them in (password, email,
// phone, names, employee number, address): no emoji or other pictographs.
// Letters of every language, accents, digits and ordinary symbols are fine.
// The same check runs on every request (App\Http\Middleware\RejectEmoji); this
// rule puts it in the request's own rules, so a form request says it too and
// the error is filed under the field.
class NoEmoji implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Emoji::in($value)) {
            $fail(Emoji::message($attribute));
        }
    }
}
