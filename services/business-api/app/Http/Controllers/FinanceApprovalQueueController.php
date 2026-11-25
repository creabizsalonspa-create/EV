<?php

namespace App\Http\Controllers;

use App\Security\CurrentFinanceReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Read-only Admin approvals; never a bank, PSP, ledger or settlement authority. */
final class FinanceApprovalQueueController extends Controller
{
    private const MAX_BROWSER_MINOR = 9_007_199_254_740_991;

    /** Verify signed source values before numeric coercion in Admin review. */
    private static function exactMinor(mixed $value): int
    {
        abort_unless(is_int($value) || is_string($value), 409,
            'Financial approval source is not an exact integer.');
        $amount = filter_var($value, FILTER_VALIDATE_INT);
        abort_unless($amount !== false &&
            $amount <= self::MAX_BROWSER_MINOR &&
            $amount >= -self::MAX_BROWSER_MINOR,
            409, 'Financial approval amount exceeds browser-safe integer bounds.');

        return $amount;
    }

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
        $filtered = DB::query()->fromSub($combined, 'q')
            ->when(isset($filters['currency']), fn ($q) => $q->where('currency', $filters['currency']))
            ->when(isset($filters['vendor_id']), fn ($q) => $q->where('vendor_id', (int) $filters['vendor_id']));

        // Every filtered source must be representable, including records
        // outside the requested page. Opposite unsafe signed adjustments
        // must not disappear behind a small, apparently valid net total.
        $invalid = (clone $filtered)->where(function ($q) {
            $q->whereNull('amount_minor')
                ->orWhere('amount_minor', '>', self::MAX_BROWSER_MINOR)
                ->orWhere('amount_minor', '<', -self::MAX_BROWSER_MINOR)
                ->orWhere(fn ($q) => $q->where('kind', '!=', 'adjustment')->where('amount_minor', '<', 0))
                ->orWhereNull('minor_units')->orWhere('minor_units', '<', 0)->orWhere('minor_units', '>', 3)
                ->orWhere('record_id', '<', 1)->orWhere('record_id', '>', self::MAX_BROWSER_MINOR)
                ->orWhere('vendor_id', '<', 1)->orWhere('vendor_id', '>', self::MAX_BROWSER_MINOR)
                ->orWhere(fn ($q) => $q->whereNotNull('invoice_id')
                    ->where(fn ($q) => $q->where('invoice_id', '<', 1)->orWhere('invoice_id', '>', self::MAX_BROWSER_MINOR)));
            if (DB::getDriverName() === 'sqlite') {
                // SQLite integer affinity does not prohibit stored REALs.
                $q->orWhereRaw("typeof(amount_minor) != 'integer' OR typeof(minor_units) != 'integer'");
            }
        })->exists();
        abort_if($invalid, 409, 'Filtered financial review contains an invalid source.');

        // The queue's pageable rows are operational triage, not a balance:
        // preserve distinct kind + currency + minor-unit buckets across
        // ALL currently filtered pending requests, never just this page.
        // Never mix a signed adjustment with a refund or settlement.
        $sourceGroups = (clone $filtered)
            ->select('kind', 'currency', 'minor_units')
            ->selectRaw('COUNT(*) AS review_count, SUM(amount_minor) AS signed_amount_minor')
            ->groupBy('kind', 'currency', 'minor_units')
            ->orderBy('kind')->orderBy('currency')->orderBy('minor_units')
            ->limit(201)->get();
        abort_if($sourceGroups->count() > 200, 422,
            'Narrow the review queue filters before summarizing more than 200 financial scopes.');
        $groups = $sourceGroups->map(static function ($group): array {
            $amount = self::exactMinor($group->signed_amount_minor);
            $count = self::exactMinor($group->review_count);
            $precision = self::exactMinor($group->minor_units);
            abort_unless($count > 0 && $precision >= 0 && $precision <= 3
                && preg_match('/\A[A-Z]{3}\z/', $group->currency) === 1
                && ($group->kind === 'adjustment' || $amount >= 0),
                409, 'Financial approval group source is invalid.');

            return [
                'kind' => $group->kind,
                'currency' => $group->currency,
                'minor_units' => $precision,
                'review_count' => $count,
                'signed_amount_minor' => $amount,
                'basis' => 'PENDING_RECORDS_NOT_FINANCIAL_BALANCE',
            ];
        })->values()->all();

        $query = $filtered->orderByDesc('created_at')
            ->orderByDesc('record_id')->orderBy('kind');
        $page = $query->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));

        $groupCount = 0;
        foreach ($groups as $group) {
            $groupCount = self::exactMinor($groupCount + $group['review_count']);
        }
        abort_unless($groupCount === $page->total(), 409, 'Financial approval source changed; refresh the review.');

        return response()->json([
            'data' => $page->getCollection()->map(static function ($row): array {
                // Refuse silently rounded JSON amounts in Customer/Vendor/Admin.
                $amount = self::exactMinor($row->amount_minor);
                $id = self::exactMinor($row->record_id);
                $vendor = self::exactMinor($row->vendor_id);
                $invoice = $row->invoice_id === null ? null : self::exactMinor($row->invoice_id);
                $precision = self::exactMinor($row->minor_units);
                abort_unless($id > 0 && $vendor > 0 && ($invoice === null || $invoice > 0)
                    && ($row->kind === 'refund') === ($invoice !== null)
                    && $precision >= 0 && $precision <= 3
                    && preg_match('/\A[A-Z]{3}\z/', $row->currency) === 1
                    && ($row->kind === 'adjustment' || $amount >= 0),
                    409, 'Financial approval row source is invalid.');

                return [
                    'kind' => $row->kind,
                    'record_id' => $id,
                    'vendor_id' => $vendor,
                    'invoice_id' => $invoice,
                    'currency' => $row->currency,
                    'minor_units' => $precision,
                    'amount_minor' => $amount,
                    'status' => $row->status,
                    'created_at' => $row->created_at,
                ];
            })->values()->all(),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'summary' => [
                'groups' => $groups,
                'scope' => 'ALL_CURRENT_FILTERED_PENDING_RECORDS_NOT_PAGE',
                'currency_and_precision_isolated' => true,
                'not_a_payment_or_payout_authority' => true,
            ],
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
        try {
            $payload = $this->index($request)->getData(true);
        } catch (ValidationException $error) {
            // CSV downloads are often plain browser GETs, not Accept: JSON.
            // Invalid paging must be an explicit API 422, never a 302
            // redirect with a leaked error flash in the browser session.
            return response()->json([
                'message' => 'Invalid private financial export filters.',
                'errors' => $error->errors(),
            ], 422)->header('Cache-Control', 'private, no-store');
        }

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
