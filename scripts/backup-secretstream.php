<?php
/** Offline authenticated streaming backup encryption. No Laravel or network boot. */
const BACKUP_MAGIC = "CREABIZ-BACKUP-1\n";
const BACKUP_CHUNK = 1048576;

function privateInput(string $path)
{
    $before = @lstat($path);
    if (!$before || realpath($path) !== $path || ($before['mode'] & 0170000) !== 0100000
        || $before['nlink'] !== 1 || ($before['mode'] & 0077) !== 0
        || $before['uid'] !== posix_geteuid()) throw new RuntimeException();
    $stream = @fopen($path, 'rb');
    $opened = $stream ? fstat($stream) : false;
    if (!$before || !$opened || ($before['mode'] & 0170000) !== 0100000
        || ($opened['mode'] & 0170000) !== 0100000 || $before['ino'] !== $opened['ino']
        || $before['dev'] !== $opened['dev'] || $opened['nlink'] !== 1
        || ($opened['mode'] & 0077) !== 0 || $opened['uid'] !== posix_geteuid()) {
        if ($stream) fclose($stream);
        throw new RuntimeException();
    }
    return $stream;
}

function exactBytes($stream, int $size): string
{
    $result = '';
    while (strlen($result) < $size) {
        $chunk = @fread($stream, $size - strlen($result));
        if ($chunk === false || $chunk === '') throw new RuntimeException();
        $result .= $chunk;
    }
    return $result;
}

function writeBytes($stream, string $bytes): void
{
    while ($bytes !== '') {
        $written = @fwrite($stream, $bytes);
        if ($written === false || $written === 0) throw new RuntimeException();
        $bytes = substr($bytes, $written);
    }
}

$input = $output = $keyStream = null;
$candidate = null;
$key = null;
try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 5 || !extension_loaded('sodium')
        || !function_exists('posix_geteuid') || !in_array($argv[1], ['encrypt', 'decrypt'], true)) throw new RuntimeException();
    [, $action, $source, $destination, $keyPath] = $argv;
    $folder = dirname($destination);
    $parent = @lstat($folder);
    if (!$parent || realpath($folder) !== $folder || ($parent['mode'] & 0170000) !== 0040000
        || ($parent['mode'] & 0077) !== 0 || $parent['uid'] !== posix_geteuid()
        || @lstat($destination) !== false) throw new RuntimeException();
    $keyStream = privateInput($keyPath);
    $key = exactBytes($keyStream, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    if (fread($keyStream, 1) !== '') throw new RuntimeException();
    fclose($keyStream); $keyStream = null;
    $input = privateInput($source);
    $candidate = $folder.'/.backup-'.bin2hex(random_bytes(16)).'.pending';
    $previousMask = umask(0077);
    try { $output = @fopen($candidate, 'x+b'); } finally { umask($previousMask); }
    if (!$output || !@chmod($candidate, 0600)) throw new RuntimeException();
    if ($action === 'encrypt') {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        writeBytes($output, BACKUP_MAGIC.$header);
        while (!feof($input)) {
            $plain = @fread($input, BACKUP_CHUNK);
            if ($plain === false) throw new RuntimeException();
            if ($plain === '') { if (!feof($input)) throw new RuntimeException(); break; }
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, BACKUP_MAGIC);
            writeBytes($output, pack('N', strlen($cipher)).$cipher);
        }
        $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', BACKUP_MAGIC, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        writeBytes($output, pack('N', strlen($final)).$final);
    } else {
        if (exactBytes($input, strlen(BACKUP_MAGIC)) !== BACKUP_MAGIC) throw new RuntimeException();
        $header = exactBytes($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        while (true) {
            $length = unpack('Nlength', exactBytes($input, 4))['length'];
            if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                || $length > BACKUP_CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) throw new RuntimeException();
            $decoded = sodium_crypto_secretstream_xchacha20poly1305_pull($state, exactBytes($input, $length), BACKUP_MAGIC);
            if ($decoded === false) throw new RuntimeException();
            [$plain, $tag] = $decoded;
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                if ($plain !== '' || fread($input, 1) !== '') throw new RuntimeException();
                break;
            }
            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || $plain === '') throw new RuntimeException();
            writeBytes($output, $plain);
        }
    }
    if (!fflush($output) || !fsync($output)) throw new RuntimeException();
    fclose($output); $output = null;
    fclose($input); $input = null;
    // Atomic publish without replacing another operation's destination.
    if (!@link($candidate, $destination)) throw new RuntimeException();
    unlink($candidate); $candidate = null;
    echo "PASS: offline authenticated backup stream; key custody and production restore acceptance remain separate.\n";
} catch (Throwable) {
    fwrite(STDERR, "FAIL: backup encryption input, private storage or stream integrity rejected; details withheld.\n");
    $failed = true;
} finally {
    foreach ([$input, $output, $keyStream] as $stream) if (is_resource($stream)) fclose($stream);
    if ($candidate !== null) @unlink($candidate);
    if (is_string($key)) sodium_memzero($key);
}
exit(isset($failed) ? 1 : 0);
