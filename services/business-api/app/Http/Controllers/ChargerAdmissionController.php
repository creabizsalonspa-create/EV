<?php

namespace App\Http\Controllers;

use App\Models\Charging\AdmissionProfile;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChargerAdmissionController extends Controller
{
    private function scope(Request $r, VendorCompany $company, VendorCharger $charger, bool $write = false): void
    {
        app(VendorAccess::class)->require($r->user(), $company, $write ? 'inventory.write' : 'inventory.read');
        abort_unless($charger->company_id === $company->id, 404);
        // Credential disclosure is more sensitive than ordinary inventory editing.
        if ($write) {
            abort_unless($company->owner_id === $r->user()->id || $r->user()->role === 'admin', 404);
        }
    }

    public function show(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $this->scope($r, $company, $charger);
        $profile = AdmissionProfile::where('charger_id', $charger->id)->first();

        return response()->json(['profile' => $profile, 'can_manage' => $r->user()->role === 'admin' || $company->owner_id === $r->user()->id, 'hardware_verified' => false, 'upstream_registration_verified' => false])->header('Cache-Control', 'no-store');
    }

    public function configure(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $this->scope($r, $company, $charger, true);
        $v = $r->validate(['identity' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/'], 'certificate_sha256' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        abort_unless(in_array($charger->ocpp_protocol, ['1.6', '2.0.1'], true), 409, 'Set an OCPP protocol before provisioning.');
        try {
            return DB::transaction(function () use ($r, $company, $charger, $v) {
                VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
                $this->scope($r, $company, $charger, true);
                app(VendorAccess::class)->operational($company->fresh());
                VendorCharger::whereKey($charger->id)->lockForUpdate()->firstOrFail();
                $old = AdmissionProfile::where('charger_id', $charger->id)->first();
                if ($old) {
                    abort_unless($old->identity === $v['identity'] && $old->certificate_sha256 === ($v['certificate_sha256'] ?? null) && $old->protocol === $charger->ocpp_protocol, 409, 'Identity is immutable; revoke and review changes through operations.');

                    return response()->json($old)->header('Cache-Control', 'no-store');
                }
                $profile = AdmissionProfile::create(['charger_id' => $charger->id, 'identity' => $v['identity'], 'protocol' => $charger->ocpp_protocol, 'certificate_sha256' => $v['certificate_sha256'] ?? null]);
                $this->event($profile, $r->user()->id, 'prepared');

                return response()->json($profile, 201)->header('Cache-Control', 'no-store');
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Charger identity is already assigned.');
        }
    }

    public function credential(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $this->scope($r, $company, $charger, true);
        abort_if(app()->environment('production') && ! $r->isSecure(), 503, 'Charger credentials require HTTPS in production.');
        $v = $r->validate(['expected_version' => 'required|integer|min:0', 'days' => 'required|integer|between:1,90']);

        return DB::transaction(function () use ($r, $company, $charger, $v) {
            VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->scope($r, $company, $charger, true);
            app(VendorAccess::class)->operational($company->fresh());
            $p = AdmissionProfile::where('charger_id', $charger->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->credential_version === (int) $v['expected_version'], 409, 'Credential changed; refresh before another explicit rotation.');
            $secret = bin2hex(random_bytes(20));
            $p->update(['credential_digest' => hash('sha256', $secret), 'credential_version' => $p->credential_version + 1, 'expires_at' => now()->addDays($v['days']), 'enabled' => true, 'revoked_at' => null]);
            $this->event($p, $r->user()->id, 'rotated');

            return response()->json(['profile' => $p, 'secret' => $secret, 'one_time' => true, 'hardware_verified' => false, 'upstream_registration_verified' => false], 201)->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
        }, 3);
    }

    public function revoke(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $this->scope($r, $company, $charger, true);

        return DB::transaction(function () use ($r, $company, $charger) {
            VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->scope($r, $company, $charger, true);
            $p = AdmissionProfile::where('charger_id', $charger->id)->lockForUpdate()->firstOrFail();
            if ($p->enabled || $p->revoked_at === null) {
                $p->update(['enabled' => false, 'revoked_at' => now(), 'credential_digest' => null, 'credential_version' => $p->credential_version + 1]);
                $this->event($p, $r->user()->id, 'revoked');
            }

            return response()->json($p)->header('Cache-Control', 'no-store');
        }, 3);
    }

    private function event(AdmissionProfile $p, int $actor, string $action): void
    {
        DB::table('charger_admission_events')->insert(['profile_id' => $p->id, 'actor_id' => $actor, 'action' => $action, 'credential_version' => $p->credential_version, 'created_at' => now()]);
    }
}
