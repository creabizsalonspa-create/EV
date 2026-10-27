<?php

namespace App\Http\Controllers;

use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommercialFinanceController extends Controller
{
    private function invoices(Request $r)
    {
        return Invoice::query()->when($r->user()->role !== 'admin', fn ($q) => $q->where($r->user()->role === 'vendor' ? 'vendor_id' : 'user_id', $r->user()->id));
    }

    public function report(Request $r)
    {
        $v = $r->validate(['currency' => 'sometimes|regex:/^[A-Z]{3}$/', 'from' => 'sometimes|date_format:Y-m-d', 'to' => ['sometimes', 'date_format:Y-m-d', Rule::when($r->filled('from'), 'after_or_equal:from')]]);
        $q = $this->invoices($r)->when($r->filled('currency'), fn ($q) => $q->where('currency', $r->input('currency')));
        if (isset($v['from'])) {
            $q->where('created_at', '>=', $v['from'].' 00:00:00');
        }
        if (isset($v['to'])) {
            $q->where('created_at', '<=', $v['to'].' 23:59:59.999999');
        }
        // One SQL statement observes invoice/payment/refund postings in a consistent
        // database snapshot and avoids a query per invoice on long financial reports.
        $payments = DB::table('finance_payments')->select('invoice_id')->selectRaw('SUM(amount_minor) AS paid')->groupBy('invoice_id');
        $refunds = DB::table('finance_refunds')->select('invoice_id')->selectRaw('SUM(amount_minor) AS refunded, SUM(commission_minor) AS commission_refunded, SUM(vendor_minor) AS vendor_refunded')->groupBy('invoice_id');
        $rows = DB::query()->fromSub($q->select('id', 'currency', 'minor_units', 'total_minor', 'commission_minor', 'vendor_minor'), 'i')
            ->leftJoinSub($payments, 'p', 'p.invoice_id', '=', 'i.id')->leftJoinSub($refunds, 'r', 'r.invoice_id', '=', 'i.id')
            ->select('i.currency', 'i.minor_units')->selectRaw('COUNT(*) AS invoice_count, SUM(i.total_minor) AS invoiced_minor, SUM(COALESCE(p.paid,0)) AS paid_minor, SUM(COALESCE(r.refunded,0)) AS refunded_minor, SUM(CASE WHEN i.total_minor > COALESCE(p.paid,0) THEN i.total_minor - COALESCE(p.paid,0) ELSE 0 END) AS outstanding_minor, SUM(i.commission_minor) AS commission_minor, SUM(i.vendor_minor) AS vendor_allocated_minor, SUM(COALESCE(r.commission_refunded,0)) AS commission_refunded_minor, SUM(COALESCE(r.vendor_refunded,0)) AS vendor_refunded_minor')
            ->groupBy('i.currency', 'i.minor_units')->orderBy('i.currency')->orderBy('i.minor_units')->get();
        $groups = $rows->map(function ($row) use ($r) {
            $result = ['currency' => $row->currency, 'minor_units' => (int) $row->minor_units, 'evidence' => 'SIMULATED'];
            $fields = ['invoice_count', 'invoiced_minor', 'paid_minor', 'refunded_minor', 'outstanding_minor'];
            if (in_array($r->user()->role, ['vendor', 'admin'], true)) {
                $fields = array_merge($fields, ['commission_minor', 'vendor_allocated_minor', 'commission_refunded_minor', 'vendor_refunded_minor']);
            }
            foreach ($fields as $field) {
                $amount = filter_var($row->$field, FILTER_VALIDATE_INT);
                abort_if($amount === false || $amount < 0, 409, 'Report exceeds supported exact integer range; narrow the period.');
                $result[$field] = $amount;
            }
            $result['net_collected_minor'] = $result['paid_minor'] - $result['refunded_minor'];
            if (isset($result['commission_minor'])) {
                $result['net_commission_allocated_minor'] = $result['commission_minor'] - $result['commission_refunded_minor'];
                $result['net_vendor_allocated_minor'] = $result['vendor_allocated_minor'] - $result['vendor_refunded_minor'];
            }

            return $result;
        });

        return response()->json(['groups' => $groups, 'real_money_movement' => false, 'note' => 'Invoice allocations are not payout eligibility. Settlement balances are separately reconciled.']);
    }

    public function requests(Request $r)
    {
        return DB::table('finance_refund_requests')->whereIn('invoice_id', $this->invoices($r)->select('id'))->orderByDesc('id')->paginate(25);
    }

    public function requestRefund(Request $r, Invoice $invoice)
    {
        abort_unless($invoice->user_id === $r->user()->id, 404);
        $v = $r->validate(['amount_minor' => 'required|integer|between:1,2000000000', 'reason' => 'required|string|max:500', 'idempotency_key' => 'required|string|max:100']);

        return DB::transaction(function () use ($r, $invoice, $v) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $old = DB::table('finance_refund_requests')->where('user_id', $r->user()->id)->where('request_key', $v['idempotency_key'])->first();
            if ($old) {
                abort_unless($old->invoice_id === $invoice->id && (int) $old->amount_minor === (int) $v['amount_minor'] && $old->reason === $v['reason'], 409, 'Refund request key conflict.');

                return response()->json($old);
            }
            $available = (int) Payment::where('invoice_id', $invoice->id)->sum('amount_minor') - (int) Refund::where('invoice_id', $invoice->id)->sum('amount_minor') - (int) DB::table('finance_refund_requests')->where('invoice_id', $invoice->id)->where('status', 'pending')->sum('amount_minor');
            abort_unless((int) $v['amount_minor'] <= $available, 409, 'Requested amount exceeds unreserved captured funds.');
            $id = DB::table('finance_refund_requests')->insertGetId(['invoice_id' => $invoice->id, 'user_id' => $r->user()->id, 'request_key' => $v['idempotency_key'], 'amount_minor' => (int) $v['amount_minor'], 'reason' => $v['reason'], 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

            return response()->json(DB::table('finance_refund_requests')->find($id), 201);
        }, 3);
    }

    public function review(Request $r, int $request, FinanceService $finance)
    {
        $v = $r->validate(['decision' => 'required|in:approve,reject', 'note' => 'required|string|max:500']);

        return DB::transaction(function () use ($r, $request, $v, $finance) {
            $row = DB::table('finance_refund_requests')->find($request);
            abort_unless($row, 404);
            // Same lock order as payments/settlements: invoice first, then workflow row.
            $invoice = Invoice::whereKey($row->invoice_id)->lockForUpdate()->firstOrFail();
            abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $invoice->vendor_id === $r->user()->id), 404);
            $row = DB::table('finance_refund_requests')->where('id', $request)->lockForUpdate()->first();
            $status = $v['decision'] === 'approve' ? 'approved' : 'rejected';
            if ($row->status !== 'pending') {
                abort_unless($row->status === $status && $row->decision_note === $v['note'], 409, 'Refund request already reviewed.');

                return response()->json($row);
            }
            abort_if($status === 'approved' && (config('payments.provider', 'mock') !== 'mock' || DB::table('gateway_checkouts')->where('invoice_id', $invoice->id)->where('provider', '!=', 'mock')->exists()), 503, 'Provider refund execution is required; ledger-only approval is disabled.');
            $refund = $status === 'approved' ? $finance->refund($invoice, $r->user()->id, (int) $row->amount_minor, 'request-'.$row->id, $row->reason) : null;
            DB::table('finance_refund_requests')->where('id', $request)->update(['status' => $status, 'reviewed_by' => $r->user()->id, 'decision_note' => $v['note'], 'refund_id' => $refund?->id, 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'finance.refund_request.'.$status, 'subject' => (string) $request, 'created_at' => now()]);

            return response()->json(DB::table('finance_refund_requests')->find($request));
        }, 3);
    }
}
