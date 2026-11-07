<?php

namespace App\Services\Finance;

use App\Models\ChargingSession;
use App\Models\Finance\Invoice;
use App\Models\OcpiRecord;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Private evidence inspection only. Never grants command or payment authority. */
class ChargingProvenance
{
    public function inspect(Invoice $invoice): array
    {
        $base = ['schema_version' => 1, 'invoice_id' => $invoice->id, 'energy_wh' => $invoice->energy_wh, 'evidence' => 'SIMULATED_ONLY', 'physical_charging_verified' => false, 'financial_records_changed' => false, 'payment_authority' => false, 'charging_command_authority' => false];
        $session = ChargingSession::find($invoice->charging_session_id);
        $receipt = DB::table('ocpi_test_invoice_receipts')->where('invoice_id', $invoice->id)->first();
        if ($receipt) {
            $valid = false;
            try {
                $facts = json_decode(Crypt::decryptString($receipt->snapshot_ciphertext), true, flags: JSON_THROW_ON_ERROR);
                $source = OcpiRecord::find($receipt->source_session_record_id);
                $cdr = OcpiRecord::find($receipt->source_cdr_record_id);
                $valid = $session && $session->reservation_id === null && $session->roaming_order_id === $receipt->order_id && $session->status === 'stopped' && $session->id === $receipt->charging_session_id && $session->energy_wh === $invoice->energy_wh
                    && $source && $cdr && $source->module === 'sessions' && $cdr->module === 'cdrs' && $source->ocpi_provider_id === $cdr->ocpi_provider_id
                    && $receipt->actor_id === $invoice->user_id && ($facts['vendor_id'] ?? null) === $invoice->vendor_id && ($facts['order_id'] ?? null) === $receipt->order_id
                    && ($facts['provider_id'] ?? null) === $source->ocpi_provider_id && ($facts['source_session_record_id'] ?? null) === $source->id && ($facts['source_cdr_record_id'] ?? null) === $cdr->id
                    && $this->digest($receipt->source_session_hash) && $this->digest($receipt->source_cdr_hash)
                    && hash_equals($receipt->source_session_hash, $source->content_hash) && hash_equals($receipt->source_cdr_hash, $cdr->content_hash)
                    && ($facts['energy_wh'] ?? null) === $invoice->energy_wh && ($facts['total_minor'] ?? null) === $invoice->total_minor && ($facts['currency'] ?? null) === $invoice->currency && ($facts['minor_units'] ?? null) === $invoice->minor_units
                    && ($facts['source_session_hash'] ?? null) === $receipt->source_session_hash && ($facts['source_cdr_hash'] ?? null) === $receipt->source_cdr_hash;
            } catch (\Throwable) {
                // Corrupt/unavailable private proof must never be upgraded to verification.
            }

            return $base + ['kind' => 'OCPI_SANDBOX', 'status' => $valid ? 'verified_simulated' : 'requires_review', 'source_verified' => $valid, 'protocol' => 'OCPI 2.2.1', 'proofs' => $valid ? ['session_sha256' => $receipt->source_session_hash, 'cdr_sha256' => $receipt->source_cdr_hash] : (object) []];
        }
        $link = DB::table('csms_session_links')->where('charging_session_id', $invoice->charging_session_id)->first();
        if ($link) {
            $started = DB::table('csms_inbox_events')->where('link_id', $link->id)->where('type', 'started')->where('outcome', 'applied')->orderBy('sequence')->first();
            $ended = DB::table('csms_inbox_events')->where('link_id', $link->id)->where('type', 'ended')->where('outcome', 'applied')->where('sequence', $link->last_sequence)->first();
            $valid = $session && $session->roaming_order_id === null && $session->status === 'stopped' && $session->energy_wh === $invoice->energy_wh
                && in_array($link->protocol, ['1.6', '2.0.1'], true) && $link->transaction_id !== null && $link->started_at !== null && $link->ended_at !== null
                && $started && $ended && $started->meter_wh === $link->meter_start_wh && $ended->meter_wh === $link->last_meter_wh
                && $this->digest($started->fingerprint) && $this->digest($ended->fingerprint)
                && $ended->sequence > $started->sequence && $ended->meter_wh >= $started->meter_wh && $ended->meter_wh - $started->meter_wh === $invoice->energy_wh;

            return $base + ['kind' => 'OCPP_SIMULATOR', 'status' => $valid ? 'verified_simulated' : 'requires_review', 'source_verified' => $valid, 'protocol' => $valid ? 'OCPP '.$link->protocol : null, 'proofs' => $valid ? ['start_sha256' => $started->fingerprint, 'end_sha256' => $ended->fingerprint] : (object) []];
        }

        return $base + ['kind' => 'APP_SIMULATION', 'status' => $session && $session->roaming_order_id === null ? 'not_source_verified' : 'requires_review', 'source_verified' => false, 'protocol' => null, 'proofs' => (object) []];
    }

    private function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
