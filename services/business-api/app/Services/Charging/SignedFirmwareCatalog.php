<?php

namespace App\Services\Charging;

use Illuminate\Support\Facades\Storage;

/** Server-provisioned artifacts and independently configured certificate pins. */
class SignedFirmwareCatalog
{
    public function entries(): array
    {
        $root = realpath(config('csms.firmware_catalog_root'));
        if (! $root || ! is_file($root.'/catalog.json')) {
            return [];
        }
        abort_unless(filesize($root.'/catalog.json') <= 262144, 503, 'Firmware catalog is too large.');
        $entries = json_decode(file_get_contents($root.'/catalog.json'), true, 32, JSON_THROW_ON_ERROR);
        abort_unless(is_array($entries) && array_is_list($entries) && count($entries) <= 100, 503, 'Invalid configured firmware catalog.');

        return $entries;
    }

    private function path(string $relative): string
    {
        $root = realpath(config('csms.firmware_catalog_root'));
        $path = $root ? realpath($root.'/'.$relative) : false;
        abort_unless($root && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path), 422, 'Artifact must remain inside the configured private catalog.');

        return $path;
    }

    public function verify(string $key): array
    {
        $found = array_values(array_filter($this->entries(), fn ($v) => is_array($v) && ($v['key'] ?? null) === $key));
        abort_unless(count($found) === 1, 404, 'One configured release is required.');
        $v = $found[0];
        foreach (['version', 'manufacturer', 'model'] as $field) {
            abort_unless(is_string($v[$field] ?? null) && strlen($v[$field]) >= 1 && strlen($v[$field]) <= 80, 422, 'Invalid configured release metadata.');
        }
        abort_unless(is_string($v['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $v['sha256']), 422);
        $file = $this->path($v['file'] ?? '');
        $size = filesize($file);
        abort_unless($size > 0 && $size <= 16 * 1024 * 1024, 413, 'Supported firmware artifacts are bounded to16MiB.');
        $certPath = $this->path($v['certificate'] ?? '');
        abort_unless(filesize($certPath) <= 5500, 422);
        $cert = file_get_contents($certPath);
        abort_unless(preg_match('/\A-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----\s*\z/D', $cert), 422, 'One public signing certificate is required.');
        $parsed = openssl_x509_parse($cert);
        $fingerprint = openssl_x509_fingerprint($cert, 'sha256');
        $pins = array_filter(array_map('strtolower', config('csms.firmware_signer_pins', [])));
        abort_unless(is_array($parsed) && is_string($fingerprint) && in_array(strtolower($fingerprint), $pins, true) && ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) <= time() && ($parsed['validTo_time_t'] ?? 0) > time(), 422, 'A current independently trusted signing certificate is required.');
        $signature = $v['signature'] ?? null;
        $raw = is_string($signature) ? base64_decode($signature, true) : false;
        abort_unless(is_string($signature) && strlen($signature) <= 800 && $raw !== false && strlen($raw) > 0, 422, 'Valid firmware signature is required.');
        $bytes = file_get_contents($file);
        abort_unless(hash_equals($v['sha256'], hash('sha256', $bytes)) && openssl_verify($bytes, $raw, $cert, OPENSSL_ALGO_SHA256) === 1, 422, 'Firmware bytes or signature failed verification.');
        $path = 'firmware/releases/'.$v['sha256'].'.bin';
        if (Storage::disk('local')->exists($path)) {
            abort_unless(hash_equals($v['sha256'], hash_file('sha256', Storage::disk('local')->path($path))), 409, 'Stored firmware changed.');
        } else {
            abort_unless(Storage::disk('local')->put($path, $bytes), 503, 'Private firmware storage is unavailable.');
        }

        return ['catalog_key' => $key, 'version' => $v['version'], 'manufacturer' => $v['manufacturer'], 'model' => $v['model'], 'sha256' => $v['sha256'], 'size' => $size, 'signer_fingerprint' => strtolower($fingerprint), 'signing_certificate' => $cert, 'signature' => $signature];
    }
}
