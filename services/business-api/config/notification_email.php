<?php

return [
    // Deployment approval and verified user opt-in are independent requirements.
    'enabled' => (bool) env('NOTIFICATION_EMAIL_ENABLED', false),
];
