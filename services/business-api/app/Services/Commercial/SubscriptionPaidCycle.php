<?php

namespace App\Services\Commercial;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** A mutable active flag alone does not authorize paid-cycle entitlements. */
final class SubscriptionPaidCycle
{
    /** Never let a fractional or out-of-range persisted amount grant access. */
    private static function exactMinor(mixed $value): int
    {
        abort_unless(is_int($value) || (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value)),
            409, 'Subscription amount requires reconciliation.');
        $amount = filter_var($value, FILTER_VALIDATE_INT);
        abort_unless($amount !== false && $amount >= 0 && $amount <= 9_007_199_254_740_991,
            409, 'Subscription amount requires reconciliation.');

        return $amount;
    }

    /** A receipt must be backed by its original balanced payment journal. */
    private static function paymentPosted(iterable $rows, int $total, string $evidence): bool
    {
        $account = $evidence === 'STRIPE_TEST_CONTRACT'
            ? 'cash:stripe_test_subscription' : 'cash:mock_subscription';
        $expected = $total === 0 ? [] : [
            $account => [$total, 0],
            'subscription_receivable' => [0, $total],
        ];
        return self::entriesMatch($rows, $expected);
    }

    /** A paid receipt cannot authorize a malformed or unposted invoice. */
    private static function invoicePosted(iterable $rows, int $total, int $subtotal, int $tax): bool
    {
        if ($subtotal > $total || $tax !== $total - $subtotal) {
            return false;
        }
        $expected = array_filter([
            'subscription_receivable' => [$total, 0],
            'subscription_revenue' => [0, $subtotal],
            'subscription_tax_payable' => [0, $tax],
        ], static fn (array $entry): bool => $entry !== [0, 0]);

        return self::entriesMatch($rows, $expected);
    }

    private static function entriesMatch(iterable $rows, array $expected): bool
    {
        foreach ($rows as $row) {
            if (! isset($expected[$row->account])
                || $expected[$row->account] !== [self::exactMinor($row->debit_minor), self::exactMinor($row->credit_minor)]) {
                return false;
            }
            unset($expected[$row->account]);
        }

        return $expected === [];
    }

    public static function current(object $s): bool
    {
        if ($s->status !== 'active' || ! $s->period_start || ! $s->period_end) {
            return false;
        }
        $i = DB::table('commercial_subscription_invoices')->where('subscription_id', $s->id)
            ->where('cycle_number', $s->cycle_number)->first();
        if (! $i) {
            return false;
        }
        $r = DB::table('commercial_subscription_receipts')->where('invoice_id', $i->id)->first();
        if (! $r) {
            return false;
        }
        $total = self::exactMinor($i->total_minor);
        $subtotal = self::exactMinor($i->subtotal_minor);
        $tax = self::exactMinor($i->tax_minor);
        $invoiceEntries = DB::table('commercial_subscription_ledger')->where('invoice_id', $i->id)
            ->where('kind', 'invoice')->get(['account', 'debit_minor', 'credit_minor']);
        abort_unless(self::invoicePosted($invoiceEntries, $total, $subtotal, $tax),
            409, 'Subscription invoice journal requires reconciliation.');
        $received = self::exactMinor($r->amount_minor);
        abort_unless($received === $total && $r->currency === $i->currency
            && in_array($r->evidence, ['MOCK_PROVIDER', 'STRIPE_TEST_CONTRACT'], true),
            409, 'Subscription receipt requires reconciliation.');
        $paymentEntries = DB::table('commercial_subscription_ledger')->where('invoice_id', $i->id)
            ->where('kind', 'payment')->get(['account', 'debit_minor', 'credit_minor']);
        abort_unless(self::paymentPosted($paymentEntries, $total, $r->evidence),
            409, 'Subscription payment journal requires reconciliation.');
        $refunded = 0;
        foreach (DB::table('subscription_credits')->where('invoice_id', $i->id)->get() as $credit) {
            $amount = self::exactMinor($credit->amount_minor);
            abort_unless((int) $credit->receipt_id === (int) $r->id
                && (int) $credit->subscription_id === (int) $s->id
                && $credit->currency === $i->currency && (int) $credit->minor_units === (int) $i->minor_units
                && $credit->evidence === $r->evidence && $amount > 0
                && $amount <= $received - $refunded,
                409, 'Subscription credit requires reconciliation.');
            $refunded += $amount;
        }
        $clock = CarbonImmutable::now();

        return ($total === 0 || $refunded < $received)
            && (int) $i->plan_id === (int) $s->plan_id
            && CarbonImmutable::parse($i->period_start)->equalTo(CarbonImmutable::parse($s->period_start))
            && CarbonImmutable::parse($i->period_end)->equalTo(CarbonImmutable::parse($s->period_end))
            && CarbonImmutable::parse($i->period_start)->lte($clock)
            && CarbonImmutable::parse($i->period_end)->gt($clock);
    }
}
