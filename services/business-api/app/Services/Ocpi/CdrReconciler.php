<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use Carbon\CarbonImmutable;

/** A source comparison, never authorization to bill or a second financial ledger. */
class CdrReconciler
{
    private array $claims = [];

    public function inspect(OcpiProvider $provider, OcpiRecord $cdr): array
    {
        if ($cdr->module !== 'cdrs' || $cdr->ocpi_provider_id !== $provider->id) {
            throw new OcpiException('A CDR from this provider is required.');
        }
        $issues = [];
        $n = $cdr->normalized;
        $raw = $cdr->payload;
        $sessionId = $n['session_id'] ?? ($raw['session_id'] ?? null);
        if (! $provider->authorized || $provider->version !== '2.2.1') {
            $issues[] = 'partner_not_authorized_and_negotiated';
        }
        if (($raw['credit'] ?? false) || isset($raw['credit_reference_id'])) {
            $issues[] = 'credit_requires_separate_reconciliation';
        }
        $session = null;
        if (! is_string($sessionId) || $sessionId === '' || strlen($sessionId) > 64) {
            $issues[] = 'session_reference_missing';
        } else {
            $session = OcpiRecord::where('ocpi_provider_id', $provider->id)->where('module', 'sessions')->where('country_code', $cdr->country_code)->where('party_id', $cdr->party_id)->where('external_id', $sessionId)->first();
            if (! $session) {
                $issues[] = 'session_not_received';
            }
        }
        if ($session) {
            $s = $session->normalized;
            if (($s['status'] ?? null) !== 'COMPLETED') {
                $issues[] = 'session_not_completed';
            }
            foreach (['currency', 'location_id', 'evse_uid', 'connector_id'] as $field) {
                if (! isset($n[$field], $s[$field]) || $n[$field] !== $s[$field]) {
                    $issues[] = $field.'_mismatch';
                }
            }
            foreach (['start_date_time', 'end_date_time'] as $field) {
                if (empty($n[$field]) || empty($s[$field]) || ! CarbonImmutable::parse($n[$field])->equalTo(CarbonImmutable::parse($s[$field]))) {
                    $issues[] = $field.'_mismatch';
                }
            }
            $energy = $this->decimal($n['energy_kwh'] ?? null);
            $sessionEnergy = $this->decimal($s['energy_kwh'] ?? null);
            if ($energy === null || $sessionEnergy === null) {
                $issues[] = 'energy_precision_unsupported';
            } elseif ($energy !== $sessionEnergy) {
                $issues[] = 'energy_mismatch';
            }
            foreach (['excl_vat', 'incl_vat'] as $field) {
                $left = $n['cost'][$field] ?? null;
                $right = $s['cost'][$field] ?? null;
                if ($field === 'incl_vat' && $left === null && $right === null) {
                    continue;
                }
                $a = $this->decimal($left);
                $b = $this->decimal($right);
                if ($a === null || $b === null) {
                    $issues[] = 'cost_'.$field.'_missing_or_unsupported';
                } elseif ($a !== $b) {
                    $issues[] = 'cost_'.$field.'_mismatch';
                }
            }
            // Multiple CDRs claiming the same source session cannot be counted twice.
            $key = $provider->id.':'.$cdr->country_code.':'.$cdr->party_id;
            if (! isset($this->claims[$key])) {
                $this->claims[$key] = OcpiRecord::where('ocpi_provider_id', $provider->id)->where('module', 'cdrs')->where('country_code', $cdr->country_code)->where('party_id', $cdr->party_id)->limit(1001)->pluck('normalized');
            }
            $claims = $this->claims[$key];
            if ($claims->count() > 1000) {
                $issues[] = 'history_exceeds_comparison_limit';
            }
            if ($claims->contains(fn ($claim) => empty($claim['session_id']))) {
                $issues[] = 'history_contains_unlinked_cdrs';
            }
            if ($claims->filter(fn ($claim) => ($claim['session_id'] ?? null) === $sessionId)->count() > 1) {
                $issues[] = 'multiple_cdrs_for_session';
            }
        }

        return ['status' => $issues === [] ? 'source_consistent' : 'requires_review', 'issues' => $issues, 'session_record_id' => $session?->id,
            'evidence' => 'PARTNER_REPORTED_UNVERIFIED', 'billing_eligible' => false, 'local_invoice_created' => false, 'credit_review' => $this->creditReference($provider, $cdr)];
    }

    private function creditReference(OcpiProvider $provider, OcpiRecord $cdr): ?array
    {
        $raw = $cdr->payload;
        if (($cdr->normalized['credit'] ?? (bool) ($raw['credit'] ?? false)) !== true) {
            return null;
        }
        $reference = $raw['credit_reference_id'] ?? null;
        $state = 'missing';
        if (is_string($reference) && $reference !== '' && strlen($reference) <= 64) {
            $original = OcpiRecord::where('ocpi_provider_id', $provider->id)->where('module', 'cdrs')->where('country_code', $cdr->country_code)->where('party_id', $cdr->party_id)->where('external_id', $reference)->first();
            $state = ! $original ? 'not_found' : ($original->id === $cdr->id || ($original->payload['credit'] ?? false) || isset($original->payload['credit_reference_id']) ? 'invalid_target' : 'matched');
        }

        // A same-party source reference is not consent to change an invoice,
        // approve a refund, settle a partner or release a financial hold.
        return ['reference_state' => $state, 'financial_authority' => false, 'automatic_refund' => false];
    }

    private function decimal(mixed $value): ?string
    {
        // Exact decimal strings only. Never round a partner discrepancy with binary floats.
        if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,6}))?$/D', $value, $m)) {
            return null;
        }
        $fraction = rtrim($m[2] ?? '', '0');

        return $m[1].($fraction === '' ? '' : '.'.$fraction);
    }
}
