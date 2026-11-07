<?php

namespace App\Services\Ocpi;

use App\Models\Finance\Invoice;
use App\Models\OcpiRecord;
use Illuminate\Support\Facades\DB;

/** A source link for an existing manually reviewed refund, never refund authority. */
class CreditRefundEvidence
{
    public function require(Invoice $invoice, int $creditId): OcpiRecord
    {
        $receipt = DB::table('ocpi_test_invoice_receipts')->where('invoice_id', $invoice->id)->first();
        abort_unless($receipt, 409, 'This invoice has no canonical OCPI source receipt.');
        $original = OcpiRecord::findOrFail($receipt->source_cdr_record_id);
        $credit = OcpiRecord::where('ocpi_provider_id', $original->ocpi_provider_id)->where('country_code', $original->country_code)->where('party_id', $original->party_id)->findOrFail($creditId);
        abort_unless($credit->module === 'cdrs' && $credit->id !== $original->id && $credit->ocpi_provider_id === $original->ocpi_provider_id && $credit->country_code === $original->country_code && $credit->party_id === $original->party_id && ($credit->normalized['credit'] ?? false) === true && ($credit->normalized['credit_reference_id'] ?? null) === $original->external_id && ($credit->normalized['currency'] ?? null) === $invoice->currency, 409, 'Credit source does not match this invoice.');

        return $credit;
    }
}
