<?php

namespace App\Http\Controllers;

use App\Services\Discovery\LookupFailure;
use App\Services\Discovery\OpenChargeMapAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Source-only licensed discovery, opt-in and disabled by default.
 * This API never provisions a charger, partner account, payment or reservation.
 */
final class OpenChargeMapDiscoveryController extends Controller
{
    public function capabilities()
    {
        return self::privateResponse([
            'provider' => 'open_charge_map',
            'enabled' => self::configured(),
            'evidence' => 'DISCOVERY_ONLY',
            'requires_explicit_consent' => true,
            'max_radius_km' => 200,
            'max_results' => 25,
            'attribution_required_per_record' => true,
            'availability' => 'unknown',
            'remote_control' => false,
            'reservable' => false,
        ]);
    }

    public function search(Request $request, OpenChargeMapAdapter $adapter)
    {
        $input = $request->validate([
            'consent' => 'required|accepted',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius_km' => 'required|numeric|between:1,200',
            'country' => 'nullable|string|regex:/^[A-Z]{2}$/',
            'min_reported_power_kw' => 'nullable|numeric|between:1,1000',
            'reported_connector' => 'nullable|string|min:1|max:80',
        ]);

        if (! self::configured()) {
            return self::privateResponse([
                'message' => 'Approved Open Charge Map source is not configured. Local station and partner catalog search remains available.',
                'reason' => 'not_configured',
            ], 503);
        }

        $key = (string) config('discovery.ocm_api_key');
        // A salted one-way cache key prevents storing raw lookup coordinates
        // or API credentials in cache key metadata, while avoiding duplicate
        // provider requests under its published fair-usage requirements.
        $cacheIdentity = hash('sha256', json_encode([
            (float) $input['latitude'], (float) $input['longitude'],
            (float) $input['radius_km'], $input['country'] ?? null,
            isset($input['min_reported_power_kw']) ? (float) $input['min_reported_power_kw'] : null,
            $input['reported_connector'] ?? null, hash('sha256', $key),
        ], JSON_THROW_ON_ERROR));

        try {
            $result = Cache::remember('ocm-discovery:'.$cacheIdentity, now()->addMinutes(5), fn () => $adapter->search(
                (float) $input['latitude'],
                (float) $input['longitude'],
                (float) $input['radius_km'],
                $key,
                $input['country'] ?? null,
                isset($input['min_reported_power_kw']) ? (float) $input['min_reported_power_kw'] : null,
                $input['reported_connector'] ?? null,
            ));

            return self::privateResponse($result);
        } catch (LookupFailure $error) {
            return self::privateResponse([
                'message' => 'Licensed discovery source temporarily unavailable. Manual station searches remain available.',
                'reason' => $error->reason,
            ], $error->status);
        }
    }

    private static function configured(): bool
    {
        $key = config('discovery.ocm_api_key');

        return config('discovery.ocm_enabled') === true
            && config('discovery.ocm_terms_accepted') === true
            && is_string($key)
            && strlen(trim($key)) >= 12
            && strlen($key) <= 512;
    }

    private static function privateResponse(array $body, int $status = 200)
    {
        return response()->json($body, $status)
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
