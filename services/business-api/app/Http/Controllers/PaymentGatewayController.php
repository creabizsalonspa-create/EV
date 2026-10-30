<?php

namespace App\Http\Controllers;

use App\Models\Finance\Invoice;
use App\Services\Commercial\Payments\GatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentGatewayController extends Controller
{
    private function invoice(Request $r, int $id, bool $ownerOnly = false): Invoice
    {
        $invoice = Invoice::findOrFail($id);
        abort_unless($invoice->user_id === $r->user()->id || (! $ownerOnly && ($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $invoice->vendor_id === $r->user()->id))), 404);

        return $invoice;
    }

    private function publicRow(object $row): array
    {
        $v = (array) $row;
        unset($v['request_key'], $v['fingerprint']);

        return $v + ['evidence' => ($v['provider'] ?? config('payments.provider')) === 'stripe_test' ? 'STRIPE_TEST_CONTRACT' : 'MOCK_PROVIDER', 'real_money_moved' => false];
    }

    public function checkouts(Request $r)
    {
        $query = DB::table('gateway_checkouts as c')->join('finance_invoices as i', 'i.id', '=', 'c.invoice_id')->select('c.*');
        if ($r->user()->role !== 'admin') {
            $query->where($r->user()->role === 'vendor' ? 'i.vendor_id' : 'i.user_id', $r->user()->id);
        }

        return $query->orderByDesc('c.id')->paginate(25)->through(fn ($row) => $this->publicRow($row));
    }

    public function checkout(Request $r, GatewayService $gateway)
    {
        $v = $r->validate(['invoice_id' => 'required|integer|min:1', 'amount_minor' => 'required|integer|between:1,2000000000', 'idempotency_key' => 'required|string|max:100']);
        $invoice = $this->invoice($r, (int) $v['invoice_id'], true);

        return response()->json($this->publicRow($gateway->checkout($invoice, $r->user()->id, (int) $v['amount_minor'], $v['idempotency_key'])), 201);
    }

    public function operation(Request $r, int $checkout, GatewayService $gateway)
    {
        $row = DB::table('gateway_checkouts')->find($checkout);
        abort_unless($row, 404);
        $kind = $r->route('operation');
        $invoice = $this->invoice($r, (int) $row->invoice_id, $kind !== 'refund');
        if ($kind === 'refund') {
            abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $invoice->vendor_id === $r->user()->id), 404);
        }
        $v = $r->validate(['card_number' => 'prohibited', 'pan' => 'prohibited', 'cvv' => 'prohibited', 'cvc' => 'prohibited', 'idempotency_key' => 'required|string|max:100', 'token' => $kind === 'authorize' ? (config('payments.provider') === 'stripe_test' ? ['required', 'string', 'regex:/^pm_[A-Za-z0-9_]{1,100}$/D'] : 'required|in:mock_success,mock_decline') : 'prohibited', 'amount_minor' => $kind === 'refund' ? 'required|integer|between:1,2000000000' : 'prohibited', 'reason' => $kind === 'refund' ? 'required|string|max:500' : 'prohibited']);

        return response()->json($this->publicRow($gateway->operate($checkout, $r->user()->id, $kind, $v['idempotency_key'], isset($v['amount_minor']) ? (int) $v['amount_minor'] : null, $v['token'] ?? null, $v['reason'] ?? '')));
    }

    public function cases(Request $r)
    {
        $query = DB::table('gateway_cases as c')->join('finance_invoices as i', 'i.id', '=', 'c.invoice_id')->select('c.*');
        if ($r->user()->role !== 'admin') {
            $query->where($r->user()->role === 'vendor' ? 'i.vendor_id' : 'i.user_id', $r->user()->id);
        }

        return $query->orderByDesc('c.id')->paginate(25)->through(fn ($row) => $this->publicRow($row));
    }

    public function createCase(Request $r, GatewayService $gateway)
    {
        $v = $r->validate(['invoice_id' => 'required|integer|min:1', 'kind' => 'required|in:dispute', 'amount_minor' => 'required|integer|between:1,2000000000', 'reason' => 'required|string|max:500', 'idempotency_key' => 'required|string|max:100']);
        $invoice = $this->invoice($r, (int) $v['invoice_id'], true);

        return response()->json($this->publicRow($gateway->createCase($invoice, $r->user()->id, $v['kind'], (int) $v['amount_minor'], $v['reason'], $v['idempotency_key'])), 201);
    }

    public function resolveCase(Request $r, int $case, GatewayService $gateway)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['outcome' => 'required|in:approved,rejected', 'resolution' => 'required|string|max:500']);

        return response()->json($this->publicRow($gateway->resolveCase($case, $r->user()->id, $v['outcome'], $v['resolution'])));
    }

    public function reconciliation(Request $r, int $invoice, GatewayService $gateway)
    {
        $record = $this->invoice($r, $invoice);
        abort_unless(in_array($r->user()->role, ['admin', 'vendor'], true), 403);

        return response()->json($gateway->reconcile($record));
    }

    public function stripeWebhook(Request $r, GatewayService $gateway)
    {
        return response()->json($gateway->stripeWebhook($r->getContent(), (string) $r->header('Stripe-Signature')));
    }

    public function webhook(Request $r, GatewayService $gateway)
    {
        return response()->json($gateway->webhook($r->getContent(), (string) $r->header('X-Payment-Timestamp'), (string) $r->header('X-Payment-Signature')));
    }
}
