<?php

namespace App\Http\Controllers;

use App\Models\Charging\AdmissionProfile;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\ChargerCertificates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChargerCertificateController extends Controller
{
    public function index(Request $r, VendorCompany $company, VendorCharger $charger, ChargerCertificates $service)
    {
        $service->scope($r->user(), $company, $charger);

        return DB::transaction(function () use ($r, $company, $charger, $service) {
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($r->user()->id);
            $charger = VendorCharger::findOrFail($charger->id);
            $service->scope($actor, $company, $charger);
            $p = AdmissionProfile::where('charger_id', $charger->id)->firstOrFail();
            $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
            $rows = DB::table('charger_certificates')->where('profile_id', $p->id)->orderByDesc('id')->paginate(25)->through(fn ($cert) => $service->present($cert, $p))->toArray();

            return response()->json($rows + ['schema_version' => 1, 'profile_id' => $p->id, 'identity' => $p->identity, 'credential_version' => $p->credential_version, 'managed_certificate_id' => $p->managed_certificate_id, 'can_manage' => $actor->role === 'admin' || $actor->id === $company->owner_id, 'enrollment_enabled' => (bool) config('csms.certificate_management_enabled'), 'device_commands_sent' => false, 'connection_close_requested' => false, 'gateway_recheck_may_disconnect' => true])->header('Cache-Control', 'private, no-store');
        });
    }

    public function apply(Request $r, VendorCompany $company, VendorCharger $charger, string $action, ChargerCertificates $service)
    {
        $service->scope($r->user(), $company, $charger, true);
        abort_unless(in_array($action, ['enroll', 'revoke'], true), 404);
        abort_if(app()->environment('production') && ! $r->isSecure(), 503);
        $v = $r->validate(['idempotency_key' => 'required|uuid', 'expected_actor_id' => 'required|integer|min:1', 'expected_profile_id' => 'required|integer|min:1', 'expected_version' => 'required|integer|min:0', 'consent' => 'required|boolean', 'reason' => 'required|string|min:10|max:1000', 'public_certificate' => $action === 'enroll' ? 'required|string|max:16384' : 'prohibited', 'certificate_id' => $action === 'revoke' ? 'required|integer|min:1' : 'prohibited']);
        foreach (['expected_actor_id', 'expected_profile_id', 'expected_version', 'certificate_id'] as $key) {
            if (isset($v[$key])) {
                $v[$key] = (int) $v[$key];
            }
        }
        $v['consent'] = (bool) $v['consent'];
        $v['idempotency_key'] = strtolower($v['idempotency_key']);

        return response()->json($service->apply($r->user(), $company, $charger, $action, $v))->header('Cache-Control', 'private, no-store');
    }

    public function resolve(Request $r, VendorCompany $company, VendorCharger $charger, ChargerCertificates $service)
    {
        $service->scope($r->user(), $company, $charger, true);
        abort_if(app()->environment('production') && ! $r->isSecure(), 503);
        $v = $r->validate(['idempotency_key' => 'required|uuid', 'expected_actor_id' => 'required|integer|min:1', 'expected_profile_id' => 'required|integer|min:1', 'expected_action' => 'required|in:enroll,revoke', 'cancel_unissued' => 'required|boolean']);
        $v['expected_actor_id'] = (int) $v['expected_actor_id'];
        $v['expected_profile_id'] = (int) $v['expected_profile_id'];
        $v['cancel_unissued'] = (bool) $v['cancel_unissued'];
        $v['idempotency_key'] = strtolower($v['idempotency_key']);

        return response()->json($service->resolve($r->user(), $company, $charger, $v))->header('Cache-Control', 'private, no-store');
    }
}
