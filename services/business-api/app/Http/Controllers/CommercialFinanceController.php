<?php

namespace App\Http\Controllers;

use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommercialFinanceController extends Controller
{
    private function invoices(Request $r)
    {
        return Invoice::query()->when($r->user()->role !== 'admin', fn ($q) => $q->where($r->user()->role === 'vendor' ? 'vendor_id' : 'user_id', $r->user()->id));
    }

    public function report(Request $r)
    {
        $r->validate(['currency' => 'sometimes|regex:/^[A-Z]{3}$/']);
        $q = $this->invoices($r)->when($r->filled('currency'), fn ($q) => $q->where('currency', $r->input('currency')));
        // Report immutable posted records; each currency and precision remains separate.
        $groups = DB::transaction(function () use ($q, $r) {
            $groups = [];
            foreach ((clone $q)->orderBy('id')->cursor() as $invoice) {
                $key = $invoice->currency.':'.$invoice->minor_units;
                $groups[$key] ??= ['currency' => $invoice->currency, 'minor_units' => $invoice->minor_units, 'invoice_count' => 0, 'invoiced_minor' => 0, 'paid_minor' => 0, 'refunded_minor' => 0, 'outstanding_minor' => 0, 'net_collected_minor' => 0, 'evidence' => 'SIMULATED'];
                $g = &$groups[$key];
                $g['invoice_count']++;
                $g['invoiced_minor'] += $invoice->total_minor;
                $paid = (int) Payment::where('invoice_id', $invoice->id)->sum('amount_minor');
                $refunded = (int) Refund::where('invoice_id', $invoice->id)->sum('amount_minor');
                $g['paid_minor'] += $paid;
                $g['refunded_minor'] += $refunded;
                $g['outstanding_minor'] += max(0, $invoice->total_minor - $paid);
                $g['net_collected_minor'] += $paid - $refunded;
                if (in_array($r->user()->role, ['vendor', 'admin'], true)) {
                    $g['commission_minor'] = ($g['commission_minor'] ?? 0) + $invoice->commission_minor;
                    $g['vendor_allocated_minor'] = ($g['vendor_allocated_minor'] ?? 0) + $invoice->vendor_minor;
                }
                unset($g);
            }

            return array_values($groups);
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
            $refund = $status === 'approved' ? $finance->refund($invoice, $r->user()->id, (int) $row->amount_minor, 'request-'.$row->id, $row->reason) : null;
            DB::table('finance_refund_requests')->where('id', $request)->update(['status' => $status, 'reviewed_by' => $r->user()->id, 'decision_note' => $v['note'], 'refund_id' => $refund?->id, 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'finance.refund_request.'.$status, 'subject' => (string) $request, 'created_at' => now()]);

            return response()->json(DB::table('finance_refund_requests')->find($request));
        }, 3);
    }
}
