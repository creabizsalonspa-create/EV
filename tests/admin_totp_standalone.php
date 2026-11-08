<?php

// Standalone RFC 6238 acceptance: php tests/admin_totp_standalone.php
// No framework, network, secrets or production accounts required.
require __DIR__.'/../services/business-api/app/Security/AdminTotp.php';

use App\Security\AdminTotp;

function check(bool $condition, string $reason): void
{
    if (! $condition) {
        throw new RuntimeException('TOTP acceptance failed: '.$reason);
    }
}

$known = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
check(AdminTotp::codeAtStep($known, 1) === '287082', 'RFC SHA-1 six-digit vector');
check(AdminTotp::matchingStep($known, '287082', 59) === 1, 'RFC matching step');
check(AdminTotp::matchingStep($known, '287082', 59, 1) === null, 'single-use replay');
check(AdminTotp::matchingStep($known, '0287082', 59) === null, 'exact six digits');

for ($i = 0; $i < 100; $i++) {
    $secret = AdminTotp::generateSecret();
    check((bool) preg_match('/^[A-Z2-7]{32}$/D', $secret), '160-bit secret encoding');
    $step = intdiv(time(), 30);
    $code = AdminTotp::codeAtStep($secret, $step);
    check(AdminTotp::matchingStep($secret, $code, $step * 30) === $step, 'randomized roundtrip');
    check(AdminTotp::matchingStep($secret, $code, $step * 30, $step) === null, 'randomized replay');
}

$uri = AdminTotp::provisioningUri($known, 'admin+test@example.test');
check(str_starts_with($uri, 'otpauth://totp/'), 'URI scheme');
check(str_contains($uri, 'period=30'), '30-second period');
check(str_contains($uri, 'digits=6'), 'six-digit format');
check(!str_contains($uri, 'admin+test@'), 'identity is URL encoded');

echo "PASS: RFC6238 SHA-1 six-digit vector, 100 generated-secret roundtrips, replay defense and provisioning URI.\n";
