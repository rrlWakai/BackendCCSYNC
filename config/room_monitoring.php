<?php

return [
    'early_check_in_minutes' => max(0, (int) env('ROOM_EARLY_CHECK_IN_MINUTES', 10)),
];
