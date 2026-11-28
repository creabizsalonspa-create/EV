<?php

return [
    'enabled' => (bool) env('OPERATIONAL_HEALTH_ENABLED', true),
    'queue_backlog_ready_count' => env('OPERATIONAL_QUEUE_BACKLOG_READY_COUNT', 25),
    'queue_backlog_age_seconds' => env('OPERATIONAL_QUEUE_BACKLOG_AGE_SECONDS', 300),
];
