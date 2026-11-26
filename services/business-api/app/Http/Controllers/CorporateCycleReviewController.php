<?php

namespace App\Http\Controllers;

use App\Models\Fleet;
use App\Models\User;
use App\Security\CurrentFinanceReader;
use App\Services\Commercial\CorporateBilling;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Bounded planning and explicit TEST-only issuance through canonical corporate billing. */
final class CorporateCycleReviewController extends Controller
{
    private const MAX_EXACT = 9_007_199_254_740_991;

    private function amount(mixed $value): int
    {
        abort_unless(is_int($value) || is_string($value), 409, 'Invalid corporate cycle source.');
        $amount = filter_var($value, FILTER_VALIDATE_INT);
        abort_unless($amount !== false && $amount >= 0 && $amount <= self::MAX_EXACT, 409, 'Invalid corporate cycle amount.');

        return $amount;
    }

    private function add(int $left, int $right): int
    {
        return $this->amount($left + $right);
    }

    private function manager(Request $request, int $agreement, CorporateBilling $billing): array
    {
        $actor = app(CurrentFinanceReader::class)->requireCurrent($request);
        $a = $billing->agreement($agreement);
        $fleet = Fleet::whereKey($a->fleet_id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->role === 'admin' || (int) $fleet->owner_id === $actor->id, 404);

        return [$actor, $a, $fleet];
    }

    private function canIssue(User $actor, Fleet $fleet): bool
    {
        return app()->environment(['local', 'testing']) && config('payments.provider') === 'mock'
            && $actor->role === 'customer' && (int) $fleet->owner_id === $actor->id;
    }

    private function snapshot(object $a, Fleet $fleet, User $actor): array
    {
        // Match existing statement issuance's application calendar month.
        // The review is for the previous completed month, not a new
        // interpretation of source invoice dates or legal tax periods.
        $now = now();
        $end = $now->copy()->startOfMonth();
        $start = $end->copy()->subMonthNoOverflow();
        $day = $this->amount($a->billing_day);
        abort_unless($day >= 1 && $day <= 28, 409, 'Invalid corporate billing day.');
        $reviewAt = $end->copy()->addDays($day - 1);
        $accepted = DB::table('corporate_agreement_acceptances')->where('agreement_id', $a->id)->exists();
        $active = $accepted && $now->gte(Carbon::parse($a->effective_from))
            && $now->lt(Carbon::parse($a->expires_at));

        // 101 is a sentinel, not a silent partial batch. If exceeded,
        // the owner must use the existing explicit selection workflow.
        $rows = DB::table('corporate_claims as c')
            ->join('corporate_claim_decisions as d', 'd.claim_id', '=', 'c.id')
            ->join('finance_invoices as i', 'i.id', '=', 'c.invoice_id')
            ->where('c.agreement_id', $a->id)->where('d.decision', 'approved')
            ->where('i.created_at', '>=', $start)->where('i.created_at', '<', $end)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('corporate_statement_lines as l')->whereColumn('l.claim_id', 'c.id'))
            ->orderBy('c.id')->limit(101)
            ->get(['c.id', 'c.invoice_id', 'c.driver_id', 'c.currency', 'c.minor_units', 'c.amount_minor',
                'i.user_id', 'i.currency as invoice_currency', 'i.minor_units as invoice_precision',
                'i.subtotal_minor', 'i.tax_minor', 'i.total_minor']);

        $tooMany = $rows->count() > 100;
        $items = [];
        $total = 0;
        $blocked = 0;
        if (! $tooMany) {
            foreach ($rows as $row) {
                $amount = $this->amount($row->amount_minor);
                $canonical = $this->amount($row->total_minor);
                abort_unless($amount === $canonical
                    && $this->add($this->amount($row->subtotal_minor), $this->amount($row->tax_minor)) === $canonical
                    && $row->currency === $a->currency && $row->invoice_currency === $a->currency
                    && (int) $row->minor_units === (int) $a->minor_units
                    && (int) $row->invoice_precision === (int) $a->minor_units
                    && (int) $row->driver_id === (int) $row->user_id, 409, 'Corporate claim does not match original invoice.');

                $id = (int) $row->invoice_id;
                $held = DB::table('finance_payments')->where('invoice_id', $id)->exists()
                    || DB::table('finance_unpaid_credits')->where('invoice_id', $id)->exists()
                    || DB::table('gateway_checkouts')->where('invoice_id', $id)->exists()
                    || DB::table('finance_refund_requests')->where('invoice_id', $id)->where('status', 'pending')->exists()
                    || DB::table('gateway_cases')->where('invoice_id', $id)->where('status', 'open')->exists()
                    || DB::table('corporate_hosted_lines as l')->join('corporate_hosted_checkouts as h', 'h.id', '=', 'l.checkout_id')
                        ->where('l.invoice_id', $id)->whereNotIn('h.status', ['cancelled', 'expired'])->exists();
                $total = $this->add($total, $amount);
                $blocked += $held ? 1 : 0;
                $items[] = [
                    'claim_id' => $this->amount($row->id),
                    'invoice_id' => $this->amount($row->invoice_id),
                    'amount_minor' => $amount,
                    'held' => $held,
                ];
            }
        }
        $limit = $this->amount($a->credit_limit_minor);
        $open = 0;
        $outstanding = DB::table('corporate_statements')->where('fleet_id', $fleet->id)
            ->where('currency', $a->currency)
            ->whereNotIn('id', DB::table('corporate_receipts')->select('statement_id'))
            ->orderBy('id')->cursor();
        foreach ($outstanding as $s) {
            $open = $this->add($open, $this->amount($s->total_minor));
        }
        $remaining = max(0, $limit - $open);
        $reason = match (true) {
            ! $active => 'agreement_inactive',
            $now->lt($reviewAt) => 'before_billing_day',
            $tooMany => 'selection_required',
            $items === [] => 'no_approved_claims',
            $blocked > 0 => 'source_hold',
            $total === 0 || $total > $remaining => 'credit_limit',
            default => 'ready_for_review',
        };

        return [
            'agreement_id' => (int) $a->id, 'period' => $start->format('Y-m'),
            'review_at' => $reviewAt->toIso8601String(), 'billing_day' => $day,
            'currency' => $a->currency, 'minor_units' => (int) $a->minor_units,
            'claims' => $items, 'claim_count' => $rows->count(),
            'count_is_lower_bound' => $tooMany,
            'total_minor' => $tooMany ? null : $total,
            'remaining_credit_minor' => $remaining,
            'blocked_count' => $blocked, 'state' => $reason,
            'read_only' => true, 'statement_issued' => false,
            'real_payment_processed' => false,
            'can_issue_reviewed_batch' => $this->canIssue($actor, $fleet) && $reason === 'ready_for_review',
        ];
    }

    public function show(Request $request, int $agreement, CorporateBilling $billing)
    {
        return DB::transaction(function () use ($request, $agreement, $billing) {
            [$actor, $a, $fleet] = $this->manager($request, $agreement, $billing);

            return response()->json($this->snapshot($a, $fleet, $actor))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function issue(Request $request, int $agreement, CorporateBilling $billing)
    {
        return DB::transaction(function () use ($request, $agreement, $billing) {
            [$actor, $a, $fleet] = $this->manager($request, $agreement, $billing);
            abort_unless($actor->role === 'customer' && (int) $fleet->owner_id === $actor->id, 403);
            abort_unless($this->canIssue($actor, $fleet), 503, 'Reviewed batch issuance is local TEST only.');
            $v = $request->validate([
                'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/D'],
                'claim_ids' => 'required|array|min:1|max:100',
                'claim_ids.*' => 'required|integer|distinct|min:1|max:9007199254740991',
                'total_minor' => 'required|integer|min:1|max:9007199254740991',
                'consent' => 'required|accepted',
            ]);
            abort_unless($request->input('consent') === true, 422, 'Explicit reviewed batch consent is required.');
            $ids = array_map(fn ($id) => $this->amount($id), $v['claim_ids']);
            sort($ids);
            abort_unless(count($ids) === count(array_unique($ids)), 422);
            $total = $this->amount($v['total_minor']);
            // Stable server-derived identity. Exact repeats of a reviewed
            // immutable batch cannot create a replacement statement, even
            // after a lost response, refresh, or month rollover.
            $key = 'cycle-review-v1:'.hash('sha256', json_encode([$agreement, $v['period'], $ids, $total], JSON_THROW_ON_ERROR));
            $requestKey = hash('sha256', $actor->id.':'.$key);
            $statement = DB::table('corporate_statements')->where('request_key', $requestKey)->first();
            $replayed = $statement !== null;
            if (! $statement) {
                $review = $this->snapshot($a, $fleet, $actor);
                abort_unless($review['can_issue_reviewed_batch'] && $review['period'] === $v['period']
                    && $review['total_minor'] === $total && array_column($review['claims'], 'claim_id') === $ids,
                    409, 'The complete reviewed batch changed. Refresh before issuing.');
                $statement = $billing->issue($agreement, $actor->id, [
                    'period' => $v['period'], 'claim_ids' => $ids, 'idempotency_key' => $key,
                ]);
            }
            $issuedIds = DB::table('corporate_statement_lines')->where('statement_id', $statement->id)
                ->orderBy('claim_id')->pluck('claim_id')->map(fn ($id) => $this->amount($id))->all();
            abort_unless((int) $statement->agreement_id === $agreement
                && (int) $statement->fleet_id === (int) $fleet->id
                && (int) $statement->issued_by === $actor->id
                && (int) $statement->payer_id === $actor->id
                && $statement->period === $v['period'] && $statement->currency === $a->currency
                && (int) $statement->minor_units === (int) $a->minor_units
                && $this->amount($statement->total_minor) === $total && $issuedIds === $ids,
                409, 'Reviewed statement source does not reconcile.');

            return response()->json([
                'statement_id' => $this->amount($statement->id),
                'agreement_id' => $agreement, 'period' => $statement->period,
                'claim_ids' => $issuedIds, 'total_minor' => $total,
                'currency' => $statement->currency, 'minor_units' => (int) $statement->minor_units,
                'statement_issued' => true, 'replayed' => $replayed,
                'real_payment_processed' => false, 'evidence' => 'TEST_REVIEWED_STATEMENT',
            ], $replayed ? 200 : 201)->header('Cache-Control', 'private, no-store');
        }, 3);
    }
}
