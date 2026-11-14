<?php

namespace App\Services\Charging;

use Carbon\CarbonImmutable;

/** Offline X.509 path validation. Client-supplied issuers never become trust anchors. */
class ChargerCertificateVerifier
{
    private function strong(array $parsed, array $details): bool
    {
        $rsa = $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048 && $details['bits'] <= 8192;
        $ec = $details['type'] === OPENSSL_KEYTYPE_EC && in_array($details['ec']['curve_name'] ?? null, ['prime256v1', 'secp384r1'], true);

        return ($rsa || $ec) && in_array($parsed['signatureTypeSN'] ?? '', ['RSA-SHA256', 'RSA-SHA384', 'RSA-SHA512', 'ecdsa-with-SHA256', 'ecdsa-with-SHA384', 'ecdsa-with-SHA512'], true);
    }

    private function current(array $parsed): bool
    {
        return ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) <= now()->timestamp && ($parsed['validTo_time_t'] ?? 0) > now()->timestamp;
    }

    private function parse(string $pem, int $status): array
    {
        $parsed = @openssl_x509_parse($pem);
        $key = @openssl_pkey_get_public($pem);
        $details = $key ? openssl_pkey_get_details($key) : false;
        abort_unless(is_array($parsed) && is_array($details) && $this->strong($parsed, $details), $status, 'Certificate key or signature algorithm is unsupported.');
        $fingerprint = openssl_x509_fingerprint($pem, 'sha256');
        abort_unless(is_string($fingerprint) && ! in_array($fingerprint, config('csms.certificate_denied_issuers', []), true), $status, 'Certificate issuer is blocked by local trust policy.');

        return compact('pem', 'parsed', 'key', 'details', 'fingerprint');
    }

    private function certificates(string $text, int $limit, int $maxBytes, int $status): array
    {
        abort_unless(strlen($text) <= $maxBytes, $status, 'Public certificate input exceeds its bound.');
        preg_match_all('/-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', $text, $matches);
        abort_unless(count($matches[0]) >= 1 && count($matches[0]) <= $limit && trim(str_replace($matches[0], '', $text)) === '', $status, 'Only bounded public certificate PEM blocks are accepted; never private keys.');

        return $matches[0];
    }

    private function ca(array $certificate, int $status): void
    {
        $parsed = $certificate['parsed'];
        abort_unless($this->current($parsed) && str_contains($parsed['extensions']['basicConstraints'] ?? '', 'CA:TRUE') && str_contains($parsed['extensions']['keyUsage'] ?? '', 'Certificate Sign'), $status, 'A current signing CA certificate is required.');
    }

    public function issuers(): array
    {
        $path = config('csms.certificate_issuer_bundle');
        abort_unless(is_string($path) && is_file($path) && filesize($path) > 0 && filesize($path) <= 65536, 503, 'A bounded server-managed issuer bundle is required.');
        $trusted = [];
        foreach ($this->certificates(file_get_contents($path), 10, 65536, 503) as $pem) {
            $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');
            if (! is_string($fingerprint) || ! in_array($fingerprint, config('csms.certificate_issuer_pins', []), true) || in_array($fingerprint, config('csms.certificate_denied_issuers', []), true)) {
                continue;
            }
            $certificate = $this->parse($pem, 503);
            if (! $this->current($certificate['parsed'])) {
                continue;
            }
            $this->ca($certificate, 503);
            abort_unless(openssl_x509_verify($pem, $certificate['key']) === 1, 503, 'A pinned self-signed root is required.');
            $trusted[$fingerprint] = $certificate;
        }
        abort_unless($trusted !== [], 503, 'No current independently pinned root is available.');

        return $trusted;
    }

    public function verify(string $pem, string $identity, ?string $issuerChain = null): array
    {
        $leafBlocks = $this->certificates($pem, 1, 16384, 422);
        $leaf = $this->parse($leafBlocks[0], 422);
        $parsed = $leaf['parsed'];
        $extensions = $parsed['extensions'] ?? [];
        abort_unless(($parsed['subject']['CN'] ?? null) === $identity, 422, 'Certificate common name must equal the immutable charger identity.');
        abort_unless(($extensions['basicConstraints'] ?? '') === 'CA:FALSE' && str_contains($extensions['keyUsage'] ?? '', 'Digital Signature') && ! str_contains($extensions['keyUsage'], 'Certificate Sign') && ! str_contains($extensions['keyUsage'], 'CRL Sign') && in_array('TLS Web Client Authentication', array_map('trim', explode(',', $extensions['extendedKeyUsage'] ?? '')), true), 422, 'A non-CA digital-signature TLS client certificate is required.');
        abort_unless($this->current($parsed), 422, 'Certificate must be currently valid.');
        $roots = $this->issuers();
        $provided = [];
        if ($issuerChain !== null && trim($issuerChain) !== '') {
            foreach ($this->certificates($issuerChain, 4, 49152, 422) as $block) {
                $candidate = $this->parse($block, 422);
                $this->ca($candidate, 422);
                $fp = $candidate['fingerprint'];
                abort_unless($fp !== $leaf['fingerprint'] && ! isset($provided[$fp]), 422, 'Duplicate issuer certificates are not accepted.');
                $provided[$fp] = $candidate;
            }
        }
        $candidates = $provided + $roots;
        $visited = [$leaf['fingerprint'] => true];
        $chain = [];
        $current = $leaf;
        $issuer = null;
        $root = null;
        for ($depth = 0; $depth < 5; $depth++) {
            $matches = [];
            foreach ($candidates as $fingerprint => $candidate) {
                if (! isset($visited[$fingerprint]) && ($current['parsed']['issuer'] ?? null) === ($candidate['parsed']['subject'] ?? null) && openssl_x509_verify($current['pem'], $candidate['key']) === 1) {
                    $matches[$fingerprint] = $candidate;
                }
            }
            abort_unless(count($matches) === 1, 422, 'A complete unambiguous chain to a server-pinned root is required.');
            $parent = array_values($matches)[0];
            $fp = $parent['fingerprint'];
            abort_unless($current['parsed']['validFrom_time_t'] >= $parent['parsed']['validFrom_time_t'] && $current['parsed']['validTo_time_t'] <= $parent['parsed']['validTo_time_t'], 422, 'Certificate validity must remain inside its issuer validity.');
            $issuer ??= $fp;
            $visited[$fp] = true;
            if (isset($roots[$fp])) {
                $root = $roots[$fp];
                break;
            }
            $chain[] = $parent['pem'];
            $current = $parent;
        }
        abort_unless($root !== null && array_diff_key($provided, $visited) === [], 422, 'Unrelated or incomplete issuer chains are not accepted.');
        $canonical = $chain === [] ? '' : implode("\n", $chain)."\n";
        $trust = tempnam(sys_get_temp_dir(), 'ev-client-ca-');
        $untrusted = $canonical === '' ? null : tempnam(sys_get_temp_dir(), 'ev-client-chain-');
        abort_unless(is_string($trust) && ($canonical === '' || is_string($untrusted)), 503, 'Private certificate validation workspace unavailable.');
        try {
            chmod($trust, 0600);
            abort_unless(file_put_contents($trust, $root['pem'], LOCK_EX) === strlen($root['pem']), 503);
            if ($untrusted !== null) {
                chmod($untrusted, 0600);
                abort_unless(file_put_contents($untrusted, $canonical, LOCK_EX) === strlen($canonical), 503);
            }
            $purpose = $untrusted === null ? @openssl_x509_checkpurpose($pem, X509_PURPOSE_SSL_CLIENT, [$trust]) : @openssl_x509_checkpurpose($pem, X509_PURPOSE_SSL_CLIENT, [$trust], $untrusted);
            abort_unless($purpose === true || $purpose === 1, 422, 'Certificate client purpose, path length or issuer constraints failed.');
        } finally {
            unlink($trust);
            if ($untrusted !== null) {
                unlink($untrusted);
            }
        }
        $serial = ltrim(strtolower($parsed['serialNumberHex'] ?? ''), '0');
        abort_unless(preg_match('/^[a-f0-9]{1,40}$/D', $serial), 422, 'Certificate serial is unsupported.');

        return ['fingerprint' => $leaf['fingerprint'], 'issuer_fingerprint' => $issuer, 'trust_anchor_fingerprint' => $root['fingerprint'], 'issuer_chain' => $canonical, 'chain_sha256' => hash('sha256', $canonical), 'chain_length' => count($chain), 'identity' => $identity, 'serial' => $serial, 'key_type' => $leaf['details']['type'] === OPENSSL_KEYTYPE_RSA ? 'RSA' : 'EC', 'key_bits' => $leaf['details']['bits'], 'not_before' => CarbonImmutable::createFromTimestampUTC($parsed['validFrom_time_t']), 'not_after' => CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t'])];
    }
}
