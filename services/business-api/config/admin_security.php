<?php

return [
    // Only synthetic local and PHPUnit test environments can opt in to the
    // production-enforced Admin authenticator challenge. There is no
    // production/staging disable switch.
    'enforce_in_local_tests' => (bool) env('ADMIN_2FA_LOCAL_TEST', false),
];
