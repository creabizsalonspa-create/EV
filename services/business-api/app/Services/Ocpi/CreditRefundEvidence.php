<?php

namespace App\Services\Ocpi;

use App\Models\Finance\Invoice;
use App\Models\OcpiRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** A source link for an existing manually reviewed refund, never refund authority. */
class CreditRefundEvidence
{
    public function fullReversal(Invoice $invoice, int $creditId): OcpiRecord
    {
        $credit = $this->require($invoice, $creditId);
        $receipt = DB::table('ocpi_test_invoice_receipts')->where('invoice_id', $invoice->id)->first();
        $original = OcpiRecord::findOrFail($receipt->source_cdr_record_id);
        $normalizer = app(RecordNormalizer::class);
        try {
            $left = $original->payload;
            $right = $credit->payload;
        } catch (\Throwable) {
            abort(409, 'Source fingerprints require review.');
        }
        abort_unless(is_array($left) && is_array($right) && hash_equals($receipt->source_cdr_hash, $original->content_hash) && hash_equals($receipt->source_cdr_hash, $normalizer->hash($left)) && hash_equals($credit->content_hash, $normalizer->hash($right)), 409, 'Source fingerprints require review.');
        abort_unless(($left['credit'] ?? false) === false && ($right['credit'] ?? false) === true && ($right['credit_reference_id'] ?? null) === $original->external_id && ($left['id'] ?? null) === $original->external_id && ($right['id'] ?? null) === $credit->external_id && ($right['country_code'] ?? null) === $original->country_code && ($right['party_id'] ?? null) === $original->party_id && ($right['currency'] ?? null) === $invoice->currency, 409, 'Raw credit identity requires review.');
        foreach (['start_date_time', 'end_date_time', 'cdr_location', 'cdr_token', 'authorization_reference', 'session_id'] as $field) {
            abort_unless($normalizer->hash([$field => $left[$field] ?? null]) === $normalizer->hash([$field => $right[$field] ?? null]), 409, 'A full credit must retain the original charging identity.');
        }
        $decimal = static function (mixed $value): ?string {
            if (is_int($value) && $value >= 0) {
                $value = (string) $value;
            }
            if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]{0,9})(?:\.[0-9]{1,6})?$/D', $value)) {
                return null;
            }

            return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        };
        abort_unless($decimal($left['total_cost']['excl_vat'] ?? null) !== null, 409, 'Original source cost is unavailable.');
        foreach (['total_energy', 'total_time'] as $field) {
            $value = $decimal($left[$field] ?? null);
            abort_unless($value !== null && $value === $decimal($right[$field] ?? null), 409, 'A partial source credit cannot cancel this invoice.');
        }
        foreach (['total_cost', 'total_energy_cost', 'total_time_cost', 'total_parking_cost', 'total_reservation_cost', 'total_fixed_cost'] as $field) {
            foreach (['excl_vat', 'incl_vat'] as $part) {
                $originalCost = $left[$field][$part] ?? null;
                $creditCost = $right[$field][$part] ?? null;
                abort_unless(($originalCost === null && $creditCost === null) || ($decimal($originalCost) !== null && $decimal($originalCost) === $decimal($creditCost)), 409, 'Source cost components do not fully reverse the invoice.');
            }
        }

        return $credit;
    }

    public function options(Invoice $invoice, int $page, bool $fullReversal = false): array
    {
        $receipt = DB::table('ocpi_test_invoice_receipts')->where('invoice_id', $invoice->id)->first();
        if (! $receipt) {
            $rows = new LengthAwarePaginator([], 0, 25, $page);
        } else {
            $original = OcpiRecord::findOrFail($receipt->source_cdr_record_id);
            abort_unless($original->module === 'cdrs' && ($original->normalized['credit'] ?? false) === false, 409, 'Original source CDR requires review.');
            $rows = OcpiRecord::where('ocpi_provider_id', $original->ocpi_provider_id)->where('module', 'cdrs')
                ->where('country_code', $original->country_code)->where('party_id', $original->party_id)
                ->where('normalized->credit', true)->where('normalized->credit_reference_id', $original->external_id)
                ->where('normalized->currency', $invoice->currency)->where('id', '!=', $original->id)
                ->orderByDesc('id')->paginate(25, ['*'], 'page', $page);
            $reviews = DB::table('finance_refund_requests')->whereIn('ocpi_credit_record_id', $rows->pluck('id'))
                ->get(['ocpi_credit_record_id', 'invoice_id', 'id', 'status'])->keyBy('ocpi_credit_record_id');
            $notes = DB::table('finance_unpaid_credits')->whereIn('credit_record_id', $rows->pluck('id'))->get(['id', 'invoice_id', 'credit_record_id'])->keyBy('credit_record_id');
            $rows->through(function ($credit) use ($invoice, $reviews, $notes, $fullReversal) {
                $review = $reviews->get($credit->id);
                $own = $review && $review->invoice_id === $invoice->id;
                $note = $notes->get($credit->id);
                $matches = false;
                if ($fullReversal) {
                    try {
                        $this->fullReversal($invoice, $credit->id);
                        $matches = true;
                    } catch (HttpExceptionInterface|ModelNotFoundException) {
                        // A partial or damaged source remains evidence only.
                    }
                }

                return ['id' => $credit->id, 'external_id' => $credit->external_id,
                    'evidence' => $this->present($invoice, $credit->id), 'available_for_review' => $review === null && $note === null,
                    'existing_unpaid_credit_id' => $note && $note->invoice_id === $invoice->id ? $note->id : null,
                    'existing_refund_request_id' => $own ? $review->id : null,
                    'existing_refund_state' => $review ? ($own ? $review->status : 'requires_review') : null] + ($fullReversal ? ['full_reversal_source_match' => $matches] : []);
            });
        }

        return $rows->toArray() + ['schema_version' => 1, 'invoice_id' => $invoice->id, 'financial_authority' => false, 'automatic_refund' => false];
    }

    public function require(Invoice $invoice, int $creditId): OcpiRecord
    {
        $receipt = DB::table('ocpi_test_invoice_receipts')->where('invoice_id', $invoice->id)->first();
        abort_unless($receipt, 409, 'This invoice has no canonical OCPI source receipt.');
        $original = OcpiRecord::findOrFail($receipt->source_cdr_record_id);
        $credit = OcpiRecord::where('ocpi_provider_id', $original->ocpi_provider_id)->where('country_code', $original->country_code)->where('party_id', $original->party_id)->findOrFail($creditId);
        abort_unless($credit->module === 'cdrs' && $credit->id !== $original->id && $credit->ocpi_provider_id === $original->ocpi_provider_id && $credit->country_code === $original->country_code && $credit->party_id === $original->party_id && ($credit->normalized['credit'] ?? false) === true && ($credit->normalized['credit_reference_id'] ?? null) === $original->external_id && ($credit->normalized['currency'] ?? null) === $invoice->currency, 409, 'Credit source does not match this invoice.');

        return $credit;
    }

    public function present(Invoice $invoice, int $creditId): array
    {
        $base = ['schema_version' => 1, 'invoice_id' => $invoice->id, 'credit_record_id' => $creditId, 'financial_authority' => false, 'automatic_refund' => false];
        try {
            $credit = $this->require($invoice, $creditId);
        } catch (HttpExceptionInterface $e) {
            return $base + ['status' => 'requires_review'];
        } catch (ModelNotFoundException $e) {
            return $base + ['status' => 'requires_review'];
        }
        $n = $credit->normalized;
        $cost = [];
        foreach (['excl_vat', 'incl_vat'] as $field) {
            $value = $n['cost'][$field] ?? null;
            if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,9})(?:\.[0-9]{1,6})?$/D', $value)) {
                $cost[$field] = $value;
            }
        }

        return $base + ['status' => 'source_reference_matched', 'currency' => $invoice->currency, 'reported_cost' => (object) $cost, 'physical_charging_verified' => false];
    }
}
