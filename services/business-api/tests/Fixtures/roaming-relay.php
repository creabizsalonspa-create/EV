<?php

// CLI-only raw loopback relay for the existing container's isolated test server.
// No application credential, request body or response is written to a log/file.
if (PHP_SAPI !== 'cli' || count($argv) !== 2 || ! ctype_digit($argv[1]) || (int) $argv[1] < 1024 || (int) $argv[1] > 65535) {
    exit(2);
}
$socket = @stream_socket_client('tcp://127.0.0.1:'.(int) $argv[1], $errno, $error, 5);
if (! $socket) {
    exit(3);
}
stream_set_blocking($socket, false);
stream_set_blocking(STDIN, false);
$input = true;
$buffer = '';
$bytes = 0;
$until = microtime(true) + 45;
while (microtime(true) < $until && $bytes <= 16777216) {
    $read = [$socket];
    if ($input && strlen($buffer) < 262144) {
        $read[] = STDIN;
    }
    $write = $buffer !== '' ? [$socket] : [];
    $except = [];
    if (@stream_select($read, $write, $except, 1) === false) {
        break;
    }
    foreach ($read as $stream) {
        $data = fread($stream, 65536);
        if ($stream === STDIN) {
            if ($data === '') {
                if (feof(STDIN)) {
                    $input = false;
                }
            } else {
                $buffer .= $data;
                $bytes += strlen($data);
            }
        } else {
            if ($data === '') {
                if (feof($socket)) {
                    break 2;
                }
            } else {
                $bytes += strlen($data);
                while ($data !== '') {
                    $n = fwrite(STDOUT, $data);
                    if ($n === false || $n === 0) {
                        break 3;
                    }$data = substr($data, $n);
                }
            }
        }
    }
    if ($write && $buffer !== '') {
        $n = fwrite($socket, $buffer);
        if ($n === false) {
            break;
        }$buffer = substr($buffer, $n);
    }
    if (! $input && $buffer === '') {
        @stream_socket_shutdown($socket, STREAM_SHUT_WR);
    }
}
fclose($socket);
