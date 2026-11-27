<?php

namespace App\Services\Charging;

use App\Http\Controllers\ChargerResetController;
use App\Http\Middleware\AdminSecondFactorGate;
use App\Models\Charging\AdmissionProfile;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Diagnostics authority only; never grants charging/reset/firmware capability. */
final class DiagnosticAuthority
{
    public function binding(Request $request, VendorCompany $company, VendorCharger $charger, bool $write = false, bool $upload = false): object
    {
        $company = $company->fresh();
        $charger = $charger->fresh();
        $actor = app(MaintenanceActor::class);
        if ($charger->source === 'simulator') {
            $binding = app(ChargerResetController::class)->scope($request, $company, $charger, $write);
            $actor->checkBearer($request->user());

            return $binding;
        }
        abort_unless($request->isSecure() || app()->environment(['local', 'testing']), 503, 'HTTPS diagnostic access is required.');
        $actor->check($request->user());
        $actor->checkBearer($request->user());
        app(VendorAccess::class)->require($request->user(), $company, 'inventory.read');
        abort_unless($charger->company_id === $company->id && $charger->source === 'direct_unverified', 404);
        abort_unless($request->user()->id !== $company->owner_id || in_array($request->user()->role, ['vendor', 'admin'], true), 404);
        $site = VendorSite::findOrFail($charger->site_id);
        $station = Station::find($site->station_id);
        abort_unless($site->company_id === $company->id && $station && $station->vendor_id === $company->owner_id && $station->source === 'direct_unverified' && $site->country === $company->country && $station->country === $company->country, 409, 'Owned direct diagnostic inventory is required.');
        abort_unless(app(ChargerSourceRegistration::class)->verified($company, $charger, false), 409, 'Verified current source registration is required.');
        $registration = DB::table('charger_source_registrations')->where('charger_id', $charger->id)->where('state', 'registered')->firstOrFail();
        $binding = DB::table('csms_bindings')->where('id', $registration->binding_id)->firstOrFail();
        abort_unless($binding->station_id === $station->id && $binding->vendor_charger_id === $charger->id && $binding->protocol === $charger->ocpp_protocol && in_array($binding->protocol, ['1.6', '2.0.1'], true), 409);
        if ($write) {
            abort_unless($request->user()->role === 'admin' || ($request->user()->role === 'vendor' && $request->user()->id === $company->owner_id), 404);
            abort_unless($upload || $request->user()->role !== 'admin' || ! AdminSecondFactorGate::required() || AdminSecondFactorGate::verified($request), 403, 'Current administrator verification is required.');
        }
        $binding->diagnostic_direct = true;
        $binding->diagnostic_profile_id = $registration->profile_id;
        $binding->diagnostic_registration_id = $registration->id;
        $binding->diagnostic_source_station_id = $registration->source_station_id;

        return $binding;
    }

    public function currentSource(object $binding): void
    {
        if (empty($binding->diagnostic_direct)) {
            return;
        }
        $charger = VendorCharger::findOrFail($binding->vendor_charger_id);
        $company = VendorCompany::findOrFail($charger->company_id);
        abort_unless(app(ChargerSourceRegistration::class)->verified($company, $charger), 409, 'Current diagnostic source configuration requires review.');
    }

    public function dispatchable(object $binding): void
    {
        if (empty($binding->diagnostic_direct)) {
            return;
        }
        $charger = VendorCharger::findOrFail($binding->vendor_charger_id);
        $company = VendorCompany::findOrFail($charger->company_id);
        app(VendorAccess::class)->operational($company);
        abort_unless(User::whereKey($company->owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists(), 409, 'Current vendor authority is required.');
        $this->currentSource($binding);
        $profile = AdmissionProfile::findOrFail($binding->diagnostic_profile_id);
        abort_unless($profile->enabled && $profile->revoked_at === null && $profile->expires_at?->isFuture() && is_string($profile->credential_digest) && preg_match('/^[a-f0-9]{64}$/D', $profile->credential_digest), 409, 'Active admission credentials are required for diagnostic dispatch.');
        app(ChargerCertificates::class)->usable($profile);
    }

    public function capture(User $actor, object $binding): array
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->role === $actor->role && (int) $current->auth_epoch === (int) $actor->auth_epoch && hash_equals((string) $current->password, (string) $actor->password) && $current->account_status === 'active', 409);
        $factor = $current->role === 'admin' ? DB::table('admin_second_factors')->where('user_id', $current->id)->value('encrypted_secret') : null;
        $profile = ! empty($binding->diagnostic_direct) ? AdmissionProfile::whereKey($binding->diagnostic_profile_id)->lockForUpdate()->firstOrFail() : null;

        return ['consent_role' => $current->role, 'consent_auth_epoch' => $current->auth_epoch, 'consent_password_hash' => hash('sha256', $current->password), 'consent_mfa_hash' => is_string($factor) ? hash('sha256', $factor) : null, 'consent_profile_id' => $profile?->id, 'consent_profile_version' => $profile?->credential_version, 'consent_profile_hash' => $profile ? hash('sha256', $profile->credential_digest) : null, 'consent_registration_id' => $binding->diagnostic_registration_id ?? null];
    }

    public function uploadConsent(object $row, User $actor, object $binding): void
    {
        // Old local simulator fixtures retain their historical adapter semantics.
        // No unbound legacy receipt can acquire production upload authority.
        if ($row->consent_role === null && empty($binding->diagnostic_direct)) {
            return;
        }
        abort_unless($row->consent_role === $actor->role && (int) $row->consent_auth_epoch === (int) $actor->auth_epoch && is_string($row->consent_password_hash) && hash_equals($row->consent_password_hash, hash('sha256', $actor->password)), 404);
        if ($actor->role === 'admin' && $row->consent_mfa_hash !== null) {
            $factor = DB::table('admin_second_factors')->where('user_id', $actor->id)->value('encrypted_secret');
            abort_unless(is_string($factor) && hash_equals($row->consent_mfa_hash, hash('sha256', $factor)), 404);
        }
        if (! empty($binding->diagnostic_direct)) {
            $this->dispatchable($binding);
            $profile = AdmissionProfile::findOrFail($binding->diagnostic_profile_id);
            abort_unless($row->consent_profile_id === $profile->id && $row->consent_profile_version === $profile->credential_version && is_string($row->consent_profile_hash) && hash_equals($row->consent_profile_hash, hash('sha256', $profile->credential_digest)) && $row->consent_registration_id === $binding->diagnostic_registration_id, 404);
        }
    }

    public function evidence(object $binding): string
    {
        return ! empty($binding->diagnostic_direct) ? 'AUTHENTICATED_SOURCE' : 'SIMULATED_SOURCE';
    }
}
