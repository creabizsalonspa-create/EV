<?php

namespace App\Services\Finance;

use App\Models\Finance\Invoice;
use Illuminate\Support\Facades\DB;

/** Source-only integrity audit; never performs external payment or ledger mutations. */
final class InvoiceReconciliationAudit
{
    public function inspect(Invoice $invoice): array
    {
        $issues = [];
        $flag = static function (string $code) use (&$issues): void {
            $issues[$code] = true;
        };

        $journals = DB::table('finance_journals')->where('invoice_id', $invoice->id)->orderBy('id')->limit(501)->get();
        abort_if($journals->count() > 500, 409, 'Invoice exceeds the bounded reconciliation audit limit.');
        $entries = DB::table('finance_entries')->whereIn('journal_id', $journals->pluck('id'))->get()->groupBy('journal_id');
        $payments = DB::table('finance_payments')->where('invoice_id', $invoice->id)->get();
        $refunds = DB::table('finance_refunds')->where('invoice_id', $invoice->id)->get();
        $credits = DB::table('finance_unpaid_credits')->where('invoice_id', $invoice->id)->get();
        $byId = $journals->keyBy('id');
        $linked = [];

        $subtotal = (int) $invoice->subtotal_minor;
        $tax = (int) $invoice->tax_minor;
        $commission = (int) $invoice->commission_minor;
        $vendor = (int) $invoice->vendor_minor;
        $total = (int) $invoice->total_minor;

        if ($subtotal < 0 || $tax < 0 || $commission < 0 || $vendor < 0 || $total < 0 ||
            $vendor + $commission !== $subtotal || $subtotal + $tax !== $total) {
            $flag('invoice_component_mismatch');
        }
        $issuance = $journals->where('kind', 'invoice');
        if ($issuance->count() !== 1 || (int) ($issuance->first()?->amount_minor ?? -1) !== $total) {
            $flag('invoice_journal_mismatch');
        }

        foreach ($payments as $payment) {
            $journal = $byId->get($payment->journal_id);
            if (! $journal || $journal->kind !== 'payment' ||
                (int) $journal->amount_minor !== (int) $payment->amount_minor) {
                $flag('payment_journal_mismatch');
            }
            $linked[(string) $payment->journal_id] = true;
        }

        $refundedTax = $refundedCommission = $refundedVendor = 0;
        foreach ($refunds as $refund) {
            $journal = $byId->get($refund->journal_id);
            if (! $journal || $journal->kind !== 'refund' ||
                (int) $journal->amount_minor !== (int) $refund->amount_minor) {
                $flag('refund_journal_mismatch');
            }
            $refundedTax += (int) $refund->tax_minor;
            $refundedCommission += (int) $refund->commission_minor;
            $refundedVendor += (int) $refund->vendor_minor;
            if ((int) $refund->tax_minor + (int) $refund->commission_minor +
                (int) $refund->vendor_minor !== (int) $refund->amount_minor) {
                $flag('refund_component_mismatch');
            }
            $linked[(string) $refund->journal_id] = true;
        }
        if ($refundedTax > $tax || $refundedCommission > $commission || $refundedVendor > $vendor) {
            $flag('refund_allocation_exceeded');
        }

        foreach ($credits as $credit) {
            $journal = $byId->get($credit->journal_id);
            if (! $journal || $journal->kind !== 'unpaid_credit' ||
                (int) $journal->amount_minor !== (int) $credit->amount_minor ||
                $credit->currency !== $invoice->currency ||
                (int) $credit->minor_units !== (int) $invoice->minor_units) {
                $flag('unpaid_credit_journal_mismatch');
            }
            if ((int) $credit->tax_minor + (int) $credit->commission_minor +
                (int) $credit->vendor_minor !== (int) $credit->amount_minor) {
                $flag('unpaid_credit_component_mismatch');
            }
            $linked[(string) $credit->journal_id] = true;
        }

        foreach ($journals as $journal) {
            $lines = $entries->get($journal->id, collect());
            $debits = $creditsSum = 0;
            foreach ($lines as $line) {
                $debit = (int) $line->debit_minor;
                $credit = (int) $line->credit_minor;
                if ($debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0)) {
                    $flag('invalid_journal_entry');
                }
                $debits += $debit;
                $creditsSum += $credit;
            }
            if ($lines->isEmpty() || $debits !== $creditsSum ||
                $debits !== (int) $journal->amount_minor) {
                $flag('journal_unbalanced');
            }
            if ($journal->currency !== $invoice->currency) {
                $flag('journal_currency_mismatch');
            }
            if (! in_array($journal->kind, ['invoice', 'payment', 'refund', 'unpaid_credit'], true)) {
                $flag('unexpected_journal_kind');
            } elseif ($journal->kind !== 'invoice' && ! isset($linked[(string) $journal->id])) {
                $flag('unlinked_financial_journal');
            }
        }

        $paid = (int) $payments->sum('amount_minor');
        $refunded = (int) $refunds->sum('amount_minor');
        $credited = (int) $credits->sum('amount_minor');
        if ($paid < 0 || $refunded < 0 || $credited < 0 || $paid + $credited > $total ||
            $refunded > $paid || $credited > $total) {
            $flag('collection_out_of_bounds');
        }

        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => $invoice->number,
            'currency' => $invoice->currency,
            'minor_units' => (int) $invoice->minor_units,
            'invoiced_minor' => $total,
            'paid_minor' => $paid,
            'refunded_minor' => $refunded,
            'unpaid_credited_minor' => $credited,
            'net_collected_minor' => $paid - $refunded,
            'amount_due_minor' => max(0, $total - $paid - $credited),
            'journal_count' => $journals->count(),
            'status' => $issues === [] ? 'reconciled' : 'attention_required',
            'issues' => array_keys($issues),
            'evidence' => 'SIMULATED_CANONICAL_LEDGER',
            'external_psp_or_bank_verified' => false,
        ];
    }
}
