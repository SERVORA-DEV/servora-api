<?php

namespace App\Rules;

use App\Support\SocialLinks;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// A Facebook page, Instagram handle or website that makes a link people can
// open (App\Support\SocialLinks). Empty is fine.
class SocialLink implements ValidationRule
{
    private const MESSAGES = [
        SocialLinks::FACEBOOK => 'Enter your Facebook page, like facebook.com/yourpage.',
        SocialLinks::INSTAGRAM => 'Enter your Instagram handle, like @yourhandle.',
        SocialLinks::WEBSITE => 'Enter a website address, like yourspa.com.',
    ];

    public function __construct(private string $kind) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! SocialLinks::valid($this->kind, $value)) {
            $fail(self::MESSAGES[$this->kind]);
        }
    }
}
