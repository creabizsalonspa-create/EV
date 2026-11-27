<?php

namespace App\Services\Charging;

use App\Models\Charging\AdmissionProfile;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Support\Facades\DB;

/** Operator reconciliation record, not live source or hardware attestation. */
final class DirectSourceRegistrationEpoch
{
    private function fingerprint(object $row, array $authority): string
    {
        return hash('sha256', json_encode([
            $row->id, $row->charger_id, $row->binding_id, $row->profile_id,
            $row->source_station_id, $row->source_generation, $row->source_receipt_hash,
            $row->verified_at, $row->payload_hash, $authority,
        ], JSON_THROW_ON_ERROR));
    }

    public function reconcile(int $registration, string $receiptHash, string $ticket): bool
    {
        abort_unless($registration > 0 && preg_match('/^[a-f0-9]{64}$/D', $receiptHash)
            && preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $ticket), 422, 'Exact receipt and operator ticket required.');

        return DB::transaction(function () use ($registration, $receiptHash, $ticket) {
            // Discover the lock targets without trusting the preliminary read.
            // Lock company, charger, site and station before registration/binding.
            $candidate = DB::table('charger_source_registrations')->where('id', $registration)->first();
            abort_unless($candidate, 409, 'Registration requires review.');
            $inventory = VendorCharger::findOrFail($candidate->charger_id);
            $company = VendorCompany::whereKey($inventory->company_id)->lockForUpdate()->firstOrFail();
            $charger = VendorCharger::whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            abort_unless($charger->company_id === $company->id && $charger->source === 'direct_unverified'
                && in_array($charger->ocpp_protocol, ['1.6', '2.0.1'], true), 409, 'Owned direct inventory required.');
            $site = VendorSite::whereKey($charger->site_id)->lockForUpdate()->firstOrFail();
            abort_unless($site->company_id === $company->id && $site->country === $company->country && $site->station_id, 409);
            $station = Station::whereKey($site->station_id)->lockForUpdate()->firstOrFail();
            abort_unless($station->vendor_id === $company->owner_id && $station->country === $company->country
                && $station->source === 'direct_unverified', 409);
            app(VendorAccess::class)->operational($company);
            abort_unless(User::whereKey($company->owner_id)->where('role', 'vendor')
                ->where('account_status', 'active')->lockForUpdate()->first(), 409);
            $row = DB::table('charger_source_registrations')->where('id', $registration)->lockForUpdate()->first();
            abort_unless($row && $row->state === 'registered' && is_int($row->source_station_id)
                && $row->source_station_id > 0 && $row->verified_at !== null
                && is_string($row->source_receipt_hash) && hash_equals($row->source_receipt_hash, $receiptHash), 409,
                'Original registration receipt requires review.');
            abort_unless($row->charger_id === $charger->id, 409);
            $binding = DB::table('csms_bindings')->where('id', $row->binding_id)->lockForUpdate()->first();
            $profile = AdmissionProfile::whereKey($row->profile_id)->lockForUpdate()->first();
            abort_unless($binding && $profile && $binding->vendor_charger_id === $charger->id
                && $binding->station_id === $station->id && $profile->charger_id === $charger->id
                && $profile->identity === $binding->identifier && $profile->protocol === $charger->ocpp_protocol
                && $binding->protocol === $charger->ocpp_protocol, 409, 'Original registration lineage changed.');
            $expectedPayload = hash('sha256', json_encode([$company->id, $company->owner_id,
                $charger->id, $station->id, $binding->identifier, $charger->ocpp_protocol,
                $charger->manufacturer, $charger->model, $row->source_generation], JSON_THROW_ON_ERROR));
            $expectedReceipt = hash('sha256', json_encode([
                'id' => $row->source_station_id, 'tenantId' => 1,
                'ocppConnectionName' => $binding->identifier, 'protocol' => 'ocpp'.$binding->protocol,
                'isOnline' => false, 'chargePointVendor' => $charger->manufacturer,
                'chargePointModel' => $charger->model,
            ], JSON_THROW_ON_ERROR));
            abort_unless(is_string($row->payload_hash) && hash_equals($expectedPayload, $row->payload_hash)
                && hash_equals($expectedReceipt, $receiptHash), 409, 'Original registration payload or receipt changed.');
            $authority = app(DirectSourceAuthority::class)->requireCurrent();
            abort_unless(hash_equals($row->source_generation, hash('sha256', 'citrineos-v2.1.0-tenant-1|'.config('csms.graphql_url'))),
                409, 'Registration source origin has changed.');
            $fingerprint = $this->fingerprint($row, $authority);
            $existing = DB::table('direct_source_registration_epochs')->where('registration_id', $registration)
                ->where('epoch', $authority[0])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->registration_fingerprint, $fingerprint), 409,
                    'Reconciled registration evidence is immutable.');

                return false;
            }
            DB::table('direct_source_registration_epochs')->insert([
                'registration_id' => $registration, 'epoch' => $authority[0],
                'registration_fingerprint' => $fingerprint, 'ticket_reference' => $ticket,
                'reviewed_at' => now()->format('Y-m-d H:i:s.u'),
            ]);

            return true;
        }, 3);
    }

    /** Caller retains the registration and authority locks through its local decision. */
    public function requireCurrent(object $row, array $authority): void
    {
        $entry = DB::table('direct_source_registration_epochs')->where('registration_id', $row->id)
            ->where('epoch', $authority[0])->first();
        abort_unless($entry && hash_equals($entry->registration_fingerprint, $this->fingerprint($row, $authority)),
            409, 'Direct registration requires explicit reconciliation for the current source authority.');
    }
}
