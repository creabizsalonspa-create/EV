<?php

namespace App\Services\Discovery;

use Closure;
use Illuminate\Support\Facades\Cache;

/** Shared egress protection; never serves stale results after provider failure. */
final class LicensedSourceCache
{
    public static function identity(float $latitude, float $longitude, float $radiusKm, ?string $country, string $credential): string
    {
        return hash('sha256', json_encode([$latitude, $longitude, $radiusKm, $country, hash('sha256', $credential)], JSON_THROW_ON_ERROR));
    }

    /** No fetch, retry, cooldown change or quota consumption. */
    public function peek(string $identity): mixed
    {
        return Cache::get('ocm-discovery:'.$identity);
    }

    public function read(string $identity, string $credential, Closure $fetch): array
    {
        $key = 'ocm-discovery:'.$identity;
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $this->cachedArray($cached); // The adapter still validates every cached field.
        }
        $cooldown = 'ocm-cooldown:'.hash('sha256', $credential);
        $this->checkCooldown($cooldown);
        // Non-blocking: a concurrent cold lookup with the same credential cannot multiply provider calls
        // or occupy a PHP worker while another request waits on the provider.
        $lock = Cache::lock('ocm-flight:'.hash('sha256', $credential), 30);
        if (! $lock->get()) {
            throw new LookupFailure('provider_busy', 503, 1);
        }
        try {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $this->cachedArray($cached);
            }
            $this->checkCooldown($cooldown);
            try {
                $source = $fetch();
            } catch (LookupFailure $error) {
                if ($error->reason === 'provider_busy') {
                    $delay = max(1, min(86400, $error->retryAfter ?? 60));
                    Cache::put($cooldown, now()->getTimestamp() + $delay, $delay);
                }
                throw $error;
            }
            Cache::put($key, $source, now()->addMinutes(5));

            return $source;
        } finally {
            $lock->release();
        }
    }

    private function cachedArray(mixed $cached): array
    {
        if (! is_array($cached)) {
            throw new LookupFailure('invalid_response', 503);
        }

        return $cached;
    }

    private function checkCooldown(string $key): void
    {
        $until = Cache::get($key);
        if (is_int($until) && $until > now()->getTimestamp()) {
            throw new LookupFailure('provider_busy', 503, min(86400, $until - now()->getTimestamp()));
        }
    }
}
