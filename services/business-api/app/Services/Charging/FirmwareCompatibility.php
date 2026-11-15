<?php

namespace App\Services\Charging;

/** Declared vendor-signed paths never infer versions or fall back after failed verification. */
final class FirmwareCompatibility
{
    public function policy(array $policy): array
    {
        abort_unless(array_diff(array_keys($policy), ['protocols', 'from_versions']) === [] && isset($policy['protocols'], $policy['from_versions']), 422, 'Invalid firmware compatibility policy.');
        foreach (['protocols' => 2, 'from_versions' => 20] as $key => $maximum) {
            $values = $policy[$key];
            abort_unless(is_array($values) && array_is_list($values) && count($values) >= 1 && count($values) <= $maximum, 422, 'Invalid firmware compatibility policy.');
            foreach ($values as $value) {
                abort_unless(is_string($value) && ($key === 'protocols' ? in_array($value, ['1.6', '2.0.1'], true) : preg_match('/^[A-Za-z0-9][A-Za-z0-9._+:-]{0,79}$/D', $value)), 422, 'Invalid firmware compatibility policy.');
            }
            abort_unless(count(array_unique($values)) === count($values), 422, 'Duplicate firmware compatibility value.');
            sort($values, SORT_STRING);
            $policy[$key] = $values;
        }

        return $policy;
    }

    public function message(array $identity, array $policy): string
    {
        $policy = $this->policy($policy);
        $document = ['catalog_key' => $identity['catalog_key'] ?? $identity['key'], 'manufacturer' => $identity['manufacturer'], 'model' => $identity['model'], 'version' => $identity['version'], 'sha256' => $identity['sha256'],
            'protocols' => $policy['protocols'], 'from_versions' => $policy['from_versions']];

        return "creabiz-firmware-compatibility-v1\n".json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function catalog(array $entry, string $certificate): array
    {
        if (! array_key_exists('compatibility', $entry) && ! array_key_exists('compatibility_signature', $entry)) {
            return ['compatibility_protocols' => null, 'compatibility_from_versions' => null, 'compatibility_manifest_sha256' => null, 'compatibility_signature' => null];
        }
        abort_unless(is_array($entry['compatibility'] ?? null), 422, 'A complete signed compatibility policy is required.');
        $policy = $this->policy($entry['compatibility']);
        $signature = $entry['compatibility_signature'] ?? null;
        $raw = is_string($signature) && strlen($signature) <= 800 ? base64_decode($signature, true) : false;
        $message = $this->message($entry, $policy);
        abort_unless($raw !== false && $raw !== '' && openssl_verify($message, $raw, $certificate, OPENSSL_ALGO_SHA256) === 1, 422, 'Firmware compatibility signature failed verification.');

        return ['compatibility_protocols' => json_encode($policy['protocols'], JSON_THROW_ON_ERROR), 'compatibility_from_versions' => json_encode($policy['from_versions'], JSON_THROW_ON_ERROR),
            'compatibility_manifest_sha256' => hash('sha256', $message), 'compatibility_signature' => $signature];
    }

    public function approved(object $release): ?array
    {
        if ($release->compatibility_manifest_sha256 === null) {
            abort_unless($release->compatibility_protocols === null && $release->compatibility_from_versions === null && $release->compatibility_signature === null, 409, 'Incomplete approved compatibility evidence.');

            return null;
        }
        try {
            $policy = ['protocols' => json_decode($release->compatibility_protocols, true, 8, JSON_THROW_ON_ERROR), 'from_versions' => json_decode($release->compatibility_from_versions, true, 8, JSON_THROW_ON_ERROR)];
            $message = $this->message((array) $release, $policy);
            $raw = base64_decode($release->compatibility_signature, true);
            $valid = hash_equals($release->compatibility_manifest_sha256, hash('sha256', $message)) && $raw !== false && openssl_verify($message, $raw, $release->signing_certificate, OPENSSL_ALGO_SHA256) === 1;
        } catch (\Throwable) {
            $valid = false;
        }
        abort_unless($valid, 409, 'Approved compatibility evidence requires review.');

        return $this->policy($policy);
    }

    public function source(object $release, string $protocol, array $source): void
    {
        $policy = $this->approved($release);
        if ($policy === null) {
            return;
        }
        abort_unless(in_array($protocol, $policy['protocols'], true)
            && in_array($source['protocol'] ?? null, [$protocol, 'ocpp'.$protocol], true)
            && is_string($source['firmwareVersion'] ?? null) && in_array($source['firmwareVersion'], $policy['from_versions'], true), 409, 'Current source firmware and protocol do not match the signed compatibility policy.');
    }
}
