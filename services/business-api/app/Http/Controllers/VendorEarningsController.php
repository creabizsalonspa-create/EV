<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Commercial\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Read-only, country-currency precise earnings and settlement source report.
 * No provider action or independent payable calculation is authorized here.
 */
final class VendorEarningsController extends Controller
{
    private function page(Request $request, int $vendor, SettlementService $service): array
    {
        $actor = $request->user();
        abort_unless($actor->role === 'admin' || ($actor->role === 'vendor' && (int) $actor->id === $vendor), 404);
        abort_unless(User::whereKey($vendor)->where('role', 'vendor')->exists(), 404);

        $values = $request->validate([
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'per_page' => ['sometimes', 'integer', 'between:1,25'],
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
                $available[$id] = ($available[$id] ?? 0) + (int) $line['amount_minor'];
            }
        }

        $query = DB::table('finance_invoices')->where('vendor_id', $vendor)->where('currency', $currency);
        if (isset($values['from'])) {
            $query->where('created_at', '>=', $values['from'].' 00:00:00');
        }
        if (isset($values['to'])) {
            $query->where('created_at', '<=', $values['to'].' 23:59:59.999999');
        }
        $page = $query->orderByDesc('id')->paginate((int) ($values['per_page'] ?? 20));

        $rows = $page->getCollection()->map(function (object $invoice) use ($available) {
            $paid = (int) DB::table('finance_payments')->where('invoice_id', $invoice->id)->sum('amount_minor');
            $refunds = DB::table('finance_refunds')->where('invoice_id', $invoice->id);
            $refunded = (int) (clone $refunds)->sum('amount_minor');
            $vendorRefund = (int) (clone $refunds)->sum('vendor_minor');
            $noncashCredit = (int) DB::table('finance_unpaid_credits')
                ->where('invoice_id', $invoice->id)->sum('amount_minor');
            $settlements = DB::table('commercial_settlement_lines as l')
                ->join('commercial_settlements as s', 's.id', '=', 'l.settlement_id')
                ->where('l.invoice_id', $invoice->id)
                ->whereIn('s.state', ['pending', 'approved', 'simulated_paid', 'provider_paid'])
                ->get(['s.state', 'l.amount_minor']);
            $pending = (int) $settlements->filter(
                fn ($line) => in_array($line->state, ['pending', 'approved'], true)
            )->sum('amount_minor');
            $completed = (int) $settlements->filter(
                fn ($line) => in_array($line->state, ['simulated_paid', 'provider_paid'], true)
            )->sum('amount_minor');

            return [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => $invoice->number,
                'currency' => $invoice->currency,
                'minor_units' => (int) $invoice->minor_units,
                'subtotal_minor' => (int) $invoice->subtotal_minor,
                'tax_minor' => (int) $invoice->tax_minor,
                'commission_minor' => (int) $invoice->commission_minor,
                'vendor_allocated_minor' => (int) $invoice->vendor_minor,
                'paid_minor' => $paid,
                'refunded_minor' => $refunded,
                'vendor_refunded_minor' => $vendorRefund,
                'unpaid_credited_minor' => $noncashCredit,
                'net_collected_minor' => $paid - $refunded,
                'amount_due_minor' => max(0, (int) $invoice->total_minor - $paid - $noncashCredit),
                'pending_reserved_minor' => $pending,
                'completed_settlement_minor' => $completed,
                'available_minor' => (int) ($available[(int) $invoice->id] ?? 0),
                'evidence' => 'SIMULATED',
            ];
        })->values()->all();

        return [
            'vendor_id' => $vendor, 'currency' => $currency, 'minor_units' => $balance['minor_units'],
            'data' => $rows, 'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            'overall_available_minor' => $balance['available_minor'],
            'overall_balance_scope' => 'ALL_VENDOR_INVOICES_AND_APPROVED_ADJUSTMENTS',
            'hold_days' => (int) ($schedule->hold_days ?? 0),
            'evidence' => 'SIMULATED_CANONICAL_SETTLEMENT',
            'real_payout_enabled' => false, 'external_psp_or_bank_verified' => false,
        ];
    }

    public function index(Request $request, int $vendor, SettlementService $service)
    {
        return response()->json($this->page($request, $vendor, $service))
            ->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, int $vendor, SettlementService $service)
    {
        $report = $this->page($request, $vendor, $service);

        return response()->streamDownload(static function () use ($report): void {
            $output = fopen('php://output', 'wb');
            $fields = [
                'invoice_id', 'invoice_number', 'currency', 'minor_units', 'subtotal_minor',
                'tax_minor', 'commission_minor', 'vendor_allocated_minor', 'paid_minor',
                'refunded_minor', 'vendor_refunded_minor', 'unpaid_credited_minor',
                'net_collected_minor', 'amount_due_minor', 'pending_reserved_minor',
                'completed_settlement_minor', 'available_minor', 'evidence',
            ];
            fputcsv($output, $fields);
            foreach ($report['data'] as $row) {
                fputcsv($output, array_map(static fn ($field) => $row[$field], $fields));
            }
            fclose($output);
        }, 'simulated-vendor-earnings-page.csv', [
            'Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
