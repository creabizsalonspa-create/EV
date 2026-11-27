<?php

namespace App\Services\Charging;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Keep queryable command metadata while protecting transport credentials. */
final class CommandSecretFields
{
    private const MARKER = '__creabiz_command_secret';

    public static function secretKey(string|int $key): bool
    {
        $name = is_string($key) ? strtolower(str_replace(['_', '-'], '', $key)) : '';

        return in_array($name, ['idtoken', 'idtag', 'token', 'accesstoken', 'refreshtoken', 'authorization', 'secret', 'clientsecret', 'apikey', 'privatekey', 'password', 'xhasuraadminsecret', 'signature'], true);
    }

    /** URI userinfo is reusable authority even under protocol-defined URL keys. */
    public static function credentialUri(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $uri = parse_url($value);

        return is_array($uri) && isset($uri['scheme'], $uri['host']) && (array_key_exists('user', $uri) || array_key_exists('pass', $uri));
    }

    private static function protectedValue(string|int $key, mixed $value): bool
    {
        return self::secretKey($key) || self::credentialUri($value) || (is_array($value) && array_key_exists(self::MARKER, $value));
    }

    public static function read(?string $json, array $attributes, string $field): mixed
    {
        return self::walk(self::json($json), $attributes, [$field], false);
    }

    /** Aggregate known-field evidence only; never return decrypted values. */
    public static function inspect(?string $json, array $attributes, string $field): array
    {
        if ($json !== null && strlen($json) > 65536) {
            throw new DecryptException('Command field exceeds the inspection limit.');
        }
        $raw = self::json($json);
        self::walk($raw, $attributes, [$field], false);
        $counts = ['encrypted_fields' => 0, 'legacy_fields' => 0, 'shape_known' => $field !== 'payload' || is_array($raw)];
        $count = function (mixed $value) use (&$count, &$counts): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if (self::protectedValue($key, $child)) {
                    $counts[is_array($child) && array_key_exists(self::MARKER, $child) ? 'encrypted_fields' : 'legacy_fields']++;
                } else {
                    $count($child);
                }
            }
        };
        $count($raw);

        return $counts;
    }

    public static function seal(?string $json, array $attributes, string $field, bool $rekey = false): ?string
    {
        if ($json === null) {
            return null;
        }

        $plain = self::json($json);
        $sealed = self::walk($plain, $attributes, [$field], true, $rekey);

        // Preserve retained JSON bytes (including PostgreSQL formatting) when
        // nothing needs sealing; ordinary status updates are not backfills.
        return $sealed === $plain ? $json : json_encode($sealed, JSON_THROW_ON_ERROR);
    }

    private static function json(?string $json): mixed
    {
        try {
            return $json === null ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DecryptException('Command secret storage is unreadable.');
        }
    }

    private static function context(array $attributes, array $path): string
    {
        $identity = [];
        foreach (['actor_id', 'station_id', 'action', 'idempotency_key', 'payload_hash'] as $key) {
            if (! isset($attributes[$key]) || ! is_scalar($attributes[$key])) {
                throw new DecryptException('Command secret identity is unavailable.');
            }
            $identity[$key] = (string) $attributes[$key];
        }

        return hash('sha256', json_encode([$identity, $path], JSON_THROW_ON_ERROR));
    }

    private static function walk(mixed $data, array $attributes, array $path, bool $seal, bool $rekey = false): mixed
    {
        if (! is_array($data)) {
            return $data;
        }
        foreach ($data as $key => $value) {
            $child = [...$path, $key];
            if (! self::protectedValue($key, $value)) {
                $data[$key] = self::walk($value, $attributes, $child, $seal, $rekey);

                continue;
            }
            $context = self::context($attributes, $child);
            if (is_array($value) && array_key_exists(self::MARKER, $value)) {
                try {
                    if (count($value) !== 2 || $value[self::MARKER] !== 1 || ! is_string($value['ciphertext'] ?? null)) {
                        throw new DecryptException;
                    }
                    $plain = json_decode(Crypt::decryptString($value['ciphertext']), true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($plain) || count($plain) !== 2 || ! is_string($plain['context'] ?? null) || ! hash_equals($context, $plain['context']) || ! array_key_exists('value', $plain)) {
                        throw new DecryptException;
                    }
                    $data[$key] = $seal
                        ? ($rekey ? [self::MARKER => 1, 'ciphertext' => Crypt::encryptString(json_encode($plain, JSON_THROW_ON_ERROR))] : $value)
                        : $plain['value'];
                } catch (Throwable) {
                    // Never fall back to using damaged ciphertext as a token.
                    throw new DecryptException('Command secret authentication failed.');
                }
            } elseif ($seal) {
                $data[$key] = [self::MARKER => 1, 'ciphertext' => Crypt::encryptString(json_encode(['context' => $context, 'value' => $value], JSON_THROW_ON_ERROR))];
            }
        }

        return $data;
    }
}
