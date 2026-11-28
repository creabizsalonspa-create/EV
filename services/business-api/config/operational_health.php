<?php

return [
    'enabled' => (bool) env('OPERATIONAL_HEALTH_ENABLED', true),
    // Operator review target, not an uptime or external delivery SLA.
    'review_due_minutes' => env('OPERATIONAL_INCIDENT_REVIEW_DUE_MINUTES', '15'),
    'queue_backlog_ready_count' => env('OPERATIONAL_QUEUE_BACKLOG_READY_COUNT', 25),
    'queue_backlog_age_seconds' => env('OPERATIONAL_QUEUE_BACKLOG_AGE_SECONDS', 300),
];
