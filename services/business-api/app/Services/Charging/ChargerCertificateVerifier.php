<?php

namespace App\Services\Charging;

use Carbon\CarbonImmutable;

/** Direct pinned-issuer public client certificates. Never accepts or creates private keys. */
class ChargerCertificateVerifier
{
    public function issuers(): array
    {
        $path = config('csms.certificate_issuer_bundle');
        abort_unless(is_string($path) && is_file($path) && filesize($path) > 0 && filesize($path) <= 65536, 503, 'A bounded server-managed issuer bundle is required.');
        $bundle = file_get_contents($path);
        preg_match_all('/-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', $bundle, $matches);
        abort_unless(count($matches[0]) >= 1 && count($matches[0]) <= 10 && trim(str_replace($matches[0], '', $bundle)) === '', 503, 'Issuer bundle must contain only public certificates.');
        $pins = config('csms.certificate_issuer_pins', []);
        $trusted = [];
        foreach ($matches[0] as $pem) {
            $parsed = @openssl_x509_parse($pem);
            $fingerprint = openssl_x509_fingerprint($pem, 'sha256');
            abort_unless(is_array($parsed) && is_string($fingerprint), 503, 'Invalid issuer certificate.');
            if (! in_array($fingerprint, $pins, true)) {
                continue;
            }
            abort_unless(str_contains($parsed['extensions']['basicConstraints'] ?? '', 'CA:TRUE') && str_contains($parsed['extensions']['keyUsage'] ?? '', 'Certificate Sign') && ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) <= now()->timestamp && ($parsed['validTo_time_t'] ?? 0) > now()->timestamp && openssl_x509_verify($pem, @openssl_pkey_get_public($pem)) === 1, 503, 'Pinned issuer is not a current self-signed CA.');
            $details = openssl_pkey_get_details(@openssl_pkey_get_public($pem));
            $strong = is_array($details) && (($details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048 && $details['bits'] <= 8192) || ($details['type'] === OPENSSL_KEYTYPE_EC && in_array($details['ec']['curve_name'] ?? null, ['prime256v1', 'secp384r1'], true)));
            abort_unless($strong && in_array($parsed['signatureTypeSN'] ?? '', ['RSA-SHA256', 'RSA-SHA384', 'RSA-SHA512', 'ecdsa-with-SHA256', 'ecdsa-with-SHA384', 'ecdsa-with-SHA512'], true), 503, 'Pinned issuer key or signature algorithm is unsupported.');
            $trusted[$fingerprint] = ['pem' => $pem, 'parsed' => $parsed];
        }
        abort_unless($trusted !== [], 503, 'No current independently pinned issuer is available.');

        return $trusted;
    }

    public function verify(string $pem, string $identity): array
    {
        abort_unless(strlen($pem) <= 16384 && preg_match('/\A\s*-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----\s*\z/D', $pem), 422, 'Provide one public certificate only; private keys and chains are not accepted.');
        $parsed = @openssl_x509_parse($pem);
        $key = @openssl_pkey_get_public($pem);
        $details = $key ? openssl_pkey_get_details($key) : false;
        abort_unless(is_array($parsed) && is_array($details), 422, 'Invalid public certificate.');
        $extensions = $parsed['extensions'] ?? [];
        abort_unless(($parsed['subject']['CN'] ?? null) === $identity, 422, 'Certificate common name must equal the immutable charger identity.');
        abort_unless(($extensions['basicConstraints'] ?? '') === 'CA:FALSE' && str_contains($extensions['keyUsage'] ?? '', 'Digital Signature') && ! str_contains($extensions['keyUsage'], 'Certificate Sign') && ! str_contains($extensions['keyUsage'], 'CRL Sign') && in_array('TLS Web Client Authentication', array_map('trim', explode(',', $extensions['extendedKeyUsage'] ?? '')), true), 422, 'A non-CA digital-signature TLS client certificate is required.');
        $rsa = $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048 && $details['bits'] <= 8192;
        $ec = $details['type'] === OPENSSL_KEYTYPE_EC && in_array($details['ec']['curve_name'] ?? null, ['prime256v1', 'secp384r1'], true);
        abort_unless(($rsa || $ec) && in_array($parsed['signatureTypeSN'] ?? '', ['RSA-SHA256', 'RSA-SHA384', 'RSA-SHA512', 'ecdsa-with-SHA256', 'ecdsa-with-SHA384', 'ecdsa-with-SHA512'], true), 422, 'Unsupported certificate key strength or signature algorithm.');
        abort_unless(($parsed['validFrom_time_t'] ?? PHP_INT_MAX) <= now()->timestamp && ($parsed['validTo_time_t'] ?? 0) > now()->timestamp, 422, 'Certificate must be currently valid.');
        $issuer = null;
        foreach ($this->issuers() as $fingerprint => $candidate) {
            if (($parsed['issuer'] ?? null) === ($candidate['parsed']['subject'] ?? null) && openssl_x509_verify($pem, @openssl_pkey_get_public($candidate['pem'])) === 1 && $parsed['validTo_time_t'] <= $candidate['parsed']['validTo_time_t']) {
                // Validate X.509 purpose and constraints against this exact pinned
                // root, not an unpinned certificate elsewhere in the bundle/OS.
                $trust = tempnam(sys_get_temp_dir(), 'ev-client-ca-');
                abort_unless(is_string($trust), 503, 'Private certificate validation workspace unavailable.');
                try {
                    chmod($trust, 0600);
                    abort_unless(file_put_contents($trust, $candidate['pem'], LOCK_EX) === strlen($candidate['pem']), 503);
                    $purpose = @openssl_x509_checkpurpose($pem, X509_PURPOSE_SSL_CLIENT, [$trust]);
                    abort_unless($purpose === true || $purpose === 1, 422, 'Certificate client purpose or issuer constraints failed.');
                } finally {
                    unlink($trust);
                }
                $issuer = $fingerprint;
                break;
            }
        }
        abort_unless($issuer !== null, 422, 'Certificate must be signed directly by a configured pinned issuer within its validity.');
        $serial = ltrim(strtolower($parsed['serialNumberHex'] ?? ''), '0');
        abort_unless(preg_match('/^[a-f0-9]{1,40}$/D', $serial) && trim($serial, '0') !== '', 422, 'Certificate serial is unsupported.');

        return ['fingerprint' => openssl_x509_fingerprint($pem, 'sha256'), 'issuer_fingerprint' => $issuer, 'identity' => $identity, 'serial' => $serial, 'key_type' => $rsa ? 'RSA' : 'EC', 'key_bits' => $details['bits'], 'not_before' => CarbonImmutable::createFromTimestampUTC($parsed['validFrom_time_t']), 'not_after' => CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t'])];
    }
}
