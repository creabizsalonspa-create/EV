<?php

namespace App\Http\Controllers;

use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\ChargerSourceRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChargerSourceRegistrationController extends Controller
{
    public function show(Request $request, VendorCompany $company, VendorCharger $charger, ChargerSourceRegistration $registration)
    {
        return DB::transaction(function () use ($request, $company, $charger, $registration) {
            $registration->scope($request, $company, $charger);
            $row = DB::table('charger_source_registrations')->where('charger_id', $charger->id)->first();

            $matches = $row?->state === 'registered' && $registration->verified($company, $charger);

            return response()->json($registration->present($row) + ['current_inventory_matches_registration' => $matches,
                'can_inspect_connection' => $matches && (bool) config('csms.upstream_registration_enabled'), 'can_prepare' => $request->user()->role === 'admin' && (bool) config('csms.upstream_registration_enabled'), 'can_register' => $request->user()->role === 'admin' && (bool) config('csms.upstream_registration_enabled') && $row?->state === 'prepared'])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function connectionHealth(Request $request, VendorCompany $company, VendorCharger $charger, int $registration, ChargerSourceRegistration $source)
    {
        return response()->json($source->connectionHealth($request, $company, $charger, $registration))
            ->header('Cache-Control', 'private, no-store');
    }

    public function prepare(Request $request, VendorCompany $company, VendorCharger $charger, ChargerSourceRegistration $registration)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $value = $request->validate(['identity' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{0,35}$/D'], 'idempotency_key' => 'required|string|max:100']);
        $result = $registration->prepare($request, $company, $charger, $value);

        return response()->json($result, $result['created'] ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function register(Request $request, VendorCompany $company, VendorCharger $charger, int $registration, ChargerSourceRegistration $source)
    {
        abort_unless($request->user()->role === 'admin', 403);

        return response()->json($source->register($request, $company, $charger, $registration))->header('Cache-Control', 'private, no-store');
    }
}
