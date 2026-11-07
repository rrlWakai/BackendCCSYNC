<?php

return [

    /*
    |--------------------------------------------------------------------------
    | QR attendance token lifetime
    |--------------------------------------------------------------------------
    |
    | QR tokens are opaque, short-lived values. The Professor display rotates a
    | token before this lifetime elapses so students can continue to check in.
    |
    */

    'qr_token_ttl_seconds' => (int) env('ATTENDANCE_QR_TOKEN_TTL_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Late threshold
    |--------------------------------------------------------------------------
    |
    | A QR check-in is recorded as late once the scheduled class start time has
    | passed by this many minutes. Earlier check-ins are recorded as present.
    |
    */

    'qr_late_after_minutes' => (int) env('ATTENDANCE_QR_LATE_AFTER_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Scan rate limit
    |--------------------------------------------------------------------------
    |
    | Maximum QR check-in submissions per authenticated student per minute.
    |
    */

    'qr_scan_rate_limit_per_minute' => (int) env('ATTENDANCE_QR_SCAN_RATE_LIMIT', 12),

];
