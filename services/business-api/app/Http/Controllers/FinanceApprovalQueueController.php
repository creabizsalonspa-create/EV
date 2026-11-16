<?php

namespace App\Http\Controllers;

use App\Security\CurrentFinanceReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Read-only Admin approvals; never a bank, PSP, ledger or settlement authority. */
final class FinanceApprovalQueueController extends Controller
{
    private const MAX_BROWSER_MINOR = 9_007_199_254_740_991;

    public function index(Request $request)
    {
        // Keep the live Admin identity locked until all private review rows
        // are materialized. A revoked cached browser role is never enough.
        return DB::transaction(fn () => $this->currentIndex($request), 3);
    }

    private function currentIndex(Request $request)
    {
        $current = app(CurrentFinanceReader::class)->requireCurrent($request);
        abort_unless($current->role === 'admin', 403);
        $filters = $request->validate([
            'kind' => ['sometimes', Rule::in(['all', 'refund', 'settlement', 'adjustment'])],
            'currency' => ['sometimes', 'regex:/\A[A-Z]{3}\z/'],
            'vendor_id' => ['sometimes', 'regex:/\A[1-9][0-9]{0,17}\z/'],
            'per_page' => ['sometimes', 'integer', 'between:1,25'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
        ]);

        $sources = [];
        if (in_array($filters['kind'] ?? 'all', ['all', 'refund'], true)) {
            $sources[] = DB::table('finance_refund_requests as r')
                ->join('finance_invoices as i', 'i.id', '=', 'r.invoice_id')
                ->where('r.status', 'pending')
                ->selectRaw("'refund' AS kind, r.id AS record_id, i.vendor_id, i.currency, i.minor_units, r.amount_minor, 'pending' AS status, r.created_at, i.id AS invoice_id");
        }
        if (in_array($filters['kind'] ?? 'all', ['all', 'settlement'], true)) {
            $sources[] = DB::table('commercial_settlements as s')
                ->where('s.state', 'pending')
                ->selectRaw("'settlement' AS kind, s.id AS record_id, s.vendor_id, s.currency, s.minor_units, s.amount_minor, 'pending' AS status, s.created_at, NULL AS invoice_id");
        }
        if (in_array($filters['kind'] ?? 'all', ['all', 'adjustment'], true)) {
            $sources[] = DB::table('commercial_adjustments as a')
                ->leftJoin('commercial_adjustment_approvals as p', 'p.adjustment_id', '=', 'a.id')
                ->whereNull('p.id')
                ->selectRaw("'adjustment' AS kind, a.id AS record_id, a.vendor_id, a.currency, a.minor_units, a.amount_minor, 'awaiting_approval' AS status, a.created_at, NULL AS invoice_id");
        }
        $combined = array_shift($sources);
        foreach ($sources as $source) {
            $combined->unionAll($source);
        }
        $query = DB::query()->fromSub($combined, 'q')
            ->when(isset($filters['currency']), fn ($q) => $q->where('currency', $filters['currency']))
            ->when(isset($filters['vendor_id']), fn ($q) => $q->where('vendor_id', (int) $filters['vendor_id']))
            ->orderByDesc('created_at')->orderByDesc('record_id')->orderBy('kind');
        $page = $query->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(static function ($row): array {
                // Refuse silently rounded JSON amounts in Customer/Vendor/Admin.
                $amount = filter_var($row->amount_minor, FILTER_VALIDATE_INT);
                abort_if($amount === false || $amount > self::MAX_BROWSER_MINOR ||
                    $amount < -self::MAX_BROWSER_MINOR, 409,
                    'Financial approval amount exceeds browser-safe integer bounds.');

                return [
                    'kind' => $row->kind,
                    'record_id' => (int) $row->record_id,
                    'vendor_id' => (int) $row->vendor_id,
                    'invoice_id' => $row->invoice_id === null ? null : (int) $row->invoice_id,
                    'currency' => $row->currency,
                    'minor_units' => $row->minor_units === null ? null : (int) $row->minor_units,
                    'amount_minor' => $amount,
                    'status' => $row->status,
                    'created_at' => $row->created_at,
                ];
            })->values()->all(),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'admin_only' => true,
            'read_only' => true,
            'mutations_performed' => false,
            'real_money_moved' => false,
            'evidence' => 'SIMULATED_FINANCIAL_APPROVAL_REVIEW',
            'not_a_payment_or_payout_authority' => true,
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * One page only, identical authorization and filters as the JSON review.
     * An export must never silently broaden access or lose a page bound.
     */
    public function export(Request $request)
    {
        $payload = $this->index($request)->getData(true);

        return response()->streamDownload(static function () use ($payload): void {
            $handle = fopen('php://output', 'wb');
            $fields = [
                'kind', 'record_id', 'vendor_id', 'invoice_id', 'currency',
                'minor_units', 'amount_minor', 'status', 'created_at',
            ];
            fputcsv($handle, $fields, ',', '"', '');
            foreach ($payload['data'] as $item) {
                fputcsv($handle, array_map(static fn (string $field) => $item[$field] ?? '', $fields), ',', '"', '');
            }
            fclose($handle);
        }, 'simulated-pending-finance-approvals.csv', [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
