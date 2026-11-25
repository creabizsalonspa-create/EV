<?php

namespace App\Services\Finance;

use App\Models\Finance\Invoice;
use Illuminate\Support\Facades\DB;

/** Source-only integrity audit; never performs external payment or ledger mutations. */
final class InvoiceReconciliationAudit
{
    private const MAX_EXACT_MINOR = 9_007_199_254_740_991;

    /**
     * Never let a floating-point or string-coerced minor-unit amount turn a
     * damaged financial record into an apparently balanced reconciliation.
     * Audit sources may be signed so negative accounting errors remain visible.
     */
    private static function exactMinor(mixed $value): int
    {
        abort_unless(is_int($value) || is_string($value), 409,
            'Financial reconciliation source is not an exact integer.');
        $number = filter_var($value, FILTER_VALIDATE_INT);
        abort_unless($number !== false
            && $number <= self::MAX_EXACT_MINOR
            && $number >= -self::MAX_EXACT_MINOR,
            409, 'Financial reconciliation source exceeds exact integer bounds.');

        return $number;
    }

    public function inspect(Invoice $invoice): array
    {
        $issues = [];
        $flag = static function (string $code) use (&$issues): void {
            $issues[$code] = true;
        };

        // Eloquent's integer casts are for ordinary application reads, not
        // evidence validation: a persisted SQLite REAL could otherwise be
        // truncated before exactMinor sees it. Use the raw hydrated snapshot.
        $raw = $invoice->getRawOriginal();
        $source = [];
        foreach (['id', 'user_id', 'vendor_id', 'minor_units', 'subtotal_minor',
            'tax_minor', 'commission_minor', 'vendor_minor', 'total_minor'] as $field) {
            $source[$field] = self::exactMinor($raw[$field] ?? null);
        }
        abort_unless($source['id'] > 0 && $source['user_id'] > 0 && $source['vendor_id'] > 0
            && $source['minor_units'] >= 0 && $source['minor_units'] <= 3
            && preg_match('/\A[A-Z]{3}\z/', $invoice->currency) === 1,
            409, 'Invalid immutable invoice audit identity or precision.');

        $journals = DB::table('finance_journals')->where('invoice_id', $source['id'])->orderBy('id')->limit(501)->get();
        abort_if($journals->count() > 500, 409, 'Invoice exceeds the bounded reconciliation audit limit.');
        $entries = DB::table('finance_entries')->whereIn('journal_id', $journals->pluck('id'))->get()->groupBy('journal_id');
        $payments = DB::table('finance_payments')->where('invoice_id', $invoice->id)->get();
        $refunds = DB::table('finance_refunds')->where('invoice_id', $invoice->id)->get();
        $credits = DB::table('finance_unpaid_credits')->where('invoice_id', $invoice->id)->get();
        // Casts below must never erase fractional, coerced or unsafe amounts
        // before audit classification or provider-facing JSON/CSV serialization.
        foreach ($journals as $journal) {
            self::exactMinor($journal->amount_minor);
        }
        foreach ($entries as $group) {
            foreach ($group as $entry) {
                self::exactMinor($entry->debit_minor);
                self::exactMinor($entry->credit_minor);
            }
        }
        foreach ($payments as $payment) {
            self::exactMinor($payment->amount_minor);
        }
        foreach ($refunds as $refund) {
            foreach (['amount_minor', 'tax_minor', 'commission_minor',
                'vendor_minor'] as $field) {
                self::exactMinor($refund->{$field});
            }
        }
        foreach ($credits as $credit) {
            foreach (['amount_minor', 'tax_minor', 'commission_minor',
                'vendor_minor'] as $field) {
                self::exactMinor($credit->{$field});
            }
        }

        $byId = $journals->keyBy('id');
        $linked = [];
        $expectedAccounts = [];

        $subtotal = $source['subtotal_minor'];
        $tax = $source['tax_minor'];
        $commission = $source['commission_minor'];
        $vendor = $source['vendor_minor'];
        $total = $source['total_minor'];

        if ($subtotal < 0 || $tax < 0 || $commission < 0 || $vendor < 0 || $total < 0 ||
            $vendor + $commission !== $subtotal || $subtotal + $tax !== $total) {
            $flag('invoice_component_mismatch');
        }
        $issuance = $journals->where('kind', 'invoice');
        if ($issuance->count() !== 1 || (int) ($issuance->first()?->amount_minor ?? -1) !== $total) {
            $flag('invoice_journal_mismatch');
        }
        foreach ($issuance as $journal) {
            $expectedAccounts[(string) $journal->id] = [
                'receivable:user:'.$invoice->user_id => [$total, 0],
                'vendor_payable:'.$invoice->vendor_id => [0, $vendor],
                'platform_commission' => [0, $commission],
                'tax_payable:'.$invoice->country => [0, $tax],
            ];
        }

        foreach ($payments as $payment) {
            if ((int) $payment->amount_minor <= 0) {
                $flag('collection_out_of_bounds');
            }
            $journal = $byId->get($payment->journal_id);
            if (! $journal || $journal->kind !== 'payment' ||
                (int) $journal->amount_minor !== (int) $payment->amount_minor) {
                $flag('payment_journal_mismatch');
            }
            $linked[(string) $payment->journal_id] = true;
            if ($journal && $journal->kind === 'payment') {
                $expectedAccounts[(string) $journal->id] = [
                    'cash:simulator' => [(int) $payment->amount_minor, 0],
                    'receivable:user:'.$invoice->user_id => [0, (int) $payment->amount_minor],
                ];
            }
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
            if ((int) $refund->amount_minor <= 0 || (int) $refund->tax_minor < 0
                || (int) $refund->commission_minor < 0 || (int) $refund->vendor_minor < 0
                || (int) $refund->tax_minor + (int) $refund->commission_minor +
                (int) $refund->vendor_minor !== (int) $refund->amount_minor) {
                $flag('refund_component_mismatch');
            }
            $linked[(string) $refund->journal_id] = true;
            if ($journal && $journal->kind === 'refund') {
                $expectedAccounts[(string) $journal->id] = [
                    'tax_payable:'.$invoice->country => [(int) $refund->tax_minor, 0],
                    'platform_commission' => [(int) $refund->commission_minor, 0],
                    'vendor_payable:'.$invoice->vendor_id => [(int) $refund->vendor_minor, 0],
                    'cash:simulator' => [0, (int) $refund->amount_minor],
                ];
            }
        }
        if ($refundedTax > $tax || $refundedCommission > $commission || $refundedVendor > $vendor) {
            $flag('refund_allocation_exceeded');
        }

        // Canonical unpaid credits are a single full reversal of an
        // untouched invoice, not a partial payment or a second refund.
        if ($credits->count() > 1 || ($credits->isNotEmpty() &&
            ($payments->isNotEmpty() || $refunds->isNotEmpty()))) {
            $flag('collection_out_of_bounds');
        }
        foreach ($credits as $credit) {
            $journal = $byId->get($credit->journal_id);
            if (! $journal || $journal->kind !== 'unpaid_credit' ||
                (int) $journal->amount_minor !== (int) $credit->amount_minor ||
                $credit->currency !== $invoice->currency ||
                self::exactMinor($credit->minor_units) !== $source['minor_units']) {
                $flag('unpaid_credit_journal_mismatch');
            }
            if ((int) $credit->amount_minor !== $total || $total <= 0
                || (int) $credit->tax_minor !== $tax
                || (int) $credit->commission_minor !== $commission
                || (int) $credit->vendor_minor !== $vendor
                || (int) $credit->tax_minor + (int) $credit->commission_minor +
                (int) $credit->vendor_minor !== (int) $credit->amount_minor) {
                $flag('unpaid_credit_component_mismatch');
            }
            $linked[(string) $credit->journal_id] = true;
            if ($journal && $journal->kind === 'unpaid_credit') {
                $expectedAccounts[(string) $journal->id] = [
                    'vendor_payable:'.$invoice->vendor_id => [(int) $credit->vendor_minor, 0],
                    'platform_commission' => [(int) $credit->commission_minor, 0],
                    'tax_payable:'.$invoice->country => [(int) $credit->tax_minor, 0],
                    'receivable:user:'.$invoice->user_id => [0, (int) $credit->amount_minor],
                ];
            }
        }

        foreach ($journals as $journal) {
            $lines = $entries->get($journal->id, collect());
            $debits = $creditsSum = 0;
            $postedAccounts = [];
            foreach ($lines as $line) {
                $debit = (int) $line->debit_minor;
                $credit = (int) $line->credit_minor;
                if ($debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0)) {
                    $flag('invalid_journal_entry');
                }
                $debits += $debit;
                $creditsSum += $credit;
                $postedAccounts[$line->account] ??= [0, 0];
                $postedAccounts[$line->account][0] += $debit;
                $postedAccounts[$line->account][1] += $credit;
            }
            if (isset($expectedAccounts[(string) $journal->id])) {
                // The two sides balancing is necessary but not sufficient: a wrong
                // payable/receivable account can otherwise hide a misposting.
                $expected = array_filter(
                    $expectedAccounts[(string) $journal->id],
                    static fn (array $pair): bool => $pair[0] !== 0 || $pair[1] !== 0
                );
                $actual = array_filter(
                    $postedAccounts,
                    static fn (array $pair): bool => $pair[0] !== 0 || $pair[1] !== 0
                );
                ksort($expected);
                ksort($actual);
                if ($actual !== $expected) {
                    $flag('journal_accounts_mismatch');
                }
            }
            // Aggregate a journal only while every posted total remains
            // representable; otherwise refuse a potentially false green audit.
            self::exactMinor($debits);
            self::exactMinor($creditsSum);
            foreach ($postedAccounts as [$accountDebit, $accountCredit]) {
                self::exactMinor($accountDebit);
                self::exactMinor($accountCredit);
            }
            if (($lines->isEmpty() && (int) $journal->amount_minor !== 0) ||
                $debits !== $creditsSum || $debits !== (int) $journal->amount_minor) {
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

        self::exactMinor($refundedTax);
        self::exactMinor($refundedCommission);
        self::exactMinor($refundedVendor);
        $paid = self::exactMinor($payments->sum('amount_minor'));
        $refunded = self::exactMinor($refunds->sum('amount_minor'));
        $credited = self::exactMinor($credits->sum('amount_minor'));
        if ($paid < 0 || $refunded < 0 || $credited < 0 || $paid + $credited > $total ||
            $refunded > $paid || $credited > $total) {
            $flag('collection_out_of_bounds');
        }

        return [
            'invoice_id' => $source['id'],
            'invoice_number' => $invoice->number,
            'currency' => $invoice->currency,
            'minor_units' => $source['minor_units'],
            'invoiced_minor' => $total,
            'paid_minor' => $paid,
            'refunded_minor' => $refunded,
            'unpaid_credited_minor' => $credited,
            'net_collected_minor' => self::exactMinor($paid - $refunded),
            'amount_due_minor' => self::exactMinor(max(0, $total - $paid - $credited)),
            'journal_count' => $journals->count(),
            'status' => $issues === [] ? 'reconciled' : 'attention_required',
            'issues' => array_keys($issues),
            'evidence' => 'SIMULATED_CANONICAL_LEDGER',
            'external_psp_or_bank_verified' => false,
        ];
    }
}
