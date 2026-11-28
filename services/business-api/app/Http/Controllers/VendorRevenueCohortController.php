<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Commercial\SettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VendorRevenueCohortController extends Controller
{
    // JSON integers must also remain exact in JavaScript/Flutter web consumers.
    private const MAX_SAFE_MINOR = 9_007_199_254_740_991;

    /**
     * Invoice-issuance cohorts, not a cash-flow statement, PSP reconciliation,
     * earned payout balance or event-time payment/reversal series.
     */
    private function report(Request $request, int $vendor, SettlementService $settlements): array
    {
        // A browser session may carry a cached role after a different request
        // suspends the account, rotates credentials or changes its role. Hold
        // the current account row until the report snapshot is materialized;
        // an old Admin must not retain cross-vendor finance read authority.
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $current = User::query()->whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($current && $current->account_status === 'active'
            && $current->role === $actor->role
            && (int) $current->auth_epoch === (int) $actor->auth_epoch
            && hash_equals((string) $current->password, (string) $actor->password),
            409, 'Account authorization changed; sign in again.');
        abort_unless($current->role === 'admin' || ($current->role === 'vendor' && (int) $current->id === $vendor), 404);
        abort_unless(User::whereKey($vendor)->where('role', 'vendor')->exists(), 404);

        $values = $request->validate([
            'currency' => ['required', 'regex:/\A[A-Z]{3}\z/'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($values['from'].' 00:00:00', 'UTC');
        $to = CarbonImmutable::parse($values['to'].' 00:00:00', 'UTC');
        abort_if(abs($from->diffInDays($to)) > 92, 422, 'Revenue cohorts are limited to 93 UTC calendar days.');

        // The existing canonical settlement precision policy rejects conflicting
        // regional and historical invoice denominations. Never combine currencies.
        $minorUnits = $settlements->currencyPrecision($vendor, $values['currency']);

        $asOf = CarbonImmutable::now('UTC');
        $until = $to->addDay()->toDateTimeString();
        $cutoff = $asOf->format('Y-m-d H:i:s.u');

        // Restrict each posting aggregate to the requested cohort IDs before
        // grouping; a small 93-day report must not scan unrelated vendors'
        // entire financial history.
        $cohortIds = DB::table('finance_invoices')
            ->select('id')
            ->where('vendor_id', $vendor)
            ->where('currency', $values['currency'])
            ->where('created_at', '>=', $values['from'].' 00:00:00')
            ->where('created_at', '<', $until)
            ->where('created_at', '<=', $cutoff);
        $max = self::MAX_SAFE_MINOR;
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $entryValid = "e.debit_minor BETWEEN 0 AND {$max} AND e.credit_minor BETWEEN 0 AND {$max} AND (e.debit_minor = 0 OR e.credit_minor = 0)";
        if ($sqlite) {
            $entryValid .= " AND typeof(e.debit_minor) = 'integer' AND typeof(e.credit_minor) = 'integer'";
        }
        // SQLite's TOTAL avoids integer overflow on corrupt imports. All valid
        // nonnegative sums up through MAX remain exactly representable.
        $sum = $sqlite ? 'TOTAL' : 'SUM';
        $entries = DB::table('finance_entries as e')
            ->join('finance_journals as j', 'j.id', '=', 'e.journal_id')
            ->whereIn('j.invoice_id', clone $cohortIds)
            ->where('j.created_at', '<=', $cutoff)
            ->where('e.created_at', '<=', $cutoff)
            ->groupBy('e.journal_id')
            ->select('e.journal_id')
            ->selectRaw("MIN(CASE WHEN {$entryValid} THEN 1 ELSE 0 END) AS source_valid")
            ->selectRaw("{$sum}(CASE WHEN {$entryValid} THEN e.debit_minor ELSE 0 END) AS debits")
            ->selectRaw("{$sum}(CASE WHEN {$entryValid} THEN e.credit_minor ELSE 0 END) AS credits");

        $journalValid = "j.currency = i.currency AND j.amount_minor BETWEEN 0 AND {$max} AND COALESCE(e.source_valid,1) = 1 AND COALESCE(e.debits,0) = j.amount_minor AND COALESCE(e.credits,0) = j.amount_minor";
        $journalValid .= " AND ((j.kind = 'invoice' AND j.amount_minor = i.total_minor) OR j.kind IN ('payment', 'refund', 'unpaid_credit'))";
        if ($sqlite) {
            $journalValid .= " AND typeof(j.amount_minor) = 'integer'";
        }
        $journals = DB::table('finance_journals as j')
            ->join('finance_invoices as i', 'i.id', '=', 'j.invoice_id')
            ->leftJoinSub($entries, 'e', 'e.journal_id', '=', 'j.id')
            ->whereIn('j.invoice_id', clone $cohortIds)
            ->where('j.created_at', '<=', $cutoff)
            ->groupBy('j.invoice_id')
            ->select('j.invoice_id')
            ->selectRaw("COUNT(CASE WHEN j.kind = 'invoice' THEN 1 END) AS issuance_count")
            ->selectRaw("COUNT(CASE WHEN j.kind = 'payment' THEN 1 END) AS payment_count")
            ->selectRaw("COUNT(CASE WHEN j.kind = 'refund' THEN 1 END) AS refund_count")
            ->selectRaw("COUNT(CASE WHEN j.kind = 'unpaid_credit' THEN 1 END) AS credit_count")
            ->selectRaw("MIN(CASE WHEN {$journalValid} THEN 1 ELSE 0 END) AS source_valid");

        $posting = function (string $table, string $kind) use ($cohortIds, $cutoff, $max, $sqlite) {
            $query = DB::table($table.' as p')
                ->whereIn('p.invoice_id', clone $cohortIds)
                ->where('p.created_at', '<=', $cutoff)
                ->join('finance_invoices as i', 'i.id', '=', 'p.invoice_id')
                ->leftJoin('finance_journals as j', 'j.id', '=', 'p.journal_id');
            $condition = "p.amount_minor BETWEEN 1 AND {$max} AND p.amount_minor <= i.total_minor AND j.invoice_id = i.id AND j.kind = '{$kind}' AND j.created_at <= p.created_at AND j.currency = i.currency AND j.amount_minor = p.amount_minor";
            if ($sqlite) {
                $condition .= " AND typeof(p.amount_minor) = 'integer' AND typeof(j.amount_minor) = 'integer'";
            }
            if ($kind !== 'payment') {
                $condition .= ' AND p.tax_minor BETWEEN 0 AND i.tax_minor AND p.commission_minor BETWEEN 0 AND i.commission_minor AND p.vendor_minor BETWEEN 0 AND i.vendor_minor AND p.tax_minor + p.commission_minor + p.vendor_minor = p.amount_minor';
                if ($sqlite) {
                    $condition .= " AND typeof(p.tax_minor) = 'integer' AND typeof(p.commission_minor) = 'integer' AND typeof(p.vendor_minor) = 'integer'";
                }
            }
            if ($kind === 'unpaid_credit') {
                $condition .= ' AND p.currency = i.currency AND p.minor_units = i.minor_units AND p.amount_minor = i.total_minor';
            }
            $valid = "CASE WHEN {$condition} THEN 1 ELSE 0 END";
            $query->select('p.invoice_id')
                ->selectRaw("COUNT(*) AS receipt_count, SUM(CASE WHEN {$valid} = 1 THEN p.amount_minor ELSE 0 END) AS amount_minor, MIN({$valid}) AS source_valid")
                ->groupBy('p.invoice_id');
            if ($kind !== 'payment') {
                $query->selectRaw("SUM(CASE WHEN {$valid} = 1 THEN p.vendor_minor ELSE 0 END) AS vendor_minor");
            }

            return $query;
        };
        $payments = $posting('finance_payments', 'payment');
        $refunds = $posting('finance_refunds', 'refund');
        $credits = $posting('finance_unpaid_credits', 'unpaid_credit');
        $invoiceValid = "i.minor_units = {$minorUnits} AND i.total_minor BETWEEN 0 AND {$max} AND i.vendor_minor BETWEEN 0 AND i.total_minor AND i.commission_minor BETWEEN 0 AND i.total_minor AND i.tax_minor BETWEEN 0 AND i.total_minor AND i.vendor_minor + i.commission_minor + i.tax_minor = i.total_minor";
        if ($sqlite) {
            $invoiceValid .= " AND typeof(i.minor_units) = 'integer' AND typeof(i.total_minor) = 'integer' AND typeof(i.vendor_minor) = 'integer' AND typeof(i.commission_minor) = 'integer' AND typeof(i.tax_minor) = 'integer'";
        }
        $invoiceValid .= ' AND a.issuance_count = 1 AND a.source_valid = 1';
        // Every non-issuance journal needs its single canonical receipt as of
        // this cutoff. A balanced orphan posting must not silently disappear.
        $invoiceValid .= ' AND a.payment_count = COALESCE(p.receipt_count,0) AND a.refund_count = COALESCE(r.receipt_count,0) AND a.credit_count = COALESCE(c.receipt_count,0)';
        $invoiceValid .= ' AND COALESCE(p.source_valid,1) = 1 AND COALESCE(r.source_valid,1) = 1 AND COALESCE(c.source_valid,1) = 1 AND COALESCE(p.amount_minor,0) + COALESCE(c.amount_minor,0) <= i.total_minor AND COALESCE(r.amount_minor,0) <= COALESCE(p.amount_minor,0) AND COALESCE(r.vendor_minor,0) + COALESCE(c.vendor_minor,0) <= i.vendor_minor';

        // All period buckets are computed from one SQL snapshot with no per-invoice
        // loop and with SQL expressions valid on both SQLite and PostgreSQL.
        $rows = DB::table('finance_invoices as i')
            ->leftJoinSub($journals, 'a', 'a.invoice_id', '=', 'i.id')
            ->leftJoinSub($payments, 'p', 'p.invoice_id', '=', 'i.id')
            ->leftJoinSub($refunds, 'r', 'r.invoice_id', '=', 'i.id')
            ->leftJoinSub($credits, 'c', 'c.invoice_id', '=', 'i.id')
            ->where('i.vendor_id', $vendor)->where('i.currency', $values['currency'])
            ->where('i.created_at', '>=', $values['from'].' 00:00:00')
            ->where('i.created_at', '<', $until)
            ->where('i.created_at', '<=', $cutoff)
            ->selectRaw('DATE(i.created_at) AS cohort_date')
            ->selectRaw("MIN(CASE WHEN {$invoiceValid} THEN 1 ELSE 0 END) AS source_valid")
            ->selectRaw("COUNT(*) AS invoice_count, SUM(CASE WHEN {$invoiceValid} THEN i.total_minor ELSE 0 END) AS invoiced_minor, SUM(CASE WHEN {$invoiceValid} THEN i.vendor_minor ELSE 0 END) AS vendor_allocated_minor, SUM(CASE WHEN {$invoiceValid} THEN i.commission_minor ELSE 0 END) AS commission_allocated_minor")
            ->selectRaw("SUM(CASE WHEN {$invoiceValid} THEN COALESCE(p.amount_minor,0) ELSE 0 END) AS paid_minor, SUM(CASE WHEN {$invoiceValid} THEN COALESCE(r.amount_minor,0) ELSE 0 END) AS refunded_minor, SUM(CASE WHEN {$invoiceValid} THEN COALESCE(r.vendor_minor,0) ELSE 0 END) AS vendor_refunded_minor")
            ->selectRaw("SUM(CASE WHEN {$invoiceValid} THEN COALESCE(c.amount_minor,0) ELSE 0 END) AS unpaid_credited_minor, SUM(CASE WHEN {$invoiceValid} THEN COALESCE(c.vendor_minor,0) ELSE 0 END) AS vendor_credited_minor")
            ->groupByRaw('DATE(i.created_at)')
            ->orderBy('cohort_date')
            ->get()->keyBy('cohort_date');

        $amounts = [
            'invoice_count', 'invoiced_minor', 'vendor_allocated_minor',
            'commission_allocated_minor', 'paid_minor', 'refunded_minor',
            'vendor_refunded_minor', 'unpaid_credited_minor', 'vendor_credited_minor',
        ];
        $totals = array_fill_keys($amounts, 0);
        $data = [];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $day = $date->toDateString();
            $source = $rows->get($day);
            abort_if($source && (int) $source->source_valid !== 1, 409, 'Invoice cohort source does not reconcile.');
            $entry = ['date_utc' => $day];
            foreach ($amounts as $name) {
                $value = $source ? filter_var($source->$name, FILTER_VALIDATE_INT) : 0;
                abort_if($value === false || $value < 0, 409, 'Financial aggregate exceeds exact integer bounds.');
                abort_if($value > self::MAX_SAFE_MINOR - $totals[$name], 409, 'Financial aggregate exceeds exact browser integer bounds.');
                $entry[$name] = $value;
                $totals[$name] += $value;
            }
            $entry['net_collected_minor'] = $entry['paid_minor'] - $entry['refunded_minor'];
            $entry['net_vendor_allocated_minor'] = $entry['vendor_allocated_minor'] -
                $entry['vendor_refunded_minor'] - $entry['vendor_credited_minor'];
            $data[] = $entry;
        }
        $totals['net_collected_minor'] = $totals['paid_minor'] - $totals['refunded_minor'];
        $totals['net_vendor_allocated_minor'] = $totals['vendor_allocated_minor'] -
            $totals['vendor_refunded_minor'] - $totals['vendor_credited_minor'];

        return [
            'as_of' => $asOf->toIso8601String(),
            'vendor_id' => $vendor,
            'currency' => $values['currency'],
            'minor_units' => $minorUnits,
            'from' => $values['from'],
            'to' => $values['to'],
            'basis' => 'INVOICE_ISSUANCE_DATE_UTC_COHORT',
            'payment_and_refund_totals' => 'LIFETIME_OF_SELECTED_INVOICE_COHORT_AS_OF_QUERY',
            'data' => $data,
            'totals' => $totals,
            'not_cashflow_or_payout_eligibility' => true,
            'external_psp_or_bank_verified' => false,
            'real_money_movement' => false,
            'evidence' => 'SIMULATED_CANONICAL_FINANCE',
        ];
    }

    public function index(Request $request, int $vendor, SettlementService $settlements)
    {
        return DB::transaction(fn () => response()->json($this->report($request, $vendor, $settlements))
            ->header('Cache-Control', 'private, no-store'), 3);
    }

    public function export(Request $request, int $vendor, SettlementService $settlements)
    {
        try {
            $report = DB::transaction(fn () => $this->report($request, $vendor, $settlements), 3);
        } catch (ValidationException $error) {
            // Browser CSV GETs typically lack Accept: application/json.
            // Return the same explicit 422 as a JSON request, never a
            // redirect that hides a malformed/oversized financial period.
            return response()->json([
                'message' => 'Invalid private invoice-cohort period.',
                'errors' => $error->errors(),
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        return response()->streamDownload(static function () use ($report): void {
            $output = fopen('php://output', 'wb');
            $fields = [
                'date_utc', 'invoice_count', 'invoiced_minor', 'vendor_allocated_minor',
                'commission_allocated_minor', 'paid_minor', 'refunded_minor',
                'vendor_refunded_minor', 'unpaid_credited_minor', 'vendor_credited_minor',
                'net_collected_minor', 'net_vendor_allocated_minor',
            ];
            fputcsv($output, $fields, ',', '"', '');
            foreach ($report['data'] as $row) {
                fputcsv($output,
                    array_map(static fn (string $field) => $row[$field], $fields),
                    ',', '"', '');
            }
            fclose($output);
        }, 'simulated-vendor-invoice-cohorts.csv', [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
