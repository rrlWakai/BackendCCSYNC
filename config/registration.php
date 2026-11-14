<?php

return [
    'institutional_email_domains' => array_values(array_filter(array_map(
        static fn (string $domain): string => strtolower(ltrim(trim($domain), '@')),
        explode(',', (string) env('INSTITUTIONAL_EMAIL_DOMAINS', 'lspu.edu.ph')),
    ))),
];
