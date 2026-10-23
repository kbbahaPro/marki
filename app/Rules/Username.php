<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A URL-safe handle used in public booking links: 3–30 chars of lowercase
 * letters, digits and inner hyphens, and not a reserved route segment.
 */
class Username implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,28})[a-z0-9]$/', $value) || str_contains($value, '--')) {
            $fail(__('The :attribute may only contain lowercase letters, numbers and single hyphens (3–30 characters).'));

            return;
        }

        if (in_array($value, config('booking.reserved_usernames'), true)) {
            $fail(__('This :attribute is reserved.'));
        }
    }
}
