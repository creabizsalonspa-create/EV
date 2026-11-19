<?php

namespace App\Services\Charging;

use JsonException;
use RuntimeException;

/** Preserve PostgreSQL numeric wire evidence without coercing JSONB meters. */
final class SourceGraphJson
{
    public static function decode(string $body): array
    {
        if (strlen($body) > 2097152) {
            throw new RuntimeException('Authenticated source projection exceeds its byte limit.');
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            // Match complete quoted strings first, so embedded JSON/escaped
            // quotes cannot be mistaken for a numeric token. Keep the original
            // decimal lexeme; never format a decoded binary float back to text.
            $exactBody = preg_replace_callback(
                '/"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s',
                static fn (array $token) => $token[0][0] !== '"' && strpbrk($token[0], '.eE') !== false
                    ? json_encode($token[0], JSON_THROW_ON_ERROR) : $token[0],
                $body,
            );
            $exact = json_decode($exactBody ?? '', true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new RuntimeException('Malformed authenticated source JSON projection.');
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Malformed authenticated source JSON projection.');
        }
        // Only these declared PostgreSQL numeric columns are decimal evidence.
        // IDs/booleans and JSONB sample types retain their existing strict
        // guards. A JSONB floating meter remains unsupported, as before.
        if (is_array($decoded['data']['Transactions'] ?? null)) {
            foreach ($decoded['data']['Transactions'] as $index => &$transaction) {
                if (! is_array($transaction)) {
                    continue;
                }
                foreach (['meterStart', 'totalKwh'] as $column) {
                    if (is_float($transaction[$column] ?? null)) {
                        $transaction[$column] = $exact['data']['Transactions'][$index][$column];
                    }
                }
            }
            unset($transaction);
        }

        return $decoded;
    }
}
