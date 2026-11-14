<?php

namespace App\Http\Controllers;

use App\Services\Commercial\Disputes\ExternalDisputes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExternalDisputesController extends Controller
{
    private function scoped(Request $r)
    {
        $query = DB::table('external_disputes as d')->join('gateway_checkouts as c', 'c.id', '=', 'd.checkout_id')->join('finance_invoices as i', 'i.id', '=', 'c.invoice_id')->select('d.*', 'i.id as invoice_id', 'i.minor_units');
        if ($r->user()->role !== 'admin') {
            $query->where($r->user()->role === 'vendor' ? 'i.vendor_id' : 'i.user_id', $r->user()->id);
        }

        return $query;
    }

    private function sanitized(object $row): array
    {
        $value = (array) $row;
        unset($value['provider_reference'], $value['charge_reference']);
        $value['settlement_hold'] = (bool) $value['settlement_hold'];
        $value['deadline_passed'] = in_array($value['status'], ['needs_response', 'warning_needs_response'], true) && $value['evidence_due_at'] && Carbon::parse($value['evidence_due_at'])->isPast();
        $value['requires_financial_resolution'] = $value['status'] === 'lost';

        return $value + ['evidence' => 'STRIPE_TEST_CONTRACT', 'real_money_moved' => false, 'chargeback_accounting' => 'NOT_POSTED_REQUIRES_FINANCIAL_RESOLUTION'];
    }

    public function index(Request $r)
    {
        $v = $r->validate(['attention' => 'sometimes|boolean']);
        $query = $this->scoped($r);
        if ($v['attention'] ?? false) {
            $query->where(function ($q) {
                $q->whereIn('d.status', ['lost', 'needs_response', 'warning_needs_response'])->orWhereExists(function ($sub) {
                    $sub->selectRaw('1')->from('external_dispute_evidence as e')->whereColumn('e.dispute_id', 'd.id')->whereIn('e.status', ['uncertain', 'sending']);
                });
            });
        }

        return $query->orderByDesc('d.id')->paginate(25)->through(fn ($row) => $this->sanitized($row));
    }

    public function show(Request $r, int $dispute)
    {
        $row = $this->scoped($r)->where('d.id', $dispute)->first();
        abort_unless($row, 404);
        $events = DB::table('external_dispute_events')->where('dispute_id', $dispute)->orderBy('id')->get(['id', 'type', 'status', 'created_at']);
        $operations = $r->user()->role === 'admin' ? DB::table('external_dispute_evidence')->where('dispute_id', $dispute)->orderBy('id')->get(['id', 'actor_id', 'submit', 'status', 'retry_until', 'created_at', 'updated_at']) : [];

        return response()->json(['dispute' => $this->sanitized($row), 'events' => $events, 'evidence_submissions' => $operations]);
    }

    public function reconcile(Request $r, int $dispute, ExternalDisputes $service)
    {
        abort_unless($r->user()->role === 'admin', 403);
        abort_unless($this->scoped($r)->where('d.id', $dispute)->exists(), 404);
        $service->reconcile($dispute);

        return $this->show($r, $dispute);
    }

    public function evidence(Request $r, int $dispute, ExternalDisputes $service)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['idempotency_key' => 'required|string|max:100', 'submit' => 'required|boolean', 'evidence' => 'required|array:product_description,service_date,refund_policy_disclosure,refund_refusal_explanation,access_activity_log,uncategorized_text', 'evidence.*' => 'required|string|max:5000']);
        $service->evidence($dispute, $r->user()->id, $v['evidence'], $v['submit'], $v['idempotency_key']);

        return $this->show($r, $dispute);
    }

    public function retry(Request $r, int $dispute, int $operation, ExternalDisputes $service)
    {
        abort_unless($r->user()->role === 'admin', 403);
        // Retry is server-owned: changing evidence or selecting a new provider
        // identity is impossible through this endpoint.
        $r->validate(['evidence' => 'prohibited', 'idempotency_key' => 'prohibited', 'submit' => 'prohibited']);
        $service->retryEvidence($dispute, $operation, $r->user()->id);

        return $this->show($r, $dispute);
    }

    public function webhook(Request $r, ExternalDisputes $service)
    {
        return response()->json($service->webhook($r->getContent(), (string) $r->header('Stripe-Signature')));
    }
}
