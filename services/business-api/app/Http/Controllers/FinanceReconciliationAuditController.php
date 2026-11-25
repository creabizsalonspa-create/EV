<?php

namespace App\Http\Controllers;

use App\Models\Finance\Invoice;
use App\Security\CurrentFinanceReader;
use App\Services\Finance\InvoiceReconciliationAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FinanceReconciliationAuditController extends Controller
{
    /** CSV output only: prevent invoice references opening as spreadsheet formulas. */
    private static function literalCsv(string $value): string
    {
        return preg_match('/^[\\p{Z}\\p{Cc}\\p{Cf}]*[=+\\-@]/u', $value)
            ? "'".$value : $value;
    }

    private function page(Request $request, InvoiceReconciliationAudit $audit): array
    {
        $user = app(CurrentFinanceReader::class)->requireCurrent($request);
        abort_unless(in_array($user->role, ['vendor', 'admin'], true), 403);
        $values = $request->validate([
            'currency' => ['sometimes', 'regex:/\A[A-Z]{3}\z/'],
            'vendor_id' => ['sometimes', 'regex:/\A[1-9][0-9]{0,17}\z/'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'per_page' => ['sometimes', 'integer', 'between:1,25'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
        ]);

        $query = Invoice::query();
        $vendor = isset($values['vendor_id']) ? (int) $values['vendor_id'] : null;
        if ($user->role === 'vendor') {
            abort_if($vendor !== null && $vendor !== $user->id, 404);
            $vendor = $user->id;
        }
        if ($vendor !== null) {
            $query->where('vendor_id', $vendor);
        }
        if (isset($values['currency'])) {
            $query->where('currency', $values['currency']);
        }
        if (isset($values['from'])) {
            $query->where('created_at', '>=', $values['from'].' 00:00:00');
        }
        if (isset($values['to'])) {
            $query->where('created_at', '<=', $values['to'].' 23:59:59.999999');
        }

        // The caller's page is validated before pagination, including CSV export.
        // Do not silently coerce non-numeric or unbounded offsets into a private read.
        $page = $query->orderByDesc('id')->paginate(
            (int) ($values['per_page'] ?? 20), ['*'], 'page', (int) ($values['page'] ?? 1)
        );

        return [
            'data' => $page->getCollection()->map(fn (Invoice $invoice) => $audit->inspect($invoice))->values()->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'scope' => [
                'vendor_id' => $vendor,
                'currency' => $values['currency'] ?? null,
                'from' => $values['from'] ?? null,
                'to' => $values['to'] ?? null,
            ],
            'evidence' => 'SIMULATED_INTERNAL_RECONCILIATION',
            'external_psp_or_bank_verified' => false,
        ];
    }

    public function index(Request $request, InvoiceReconciliationAudit $audit)
    {
        return DB::transaction(fn () => response()->json($this->page($request, $audit))
            ->header('Cache-Control', 'private, no-store'), 3);
    }

    public function export(Request $request, InvoiceReconciliationAudit $audit)
    {
        // Same tenant-scoped, validated page as JSON; never an unbounded export.
        try {
            $payload = DB::transaction(fn () => $this->page($request, $audit), 3);
        } catch (ValidationException $error) {
            // A plain CSV GET still follows an API validation contract.
            return response()->json([
                'message' => 'Invalid private financial export filters.',
                'errors' => $error->errors(),
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        return response()->streamDownload(static function () use ($payload): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, [
                'invoice_id', 'invoice_number', 'currency', 'minor_units', 'invoiced_minor',
                'paid_minor', 'refunded_minor', 'unpaid_credited_minor', 'net_collected_minor',
                'amount_due_minor', 'journal_count', 'status', 'issues', 'evidence',
                'external_psp_or_bank_verified',
            ], ',', '"', '');
            foreach ($payload['data'] as $row) {
                fputcsv($output, [
                    $row['invoice_id'], self::literalCsv((string) $row['invoice_number']),
                    $row['currency'], $row['minor_units'],
                    $row['invoiced_minor'], $row['paid_minor'], $row['refunded_minor'],
                    $row['unpaid_credited_minor'], $row['net_collected_minor'],
                    $row['amount_due_minor'], $row['journal_count'], $row['status'],
                    implode(';', $row['issues']), $row['evidence'], 'false',
                ], ',', '"', '');
            }
            fclose($output);
        }, 'simulated-finance-reconciliation-page.csv', [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
