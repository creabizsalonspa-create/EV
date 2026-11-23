<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OcpiProvisioningController extends Controller
{
    /**
     * Keep private onboarding metadata inside one current Admin authority
     * window. A captured, previously valid Request cannot regain access
     * following account suspension, role change, password or epoch rotation.
     */
    private function currentAdminRead(Request $request, Closure $read): mixed
    {
        return DB::transaction(function () use ($request, $read) {
            $snapshot = $request->user();
            abort_unless($snapshot instanceof User, 401);
            $admin = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($admin->role === 'admin' && $admin->account_status === 'active', 403);
            abort_unless($snapshot->role === 'admin'
                && (int) $snapshot->auth_epoch === (int) $admin->auth_epoch
                && hash_equals((string) $snapshot->password, (string) $admin->password),
                409, 'Account credentials changed. Sign in again.');

            return $read();
        }, 3);
    }

    /** Structural readiness only: no endpoint, token name/value or outbound probe. */
    public function connections(Request $request, PartnerClient $client)
    {
        $data = $this->currentAdminRead($request, function () use ($client) {
            $assigned = OcpiProvider::pluck('connection_key')->all();
            $items = [];
            foreach (array_keys(config('ocpi.connections', [])) as $key) {
                $valid = true;
                try {
                    $client->connection(new OcpiProvider(['connection_key' => $key, 'authorized' => true]));
                } catch (OcpiException) {
                    $valid = false;
                }
                $items[] = ['key' => $key, 'configuration_valid' => $valid, 'assigned' => in_array($key, $assigned, true)];
            }

            return ['data' => $items, 'external_requests_made' => false, 'partner_acceptance_verified' => false];
        });

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function owners(Request $request)
    {
        $page = $this->currentAdminRead($request, static function () use ($request) {
            $v = $request->validate(['q' => 'nullable|string|max:80']);

            return User::where('role', 'vendor')->where('account_status', 'active')
                ->when(trim($v['q'] ?? '') !== '', fn ($q) => $q->where('name', 'like', '%'.trim($v['q']).'%'))
                ->orderBy('id')->paginate(25, ['id', 'name']);
        });

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }
}
