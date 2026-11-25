<?php

return [
    // Opt-in only on an isolated simulator deployment. Never production.
    'scheduled_enabled' => (bool) env('SCHEDULED_SIMULATOR_TARIFFS_ENABLED', false),
];
