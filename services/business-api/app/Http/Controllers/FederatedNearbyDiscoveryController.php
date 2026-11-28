<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Discovery\LicensedSourceCache;
use App\Services\Discovery\LicensedSourceQuality;
use App\Services\Discovery\OpenChargeMapAdapter;
use Illuminate\Http\Request;

final class FederatedNearbyDiscoveryController extends Controller
{
    public function search(Request $request, UnifiedDiscoveryController $published, OpenChargeMapDiscoveryController $licensed, OpenChargeMapAdapter $adapter, LicensedSourceCache $cache, LicensedSourceQuality $quality)
    {
        $actor = $this->currentActor($request);
        $input = $request->validate([
            'consent' => 'required|accepted',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius_km' => 'required|numeric|between:1,200',
            'country' => 'nullable|in:AE,US,CA',
            'page' => 'prohibited',
        ]);
        // Only a common geographic query is supported. Reject unknown
        // filters rather than silently applying them to just one source.
        if (array_diff(array_keys($request->all()), ['consent', 'latitude', 'longitude', 'radius_km', 'country', '_token']) !== []) {
            abort(422, 'Unsupported federated nearby filter.');
        }

        $geography = array_intersect_key($input, array_flip(['latitude', 'longitude', 'radius_km', 'country']));
        // The published query is local, first-page only. Never include its
        // generated pagination URLs (which may contain private coordinates).
        $local = Request::create('/internal/published-discovery', 'POST', $geography);
        $local->setUserResolver(fn () => $actor);
        $published->index($local); // Fail local admission before any provider egress.
        $this->currentActor($request);

        // This is the exact existing provider path, with its cache,
        // licensing checks, bounds, normalization and failure policy.
        // No account identity or published record travels to the provider.
        $sourceRequest = Request::create('/internal/licensed-discovery', 'POST', $geography + ['consent' => true]);
        $sourceCredential = config('discovery.ocm_api_key');
        $source = $licensed->search($sourceRequest, $adapter, $cache, $quality);
        $this->currentActor($request);

        // Provider latency is an authorization boundary. Re-read published
        // visibility after the network await, including partner revocation.
        $localPage = $published->index($local)->getData(true);
        $this->currentActor($request);
        $sourceStatus = $source->getStatusCode();
        $sourceBody = $source->getData(true);
        // A rotated credential is a different licensed-cache identity.
        // Never release a sample fetched under the retired configuration.
        $authorityCurrent = $licensed->capabilities()->getData(true)['enabled'] === true
            && config('discovery.ocm_api_key') === $sourceCredential;
        if (! $authorityCurrent) {
            $sourceStatus = 503;
            $sourceBody = ['reason' => 'not_configured'];
        }
        $sourceResult = $sourceStatus === 200
            ? ['state' => 'observed', 'sample' => $sourceBody]
            : ['state' => 'unavailable', 'reason' => $sourceBody['reason'] ?? 'source_unavailable', 'sample' => null];
        // Retry advice from a retired provider must not be attributed to
        // the currently approved source (including a replacement credential).
        if ($authorityCurrent && $sourceStatus !== 200 && $source->headers->has('Retry-After')) {
            $sourceResult['retry_after_seconds'] = (int) $source->headers->get('Retry-After');
        }

        return response()->json([
            'evidence' => 'FEDERATED_GEOGRAPHIC_DISCOVERY_ONLY',
            'distance_basis' => 'great_circle',
            'published' => array_intersect_key($localPage, array_flip(['data', 'total', 'current_page', 'last_page', 'per_page'])),
            'licensed' => $sourceResult,
            'complete_geographic_coverage' => false,
            'live_status_verified' => false,
            'combined_total' => null,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function currentActor(Request $request): User
    {
        $snapshot = $request->user();
        abort_unless($snapshot instanceof User, 401);
        $current = User::find($snapshot->id);
        abort_unless($current && $current->account_status === 'active' && $current->role === $snapshot->role
            && (int) $current->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $current->password, (string) $snapshot->password), 409, 'Account authorization changed. Sign in again.');

        return $current;
    }
}
