<?php

return [
    'enabled' => (bool) env('OPERATIONAL_HEALTH_ENABLED', true),
    // Operator review target, not an uptime or external delivery SLA.
    'review_due_minutes' => env('OPERATIONAL_INCIDENT_REVIEW_DUE_MINUTES', '15'),
];
