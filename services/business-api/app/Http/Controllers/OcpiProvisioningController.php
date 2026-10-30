<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerClient;
use Illuminate\Http\Request;

class OcpiProvisioningController extends Controller
{
    /** Structural readiness only: no endpoint, token name/value or outbound probe. */
    public function connections(Request $request, PartnerClient $client)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $assigned = OcpiProvider::pluck('connection_key')->all();
        $items = [];
        foreach (array_keys(config('ocpi.connections', [])) as $key) {
            $valid = true;
            try {
                $client->connection(new OcpiProvider(['connection_key' => $key, 'authorized' => true]));
            } catch (OcpiException $e) {
                $valid = false;
            }
            $items[] = ['key' => $key, 'configuration_valid' => $valid, 'assigned' => in_array($key, $assigned, true)];
        }

        return response()->json(['data' => $items, 'external_requests_made' => false, 'partner_acceptance_verified' => false]);
    }

    public function owners(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $v = $request->validate(['q' => 'nullable|string|max:80']);

        return User::where('role', 'vendor')->when(trim($v['q'] ?? '') !== '', fn ($q) => $q->where('name', 'like', '%'.trim($v['q']).'%'))->orderBy('id')->paginate(25, ['id', 'name']);
    }
}
