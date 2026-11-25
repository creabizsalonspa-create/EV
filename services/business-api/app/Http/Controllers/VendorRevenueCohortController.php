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

        // Restrict each posting aggregate to the requested cohort IDs before
        // grouping; a small 93-day report must not scan unrelated vendors'
        // entire financial history.
        $cohortIds = DB::table('finance_invoices')
            ->select('id')
            ->where('vendor_id', $vendor)
            ->where('currency', $values['currency'])
            ->where('created_at', '>=', $values['from'].' 00:00:00')
            ->where('created_at', '<=', $values['to'].' 23:59:59.999999');
        $payments = DB::table('finance_payments')
            ->whereIn('invoice_id', clone $cohortIds)
            ->select('invoice_id')->selectRaw('SUM(amount_minor) AS paid_minor')
            ->groupBy('invoice_id');
        $refunds = DB::table('finance_refunds')
            ->whereIn('invoice_id', clone $cohortIds)
            ->select('invoice_id')
            ->selectRaw('SUM(amount_minor) AS refunded_minor, SUM(vendor_minor) AS vendor_refunded_minor')
            ->groupBy('invoice_id');
        $credits = DB::table('finance_unpaid_credits')
            ->whereIn('invoice_id', clone $cohortIds)
            ->select('invoice_id')
            ->selectRaw('SUM(amount_minor) AS unpaid_credited_minor, SUM(vendor_minor) AS vendor_credited_minor')
            ->groupBy('invoice_id');

        // All period buckets are computed from one SQL snapshot with no per-invoice
        // loop and with SQL expressions valid on both SQLite and PostgreSQL.
        $rows = DB::table('finance_invoices as i')
            ->leftJoinSub($payments, 'p', 'p.invoice_id', '=', 'i.id')
            ->leftJoinSub($refunds, 'r', 'r.invoice_id', '=', 'i.id')
            ->leftJoinSub($credits, 'c', 'c.invoice_id', '=', 'i.id')
            ->where('i.vendor_id', $vendor)->where('i.currency', $values['currency'])
            ->where('i.created_at', '>=', $values['from'].' 00:00:00')
            ->where('i.created_at', '<=', $values['to'].' 23:59:59.999999')
            ->selectRaw('DATE(i.created_at) AS cohort_date')
            ->selectRaw('COUNT(*) AS invoice_count, SUM(i.total_minor) AS invoiced_minor, SUM(i.vendor_minor) AS vendor_allocated_minor, SUM(i.commission_minor) AS commission_allocated_minor')
            ->selectRaw('SUM(COALESCE(p.paid_minor,0)) AS paid_minor, SUM(COALESCE(r.refunded_minor,0)) AS refunded_minor, SUM(COALESCE(r.vendor_refunded_minor,0)) AS vendor_refunded_minor')
            ->selectRaw('SUM(COALESCE(c.unpaid_credited_minor,0)) AS unpaid_credited_minor, SUM(COALESCE(c.vendor_credited_minor,0)) AS vendor_credited_minor')
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
