<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Security\CurrentFinanceReader;
use App\Services\Commercial\Disputes\ExternalDisputes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExternalDisputesController extends Controller
{
    private const MAX_EXACT = 9_007_199_254_740_991;

    private const STATES = ['warning_needs_response', 'warning_under_review', 'warning_closed',
        'needs_response', 'under_review', 'won', 'lost'];

    private static function exact(mixed $value, int $minimum = 0, int $maximum = self::MAX_EXACT): int
    {
        abort_unless(is_int($value) || is_string($value), 409, 'Invalid dispute source integer.');
        $number = filter_var($value, FILTER_VALIDATE_INT);
        abort_unless($number !== false && $number >= $minimum && $number <= $maximum,
            409, 'Invalid dispute source bounds.');

        return $number;
    }

    private static function verifySource(object $row): array
    {
        abort_unless(in_array($row->status, self::STATES, true)
            && is_string($row->currency) && preg_match('/\A[A-Z]{3}\z/', $row->currency) === 1,
            409, 'Invalid dispute source state or currency.');

        return [
            'id' => self::exact($row->id, 1),
            'invoice_id' => self::exact($row->invoice_id, 1),
            'amount_minor' => self::exact($row->amount_minor, 1),
            'minor_units' => self::exact($row->minor_units, 0, 3),
        ];
    }

    /** Must run within a transaction that retains the live user row lock. */
    private function scoped(Request $r)
    {
        $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
        $query = DB::table('external_disputes as d')
            ->join('gateway_checkouts as c', 'c.id', '=', 'd.checkout_id')
            ->join('finance_invoices as i', 'i.id', '=', 'c.invoice_id')
            ->select('d.*', 'i.id as invoice_id', 'i.minor_units');
        if ($actor->role !== 'admin') {
            $query->where($actor->role === 'vendor' ? 'i.vendor_id' : 'i.user_id', $actor->id);
        }

        return $query;
    }

    private function currentAdmin(Request $r): User
    {
        return DB::transaction(function () use ($r) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($actor->role === 'admin', 403);

            return $actor;
        }, 3);
    }

    private function sanitized(object $row): array
    {
        $value = array_replace((array) $row, self::verifySource($row));
        unset($value['provider_reference'], $value['charge_reference']);
        $value['settlement_hold'] = (bool) $value['settlement_hold'];
        $value['deadline_passed'] = in_array($value['status'], ['needs_response', 'warning_needs_response'], true) && $value['evidence_due_at'] && Carbon::parse($value['evidence_due_at'])->lessThanOrEqualTo(now());
        $value['requires_financial_resolution'] = $value['status'] === 'lost';

        return $value + ['evidence' => 'STRIPE_TEST_CONTRACT', 'real_money_moved' => false, 'chargeback_accounting' => 'NOT_POSTED_REQUIRES_FINANCIAL_RESOLUTION'];
    }

    public function index(Request $r)
    {
        $v = $r->validate([
            'attention' => 'sometimes|boolean',
            'currency' => ['sometimes', 'regex:/\A[A-Z]{3}\z/'],
            'invoice_id' => ['sometimes', 'regex:/\A[1-9][0-9]{0,17}\z/'],
            'status' => ['sometimes', Rule::in(self::STATES)],
            'per_page' => 'sometimes|integer|between:1,25',
            'page' => 'sometimes|integer|between:1,10000',
        ]);

        return DB::transaction(function () use ($r, $v) {
            $query = $this->scoped($r)
                ->when(isset($v['currency']), fn ($q) => $q->where('d.currency', $v['currency']))
                ->when(isset($v['invoice_id']), fn ($q) => $q->where('i.id', (int) $v['invoice_id']))
                ->when(isset($v['status']), fn ($q) => $q->where('d.status', $v['status']));
            if ($v['attention'] ?? false) {
                $query->where(function ($q) {
                    $q->whereIn('d.status', ['lost', 'needs_response', 'warning_needs_response'])
                        ->orWhereExists(function ($sub) {
                            $sub->selectRaw('1')->from('external_dispute_evidence as e')
                                ->whereColumn('e.dispute_id', 'd.id')
                                ->whereIn('e.status', ['uncertain', 'sending']);
                        });
                });
            }

            $page = $query->orderByDesc('d.id')
                ->paginate((int) ($v['per_page'] ?? 25), ['*'], 'page', (int) ($v['page'] ?? 1))
                ->through(fn ($row) => $this->sanitized($row));

            return response()->json($page->toArray() + [
                'scope' => [
                    'currency' => $v['currency'] ?? null,
                    'invoice_id' => isset($v['invoice_id']) ? (int) $v['invoice_id'] : null,
                    'status' => $v['status'] ?? null,
                    'attention' => (bool) ($v['attention'] ?? false),
                ],
                'evidence' => 'STRIPE_TEST_CONTRACT',
                'real_money_moved' => false,
            ])
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    /**
     * Read-only Admin triage; never polls providers, changes settlement holds
     * or posts any financial or external dispute operation.
     */
    public function deadlineQueue(Request $r)
    {
        return DB::transaction(function () use ($r) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($actor->role === 'admin', 403);
            $values = $r->validate([
                'window_hours' => 'sometimes|integer|between:0,168',
                'currency' => ['sometimes', 'regex:/\A[A-Z]{3}\z/'],
                'invoice_id' => ['sometimes', 'regex:/\A[1-9][0-9]{0,17}\z/'],
                'per_page' => 'sometimes|integer|between:1,25',
                'page' => 'sometimes|integer|between:1,10000',
            ]);
            $window = (int) ($values['window_hours'] ?? 48);
            $observedAt = now();
            $cutoff = $observedAt->copy()->addHours($window);
            $query = $this->scoped($r)
                ->when(isset($values['currency']), fn ($q) => $q->where('d.currency', $values['currency']))
                ->when(isset($values['invoice_id']), fn ($q) => $q->where('i.id', (int) $values['invoice_id']))
                ->selectSub(DB::table('external_dispute_evidence as pending')
                    ->selectRaw('COUNT(*)')->whereColumn('pending.dispute_id', 'd.id')
                    ->whereIn('pending.status', ['sending', 'uncertain']), 'uncertain_evidence_count')
                ->where(function ($q) use ($cutoff) {
                    $q->where('d.status', 'lost')
                        ->orWhere(function ($q) use ($cutoff) {
                            $q->whereIn('d.status', ['needs_response', 'warning_needs_response'])
                                ->whereNotNull('d.evidence_due_at')
                                ->where('d.evidence_due_at', '<=', $cutoff);
                        })
                        ->orWhereExists(function ($q) {
                            $q->selectRaw('1')->from('external_dispute_evidence as e')
                                ->whereColumn('e.dispute_id', 'd.id')
                                ->whereIn('e.status', ['sending', 'uncertain']);
                        });
                });
            // SQLite sorts NULLs first while PostgreSQL sorts them last for ASC.
            // Keep active evidence deadlines ahead of unresolved lost cases on both.
            $page = $query
                ->orderByRaw("CASE WHEN d.status IN ('needs_response', 'warning_needs_response') AND d.evidence_due_at IS NOT NULL THEN 0 WHEN d.status = 'lost' THEN 1 ELSE 2 END")
                ->orderByRaw('CASE WHEN d.evidence_due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('d.evidence_due_at')->orderBy('d.id')
                ->paginate((int) ($values['per_page'] ?? 20),
                    ['*'], 'page', (int) ($values['page'] ?? 1));
            $rows = $page->getCollection()->map(function ($row) use ($observedAt) {
                $source = self::verifySource($row);
                $unresolved = self::exact($row->uncertain_evidence_count) > 0;
                $needs = in_array($row->status, ['needs_response', 'warning_needs_response'], true);
                $expired = $needs && $row->evidence_due_at
                    && Carbon::parse($row->evidence_due_at)->lessThanOrEqualTo($observedAt);
                $urgency = $row->status === 'lost' ? 'financial_resolution_required'
                    : ($unresolved ? 'provider_submission_uncertain'
                        : ($expired ? 'deadline_passed' : 'evidence_due_soon'));

                return [
                    ...$source,
                    'currency' => $row->currency,
                    'status' => $row->status,
                    'urgency' => $urgency,
                    'evidence_due_at' => $row->evidence_due_at,
                    'provider_observed_at' => $row->provider_observed_at,
                    'provider_observation_stale' => ! $row->provider_observed_at
                        || Carbon::parse($row->provider_observed_at)->lessThan($observedAt->copy()->subDay()),
                    'deadline_passed' => (bool) $expired,
                    'settlement_hold' => (bool) $row->settlement_hold,
                    'requires_financial_resolution' => $row->status === 'lost',
                    'has_uncertain_evidence' => $unresolved,
                    'evidence' => 'STRIPE_TEST_CONTRACT',
                    'real_money_moved' => false,
                ];
            })->values()->all();

            return response()->json([
                'data' => $rows,
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'window_hours' => $window,
                'scope' => [
                    'currency' => $values['currency'] ?? null,
                    'invoice_id' => isset($values['invoice_id']) ? (int) $values['invoice_id'] : null,
                ],
                'provider_actions_performed' => false,
                'real_money_moved' => false,
                'evidence' => 'STRIPE_TEST_CONTRACT',
            ])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function show(Request $r, int $dispute)
    {
        return DB::transaction(function () use ($r, $dispute) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $row = $this->scoped($r)->where('d.id', $dispute)->first();
            abort_unless($row, 404);
            $events = DB::table('external_dispute_events')->where('dispute_id', $dispute)
                ->orderBy('id')->get(['id', 'type', 'status', 'created_at']);
            $operations = $actor->role === 'admin'
                ? DB::table('external_dispute_evidence')->where('dispute_id', $dispute)
                    ->orderBy('id')->get([
                        'id', 'actor_id', 'submit', 'status', 'retry_until', 'created_at', 'updated_at',
                    ])
                : [];

            return response()->json([
                'dispute' => $this->sanitized($row), 'events' => $events,
                'evidence_submissions' => $operations,
            ])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function reconcile(Request $r, int $dispute, ExternalDisputes $service)
    {
        $actor = $this->currentAdmin($r);
        DB::transaction(function () use ($r, $dispute) {
            abort_unless($this->scoped($r)->where('d.id', $dispute)->exists(), 404);
        }, 3);
        // The service owns the provider read/uncertainty transaction. No
        // controller transaction may span a remote test-provider request.
        $service->reconcile($dispute, $actor);

        return $this->show($r, $dispute);
    }

    public function evidence(Request $r, int $dispute, ExternalDisputes $service)
    {
        $actor = $this->currentAdmin($r);
        $v = $r->validate(['idempotency_key' => 'required|string|max:100', 'submit' => 'required|boolean', 'evidence' => 'required|array:product_description,service_date,refund_policy_disclosure,refund_refusal_explanation,access_activity_log,uncategorized_text', 'evidence.*' => 'required|string|max:5000']);
        $service->evidence($dispute, $actor->id, $v['evidence'], $v['submit'], $v['idempotency_key'], $actor);

        return $this->show($r, $dispute);
    }

    public function retry(Request $r, int $dispute, int $operation, ExternalDisputes $service)
    {
        $actor = $this->currentAdmin($r);
        // Retry is server-owned: changing evidence or selecting a new provider
        // identity is impossible through this endpoint.
        $r->validate(['evidence' => 'prohibited', 'idempotency_key' => 'prohibited', 'submit' => 'prohibited']);
        $service->retryEvidence($dispute, $operation, $actor->id, $actor);

        return $this->show($r, $dispute);
    }

    public function webhook(Request $r, ExternalDisputes $service)
    {
        return response()->json($service->webhook($r->getContent(), (string) $r->header('Stripe-Signature')));
    }
}
