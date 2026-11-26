<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Security\CurrentFinanceReader;
use App\Services\Commercial\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Read-only, country-currency precise earnings and settlement source report.
 * No provider action or independent payable calculation is authorized here.
 */
final class VendorEarningsController extends Controller
{
    private const MAX_EXACT_MINOR = 9_007_199_254_740_991;

    /**
     * Financial records may be represented by PostgreSQL numeric strings.
     * Refuse inexact or negative values before serializing JSON/CSV.
     */
    private static function minor(mixed $value, bool $signed = false): int
    {
        // Reject values already coerced through floating point or boolean
        // conversion, even when PHP would validate them as integral.
        abort_unless(is_int($value) || is_string($value), 409,
            'Financial source exceeds exact integer bounds.');
        $number = filter_var($value, FILTER_VALIDATE_INT);
        abort_if($number === false || $number > self::MAX_EXACT_MINOR
            || $number < ($signed ? -self::MAX_EXACT_MINOR : 0),
            409, 'Financial source exceeds exact integer bounds.');

        return $number;
    }

    /** Only CSV gets the spreadsheet-literal prefix; source JSON remains exact. */
    private static function csvLiteral(string $number): string
    {
        return preg_match('/^[\\p{Z}\\p{Cc}\\p{Cf}]*[=+@-]/u', $number)
            ? "'".$number : $number;
    }

    private function page(Request $request, int $vendor, SettlementService $service): array
    {
        // Caller holds the account lock until the scoped earnings snapshot
        // is materialized; cached former Vendor/Admin roles cannot read it.
        $actor = app(CurrentFinanceReader::class)->requireCurrent($request);
        abort_unless($actor->role === 'admin' || ($actor->role === 'vendor' && (int) $actor->id === $vendor), 404);
        abort_unless(User::whereKey($vendor)->where('role', 'vendor')->exists(), 404);

        $values = $request->validate([
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'per_page' => ['sometimes', 'integer', 'between:1,25'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
        ]);
        $currency = $values['currency'];
        $schedule = DB::table('commercial_settlement_schedules')
            ->where('vendor_id', $vendor)->where('currency', $currency)->first();

        // All eligibility, refund-debt and held-funds calculations come from
        // the canonical settlement service; do not copy those rules here.
        $balance = $service->balances($vendor, $currency, (int) ($schedule->hold_days ?? 0));
        $available = [];
        foreach ($balance['lines'] as $line) {
            if ($line['invoice_id'] !== null) {
                $id = (int) $line['invoice_id'];
                $available[$id] = self::minor(
                    ($available[$id] ?? 0) + self::minor($line['amount_minor'], true),
                    true
                );
            }
        }

        $query = DB::table('finance_invoices')->where('vendor_id', $vendor)->where('currency', $currency);
        if (isset($values['from'])) {
            $query->where('created_at', '>=', $values['from'].' 00:00:00');
        }
        if (isset($values['to'])) {
            $query->where('created_at', '<=', $values['to'].' 23:59:59.999999');
        }
        $page = $query->orderByDesc('id')->paginate(
            (int) ($values['per_page'] ?? 20),
            ['*'], 'page', (int) ($values['page'] ?? 1)
        );

        $rows = $page->getCollection()->map(function (object $invoice) use ($available) {
            $paid = self::minor(DB::table('finance_payments')->where('invoice_id', $invoice->id)->sum('amount_minor'));
            $refunds = DB::table('finance_refunds')->where('invoice_id', $invoice->id);
            $refunded = self::minor((clone $refunds)->sum('amount_minor'));
            $vendorRefund = self::minor((clone $refunds)->sum('vendor_minor'));
            $noncashCredit = self::minor(DB::table('finance_unpaid_credits')
                ->where('invoice_id', $invoice->id)->sum('amount_minor'));
            $settlements = DB::table('commercial_settlement_lines as l')
                ->join('commercial_settlements as s', 's.id', '=', 'l.settlement_id')
                ->where('l.invoice_id', $invoice->id)
                ->whereIn('s.state', ['pending', 'approved', 'simulated_paid', 'provider_paid'])
                ->get(['s.state', 'l.amount_minor']);
            $pending = self::minor($settlements->filter(
                fn ($line) => in_array($line->state, ['pending', 'approved'], true)
            )->sum('amount_minor'));
            $completed = self::minor($settlements->filter(
                fn ($line) => in_array($line->state, ['simulated_paid', 'provider_paid'], true)
            )->sum('amount_minor'));
            $invoiceTotal = self::minor($invoice->total_minor);

            return [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => $invoice->number,
                'currency' => $invoice->currency,
                'minor_units' => (int) $invoice->minor_units,
                'subtotal_minor' => self::minor($invoice->subtotal_minor),
                'tax_minor' => self::minor($invoice->tax_minor),
                'commission_minor' => self::minor($invoice->commission_minor),
                'vendor_allocated_minor' => self::minor($invoice->vendor_minor),
                'paid_minor' => $paid,
                'refunded_minor' => $refunded,
                'vendor_refunded_minor' => $vendorRefund,
                'unpaid_credited_minor' => $noncashCredit,
                'net_collected_minor' => self::minor($paid - $refunded, true),
                'amount_due_minor' => self::minor(max(0, $invoiceTotal - $paid - $noncashCredit)),
                'pending_reserved_minor' => $pending,
                'completed_settlement_minor' => $completed,
                'available_minor' => self::minor($available[(int) $invoice->id] ?? 0, true),
                // Preserve the invoice's immutable source claim. A partner-
                // sourced or sandbox invoice must never be relabelled as a
                // simulator record in a financial CSV or earnings history.
                'evidence' => (string) $invoice->evidence,
            ];
        })->values()->all();

        return [
            'vendor_id' => $vendor, 'currency' => $currency, 'minor_units' => $balance['minor_units'],
            'data' => $rows, 'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            'overall_available_minor' => self::minor($balance['available_minor'], true),
            'overall_balance_scope' => 'ALL_VENDOR_INVOICES_AND_APPROVED_ADJUSTMENTS',
            'hold_days' => (int) ($schedule->hold_days ?? 0),
            'evidence' => 'SIMULATED_CANONICAL_SETTLEMENT',
            'real_payout_enabled' => false, 'external_psp_or_bank_verified' => false,
        ];
    }

    public function index(Request $request, int $vendor, SettlementService $service)
    {
        return DB::transaction(fn () => response()->json(
            $this->page($request, $vendor, $service)
        )->header('Cache-Control', 'private, no-store'), 3);
    }

    public function export(Request $request, int $vendor, SettlementService $service)
    {
        try {
            $report = DB::transaction(
                fn () => $this->page($request, $vendor, $service), 3
            );
        } catch (ValidationException $error) {
            // A normal browser CSV request must receive HTTP 422, not
            // a Laravel form redirect that could mask invalid page bounds.
            return response()->json([
                'message' => 'Invalid private earnings export filters.',
                'errors' => $error->errors(),
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        return response()->streamDownload(static function () use ($report): void {
            $output = fopen('php://output', 'wb');
            $fields = [
                'invoice_id', 'invoice_number', 'currency', 'minor_units', 'subtotal_minor',
                'tax_minor', 'commission_minor', 'vendor_allocated_minor', 'paid_minor',
                'refunded_minor', 'vendor_refunded_minor', 'unpaid_credited_minor',
                'net_collected_minor', 'amount_due_minor', 'pending_reserved_minor',
                'completed_settlement_minor', 'available_minor', 'evidence',
            ];
            fputcsv($output, $fields, ',', '"', '');
            foreach ($report['data'] as $row) {
                // Invoice numbers can come from an issuer/provider. Treat
                // spreadsheet-leading formulas as literal cells on export.
                $values = array_map(static function (string $field) use ($row) {
                    $value = $row[$field];

                    return in_array($field, ['invoice_number', 'evidence'], true)
                        ? self::csvLiteral((string) $value)
                        : $value;
                }, $fields);
                fputcsv($output, $values, ',', '"', '');
            }
            fclose($output);
        }, 'simulated-vendor-earnings-page.csv', [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
