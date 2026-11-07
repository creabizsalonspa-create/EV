<?php

return [
    // In normal local/staging/production operation Admin MFA is mandatory.
    // This switch is only available to isolated Laravel regression fixtures,
    // so existing legacy feature tests are unaffected until explicitly opted in.
    'force_in_tests' => false,
];
