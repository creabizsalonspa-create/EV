<?php

// Stop only the exact owned loopback fixture process, never the application PID1.
if (PHP_SAPI !== 'cli' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/D', $argv[1]) || ! ctype_digit($argv[2]) || (int) $argv[2] < 1024 || (int) $argv[2] > 65535) {
    throw new RuntimeException('Owned local fixture identity required.');
}
$file = '/tmp/ev-roaming-'.$argv[1].'.pid';
if (! is_file($file)) {
    exit;
}
$raw = trim(file_get_contents($file));
if (! ctype_digit($raw) || (int) $raw <= 1) {
    throw new RuntimeException('Invalid owned fixture PID.');
}
$cmd = @file_get_contents('/proc/'.(int) $raw.'/cmdline');
if (is_string($cmd) && str_contains($cmd, '127.0.0.1:'.$argv[2]."\0") && str_contains($cmd, 'tests/Fixtures/roaming-router.php')) {
    if (! function_exists('posix_kill')) {
        throw new RuntimeException('POSIX fixture cleanup unavailable.');
    }
    posix_kill((int) $raw, 15);
}
unlink($file);
