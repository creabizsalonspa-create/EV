<?php

namespace App\Services\Charging;

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
            $row = DB::table('charger_source_registrations')->where('id', $registration)->lockForUpdate()->first();
            abort_unless($row && $row->state === 'registered' && is_int($row->source_station_id)
                && $row->source_station_id > 0 && $row->verified_at !== null
                && is_string($row->source_receipt_hash) && hash_equals($row->source_receipt_hash, $receiptHash), 409,
                'Original registration receipt requires review.');
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
