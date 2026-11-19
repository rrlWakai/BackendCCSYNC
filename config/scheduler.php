<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Python Scheduling Engine Configuration
    |--------------------------------------------------------------------------
    |
     | These values configure the service-to-service connection between the
     | Laravel backend and the deployed Python FastAPI + OR-Tools scheduler.
    |
    | All values MUST be supplied through environment variables. There are
    | no hardcoded fallbacks for URLs or credentials.
    |
    */

    'url' => env('SCHEDULER_URL'),

    'api_key' => env('SCHEDULER_API_KEY'),

    'connect_timeout' => (int) env('SCHEDULER_CONNECT_TIMEOUT', 5),

    'timeout' => (int) env('SCHEDULER_TIMEOUT', 60),

    'health_cache_seconds' => max(1, (int) env('SCHEDULER_HEALTH_CACHE_SECONDS', 30)),

    'contract_version' => '1.0',

    'max_body_bytes' => 10 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Solver Configuration
    |--------------------------------------------------------------------------
    |
    | These values are sent to the Python solver as part of every contract
    | request. They must be explicitly supplied through the environment so
    | that objective weights and solver limits remain an approved decision
    | rather than an implicit fallback. Missing or invalid values cause the
    | request builder to reject the request before it reaches Python.
    |
    */

    'solver' => [
        'time_limit_ms' => env('SCHEDULER_SOLVE_TIME_LIMIT_MS'),
        'worker_count' => env('SCHEDULER_SOLVE_WORKER_COUNT'),
        'random_seed' => env('SCHEDULER_SOLVE_RANDOM_SEED'),
        'objective_weights' => [
            'part_time_target_deviation_per_minute' => env('SCHEDULER_SOLVE_WEIGHT_PART_TIME_TARGET_DEVIATION'),
            'full_time_load_spread_per_minute' => env('SCHEDULER_SOLVE_WEIGHT_FULL_TIME_LOAD_SPREAD'),
            'professor_gap_per_minute' => env('SCHEDULER_SOLVE_WEIGHT_PROFESSOR_GAP'),
            'section_gap_per_minute' => env('SCHEDULER_SOLVE_WEIGHT_SECTION_GAP'),
            'room_capacity_waste_per_seat' => env('SCHEDULER_SOLVE_WEIGHT_ROOM_CAPACITY_WASTE'),
            'section_daily_load_spread_per_minute' => env('SCHEDULER_SOLVE_WEIGHT_SECTION_DAILY_LOAD_SPREAD'),
            'preferred_window_miss_per_meeting' => env('SCHEDULER_SOLVE_WEIGHT_PREFERRED_WINDOW_MISS'),
            'optional_meeting_omission' => env('SCHEDULER_SOLVE_WEIGHT_OPTIONAL_MEETING_OMISSION'),
            'published_placement_change_per_meeting' => env('SCHEDULER_SOLVE_WEIGHT_PUBLISHED_PLACEMENT_CHANGE'),
        ],
    ],

];
