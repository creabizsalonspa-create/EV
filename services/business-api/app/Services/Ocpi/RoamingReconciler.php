<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Services\Charging\SourceConsumer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Customer/source lineage only. No reservation, invoice, collection or settlement writes. */
class RoamingReconciler
{
    public function reconcile(object $order): object
    {
        return DB::transaction(function () use ($order) {
            $p = OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            app(SandboxRoaming::class)->current($row);
            [, $local] = app(SandboxRoaming::class)->policy($p);
            $issuer = $local['roles'][0];
            $target = json_decode(Crypt::decryptString($row->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $uid = Crypt::decryptString($row->token_ciphertext);
            $records = OcpiRecord::where('ocpi_provider_id', $p->id)->where('module', 'sessions')->where('country_code', $target['country_code'])->where('party_id', $target['party_id'])->where('id', '>', $row->source_record_floor)->orderByDesc('id')->limit(1001)->get();
            abort_if($records->count() > 1000, 409, 'Partner history needs bounded manual review.');
            $matches = $records->filter(fn ($r) => app(SandboxRoaming::class)->ownsSourceSession($row, $r));
            $dispatched = DB::table('ocpi_roaming_commands')->where('order_id', $row->id)->where('action', 'START_SESSION')->whereNotNull('attempted_at')->exists();
            if ($matches->count() !== 1 || ! $dispatched) {
                if ($matches->count() > 1 || ($matches->isNotEmpty() && ! $dispatched) || ($matches->isEmpty() && $row->source_session_record_id !== null)) {
                    DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['reconciliation_state' => 'requires_review', 'updated_at' => now()]);
                }

                return DB::table('ocpi_roaming_orders')->find($row->id);
            }
            $session = $matches->first();
            $n = $session->normalized;
            $raw = $session->payload;
            if ($row->source_status === 'COMPLETED' && $row->source_session_hash !== null && ! hash_equals($row->source_session_hash, $session->content_hash)) {
                DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['reconciliation_state' => 'requires_review', 'updated_at' => now()]);

                return DB::table('ocpi_roaming_orders')->find($row->id);
            }
            abort_unless($row->source_session_record_id === null || $row->source_session_record_id === $session->id, 409, 'Session identity requires review.');
            $review = $n['currency'] !== $target['tariff']['currency'] || ! CarbonImmutable::parse($n['start_date_time'])->betweenIncluded(CarbonImmutable::parse($row->created_at)->subSeconds(2), CarbonImmutable::parse($row->expires_at));
            try {
                $wh = SourceConsumer::wh($n['energy_kwh'], 'kWh');
                if ($wh < $row->energy_wh || $wh > 1000000) {
                    $review = true;
                    $wh = $row->energy_wh;
                }
            } catch (\Throwable) {
                $review = true;
                $wh = $row->energy_wh;
            }
            // Price/meter discrepancies must not hide a proven owned active session from stop.

            abort_unless(in_array($n['status'], ['PENDING', 'ACTIVE', 'COMPLETED', 'INVALID'], true), 409, 'Unsupported partner session transition.');
            abort_if($row->source_status === 'COMPLETED' && $n['status'] !== 'COMPLETED', 409, 'Completed source history is immutable.');
            $values = ['source_session_record_id' => $session->id, 'source_session_hash' => $session->content_hash, 'energy_wh' => $wh, 'source_status' => $n['status'], 'source_observed_at' => now(), 'reconciliation_state' => 'awaiting_cdr', 'updated_at' => now()];
            if ($n['status'] === 'ACTIVE') {
                $values['state'] = 'source_active';
            }
            if ($n['status'] === 'COMPLETED') {
                $values['state'] = 'source_completed';
                $values['active_target_key'] = null;
            }
            if ($n['status'] === 'INVALID') {
                $values['reconciliation_state'] = 'requires_review';
            }
            $cdrs = OcpiRecord::where('ocpi_provider_id', $p->id)->where('module', 'cdrs')->where('country_code', $target['country_code'])->where('party_id', $target['party_id'])->where('id', '>', $row->source_record_floor)->orderByDesc('id')->limit(1001)->get();
            abort_if($cdrs->count() > 1000, 409, 'Partner CDR history needs bounded manual review.');
            $claims = $cdrs->filter(function ($r) use ($row, $session, $uid, $issuer) {
                $raw = $r->payload;

                return ($raw['session_id'] ?? null) === $session->external_id && ($raw['authorization_reference'] ?? null) === $row->authorization_reference && ($raw['cdr_token']['uid'] ?? null) === $uid && ($raw['cdr_token']['type'] ?? null) === 'APP_USER' && ($raw['cdr_token']['country_code'] ?? null) === $issuer['country_code'] && ($raw['cdr_token']['party_id'] ?? null) === $issuer['party_id'] && ($raw['cdr_token']['contract_id'] ?? null) === $row->authorization_reference;
            });
            if ($claims->count() > 1) {
                $values['reconciliation_state'] = 'requires_review';
            } elseif ($claims->count() === 1) {
                $cdr = $claims->first();
                $comparison = app(CdrReconciler::class)->inspect($p, $cdr);
                $values['reconciliation_state'] = $comparison['status'];
                $values['source_cdr_record_id'] = $cdr->id;
                $values['source_cdr_hash'] = $cdr->content_hash;
            }
            if ($review) {
                $values['reconciliation_state'] = 'requires_review';
            }
            DB::table('ocpi_roaming_orders')->where('id', $row->id)->update($values);

            return DB::table('ocpi_roaming_orders')->find($row->id);
        }, 3);
    }
}
