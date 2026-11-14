<?php

namespace App\Services\Ocpi;

use App\Models\Finance\Invoice;
use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Services\Finance\FinanceService;
use Illuminate\Support\Facades\DB;

/** Read-only lineage into the canonical ledger; no partner settlement authority. */
class PartnerBillingReconciliation
{
    public function page(OcpiProvider $provider, array $filters): array
    {
        $rows = $this->query($provider, $filters)->paginate(25, ['*'], 'page', $filters['page'] ?? 1);
        $rows->through(fn ($cdr) => $this->row($provider, $cdr));

        return $rows->toArray() + ['schema_version' => 1, 'provider_id' => $provider->id, 'read_only' => true, 'billing_authority' => false, 'payment_execution' => false];
    }

    public function exportRows(OcpiProvider $provider, array $filters): array
    {
        // Fail closed instead of silently exporting only a page of a larger report.
        $records = $this->query($provider, $filters)->limit(251)->get();
        abort_if($records->count() > 250, 409, 'Narrow partner filters to at most 250 source records.');

        return $records->map(fn ($cdr) => $this->row($provider, $cdr))->all();
    }

    private function query(OcpiProvider $provider, array $filters)
    {
        return OcpiRecord::where('ocpi_provider_id', $provider->id)->where('module', 'cdrs')
            ->when($filters['currency'] ?? null, fn ($q, $currency) => $q->where('normalized->currency', $currency))
            ->when($filters['country_code'] ?? null, fn ($q, $country) => $q->where('country_code', $country))
            ->when($filters['party_id'] ?? null, fn ($q, $party) => $q->where('party_id', $party))
            ->when(($filters['kind'] ?? 'all') === 'credits', fn ($q) => $q->where('normalized->credit', true))
            ->when(($filters['kind'] ?? 'all') === 'charges', fn ($q) => $q->where(fn ($x) => $x->where('normalized->credit', false)->orWhereNull('normalized->credit')))
            ->orderByDesc('id');
    }

    private function row(OcpiProvider $provider, OcpiRecord $cdr): array
    {
        return ['id' => $cdr->id, 'external_id' => $cdr->external_id, 'country_code' => $cdr->country_code, 'party_id' => $cdr->party_id,
            'currency' => $cdr->normalized['currency'] ?? null, 'credit' => ($cdr->normalized['credit'] ?? false) === true,
            'received_at' => $cdr->received_at, 'source' => (new CdrReconciler)->inspect($provider, $cdr), 'billing' => $this->lineage($provider, $cdr)];
    }

    private function lineage(OcpiProvider $provider, OcpiRecord $cdr): array
    {
        $credit = ($cdr->normalized['credit'] ?? false) === true;
        $original = $cdr;
        if ($credit) {
            $reference = $cdr->normalized['credit_reference_id'] ?? null;
            $original = is_string($reference) ? OcpiRecord::where('ocpi_provider_id', $provider->id)->where('module', 'cdrs')->where('country_code', $cdr->country_code)->where('party_id', $cdr->party_id)->where('external_id', $reference)->first() : null;
            if (! $original || ($original->normalized['credit'] ?? false) === true || $original->id === $cdr->id) {
                return ['status' => 'original_requires_review'];
            }
        }
        $receipt = DB::table('ocpi_test_invoice_receipts')->where('source_cdr_record_id', $original->id)->first();
        if (! $receipt) {
            return ['status' => 'not_invoiced'];
        }
        $invoice = Invoice::find($receipt->invoice_id);
        // A provider reassignment never grants access to its previous owner's invoices.
        if (! $invoice || $invoice->vendor_id !== $provider->owner_id) {
            return ['status' => 'scope_requires_review'];
        }
        $session = OcpiRecord::find($receipt->source_session_record_id);
        $normalizer = app(RecordNormalizer::class);
        try {
            $verified = $session && $session->ocpi_provider_id === $provider->id && $original->ocpi_provider_id === $provider->id
                && $session->country_code === $original->country_code && $session->party_id === $original->party_id
                && hash_equals($receipt->source_cdr_hash, $original->content_hash) && hash_equals($receipt->source_session_hash, $session->content_hash)
                && hash_equals($receipt->source_cdr_hash, $normalizer->hash($original->payload)) && hash_equals($receipt->source_session_hash, $normalizer->hash($session->payload))
                && hash_equals($cdr->content_hash, $normalizer->hash($cdr->payload));
        } catch (\Throwable) {
            $verified = false;
        }
        if (! $verified) {
            return ['status' => 'source_requires_review'];
        }
        $summary = app(FinanceService::class)->summary($invoice);
        $reviews = DB::table('finance_refund_requests')->where('invoice_id', $invoice->id)
            ->when($credit, fn ($q) => $q->where('ocpi_credit_record_id', $cdr->id));
        $notes = DB::table('finance_unpaid_credits')->where('invoice_id', $invoice->id)
            ->when($credit, fn ($q) => $q->where('credit_record_id', $cdr->id));

        return ['status' => 'test_invoice_linked', 'invoice' => ['id' => $invoice->id, 'number' => $invoice->number, 'currency' => $invoice->currency, 'minor_units' => $invoice->minor_units, 'total_minor' => $invoice->total_minor, 'evidence' => $invoice->evidence],
            'state' => $summary['state'], 'paid_minor' => $summary['paid_minor'], 'refunded_minor' => $summary['refunded_minor'], 'unpaid_credited_minor' => $summary['unpaid_credited_minor'], 'due_minor' => $summary['amount_due_minor'], 'ledger_balanced' => $summary['ledger_balanced'],
            'refund_review_count' => $reviews->count(), 'refund_reviews' => $reviews->orderByDesc('id')->limit(5)->get(['id', 'status', 'amount_minor'])->all(), 'noncash_credit_count' => $notes->count()];
    }
}
