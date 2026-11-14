<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class InstitutionalEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $allowedDomains = config('registration.institutional_email_domains', []);
        $domain = strtolower((string) substr(strrchr((string) $value, '@') ?: '', 1));

        if ($domain === '' || ! in_array($domain, $allowedDomains, true)) {
            $fail('Use an institutional email address from an approved domain.');
        }
    }
}
