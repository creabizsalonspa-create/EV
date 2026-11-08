<?php

namespace App\Security;

/**
 * RFC 6238-compatible 6-digit SHA-1 TOTP, 30-second periods.
 *
 * A code is a single-use factor: the persistent caller must record and lock
 * the accepted time step, and refuse a matching step on replay.
 */
final class AdminTotp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private static function decodeSecret(string $secret): string
    {
        if (strlen($secret) !== 32 || ! preg_match('/^[A-Z2-7]{32}$/D', $secret)) {
            throw new \InvalidArgumentException('Invalid authenticator secret.');
        }

        $bits = '';
        foreach (str_split($secret) as $character) {
            $value = strpos(self::ALPHABET, $character);
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $raw = '';
        foreach (str_split($bits, 8) as $chunk) {
            $raw .= chr(bindec($chunk));
        }

        return $raw;
    }

    /** Return a string preserving leading zeros. */
    public static function codeAtStep(string $secret, int $step): string
    {
        if ($step < 0) {
            throw new \InvalidArgumentException('Invalid authenticator counter.');
        }
        $counter = pack('N2', intdiv($step, 4294967296), $step % 4294967296);
        $digest = hash_hmac('sha1', $counter, self::decodeSecret($secret), true);
        $offset = ord($digest[19]) & 0x0F;
        $number = ((ord($digest[$offset]) & 0x7F) << 24)
            | ((ord($digest[$offset + 1]) & 0xFF) << 16)
            | ((ord($digest[$offset + 2]) & 0xFF) << 8)
            | (ord($digest[$offset + 3]) & 0xFF);

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function matchingStep(string $secret, string $code, int $unixSeconds, ?int $lastAcceptedStep = null): ?int
    {
        if (! preg_match('/^\d{6}$/D', $code)) {
            return null;
        }
        $current = intdiv($unixSeconds, 30);
        foreach ([$current - 1, $current, $current + 1] as $step) {
            if ($step >= 0 && ($lastAcceptedStep === null || $step > $lastAcceptedStep)
                && hash_equals(self::codeAtStep($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function provisioningUri(string $secret, string $email): string
    {
        return 'otpauth://totp/'.rawurlencode('Crea-Biz EV:'.$email)
            .'?secret='.$secret.'&issuer='.rawurlencode('Crea-Biz EV')
            .'&algorithm=SHA1&digits=6&period=30';
    }
}
