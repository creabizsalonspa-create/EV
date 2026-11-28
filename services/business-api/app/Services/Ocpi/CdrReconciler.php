<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;

/** A source comparison, never authorization to bill or a second financial ledger. */
class CdrReconciler
{
    public function inspect(OcpiProvider $provider, OcpiRecord $cdr): array
    {
        if ($cdr->module !== 'cdrs' || $cdr->ocpi_provider_id !== $provider->id) {
            throw new OcpiException('A CDR from this provider is required.');
        }
        $issues = [];
        $integrity = app(StoredSourceIntegrity::class);
        $stored = OcpiRecord::whereKey($cdr->id)->where('ocpi_provider_id', $provider->id)
            ->where('module', 'cdrs')->first();
        if (! $stored || ! $integrity->consistent($stored) || ! $integrity->consistent($cdr)
            || $stored->content_hash !== $cdr->content_hash) {
            $issues[] = 'cdr_source_integrity_requires_review';
        }
        try {
            $n = $cdr->normalized;
            $raw = $cdr->payload;
            if (! is_array($n) || ! is_array($raw)) {
                throw new OcpiException('Source is unreadable.');
            }
        } catch (\Throwable) {
            return ['status' => 'requires_review', 'issues' => ['cdr_source_integrity_requires_review'], 'session_record_id' => null,
                'evidence' => 'PARTNER_REPORTED_UNVERIFIED', 'billing_eligible' => false, 'local_invoice_created' => false, 'credit_review' => null];
        }
        $sessionId = $n['session_id'] ?? ($raw['session_id'] ?? null);
        // Source reconciliation may run in a long-lived worker after an
        // owner, credential configuration or authorization revision changes.
        // A passed Eloquent model is only a snapshot; never represent a
        // revoked or transferred provider as currently source-consistent.
        $current = OcpiProvider::find($provider->id);
        if (! $current || ! $provider->authorized || $provider->version !== '2.2.1'
            || ! $current->authorized || $current->version !== '2.2.1'
            || $current->owner_id !== $provider->owner_id
            || $current->connection_key !== $provider->connection_key
            || $current->authorization_revision !== $provider->authorization_revision) {
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
            if (! $integrity->consistent($session)) {
                $issues[] = 'session_source_integrity_requires_review';
            } elseif (app(SessionMeterHistory::class)->requiresReview($session)) {
                $issues[] = 'session_meter_history_requires_review';
            }
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
                // Historical or corrupted normalized source rows are not
                // guaranteed to satisfy the current strict ingestion schema.
                // Carbon::parse would accept relative dates or silently roll
                // invalid calendar days forward, and may throw on bad input.
                $cdrInstant = $this->strictUtcInstant($n[$field] ?? null);
                $sessionInstant = $this->strictUtcInstant($s[$field] ?? null);
                if ($cdrInstant === null || $sessionInstant === null || $cdrInstant !== $sessionInstant) {
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
            $issues = [...$issues, ...app(CdrSessionClaims::class)->findings($cdr, $sessionId)];
        }

        return ['status' => $issues === [] ? 'source_consistent' : 'requires_review', 'issues' => $issues, 'session_record_id' => $session?->id,
            'evidence' => 'PARTNER_REPORTED_UNVERIFIED', 'billing_eligible' => false, 'local_invoice_created' => false, 'credit_review' => in_array('cdr_source_integrity_requires_review', $issues, true) ? null : $this->creditReference($provider, $cdr)];
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
            if (! $original) {
                $state = 'not_found';
            } elseif (! app(StoredSourceIntegrity::class)->consistent($cdr) || ! app(StoredSourceIntegrity::class)->consistent($original)
                || $original->id === $cdr->id || ($original->payload['credit'] ?? false) || isset($original->payload['credit_reference_id'])) {
                $state = 'invalid_target';
            } else {
                // Existence of a referenced CDR is not proof that a credit is
                // for the same charging source. Require the source identities
                // to agree before marking the reference as structurally matched.
                // A partial credit may change amounts, so do not compare price
                // or use this result to authorize any financial adjustment.
                $credit = $cdr->normalized;
                $source = $original->normalized;
                $identity = ['session_id', 'currency', 'location_id', 'evse_uid', 'connector_id'];
                $state = collect($identity)->every(fn ($field) => is_string($credit[$field] ?? null)
                    && $credit[$field] !== '' && $credit[$field] === ($source[$field] ?? null))
                    ? 'matched' : 'identity_mismatch';
            }
        }

        // A same-party source reference is not consent to change an invoice,
        // approve a refund, settle a partner or release a financial hold.
        return ['reference_state' => $state, 'financial_authority' => false, 'automatic_refund' => false];
    }

    private function strictUtcInstant(mixed $value): ?string
    {
        if (! is_string($value)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D', $value)) {
            return null;
        }

        $format = '!Y-m-d\TH:i:s'.(str_contains($value, '.') ? '.u' : '').'\Z';
        $instant = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
        if ($instant === false || \DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        // Normalization to six fractional digits makes equal instants compare
        // exactly, without floats, server timezone, or relative-date parsing.
        return $instant->format('Y-m-d\TH:i:s.u\Z');
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
