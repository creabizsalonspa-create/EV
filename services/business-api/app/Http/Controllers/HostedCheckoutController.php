<?php

namespace App\Http\Controllers;

use App\Services\Commercial\Payments\HostedCheckout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HostedCheckoutController extends Controller
{
    private function response(array $dto, int $status = 200)
    {
        return response()->json(['checkout' => $dto], $status)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $r, HostedCheckout $s)
    {
        $v = $r->validate(['scope' => ['nullable', Rule::in(['charging', 'subscription'])], 'invoice_id' => 'nullable|integer|min:1']);
        $q = DB::table('hosted_payment_checkouts as h')->when(isset($v['scope']), fn ($q) => $q->where('h.scope', $v['scope']))->when(isset($v['invoice_id']), fn ($q) => $q->where('h.invoice_id', $v['invoice_id']));
        if ($r->user()->role !== 'admin') {
            $q->where(function ($q) use ($r) {
                $q->where('h.user_id', $r->user()->id);
                if ($r->user()->role === 'vendor') {
                    $q->orWhere(function ($q) use ($r) {
                        $q->where('h.scope', 'charging')->whereIn('h.invoice_id', DB::table('finance_invoices')->where('vendor_id', $r->user()->id)->select('id'));
                    });
                }
            });
        }

        return response()->json($q->orderByDesc('h.id')->paginate(25)->through(fn ($h) => $s->dto($h, $r->user())))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, HostedCheckout $s)
    {
        $v = $r->validate(['scope' => ['required', Rule::in(['charging', 'subscription'])], 'invoice_id' => 'required|integer|min:1', 'subscription_id' => 'required_if:scope,subscription|prohibited_if:scope,charging|integer|min:1', 'consent' => 'required|accepted', 'idempotency_key' => 'required|string|max:100', 'amount_minor' => 'prohibited', 'currency' => 'prohibited', 'provider' => 'prohibited', 'return_url' => 'prohibited', 'token' => 'prohibited', 'card_number' => 'prohibited', 'pan' => 'prohibited', 'cvv' => 'prohibited', 'cvc' => 'prohibited']);

        return $this->response($s->create($r->user(), $v), 201);
    }

    public function show(Request $r, int $hosted, HostedCheckout $s)
    {
        return $this->response($s->dto($s->access($r->user(), $hosted), $r->user()));
    }

    public function events(Request $r, int $hosted, HostedCheckout $s)
    {
        $s->access($r->user(), $hosted);

        return response()->json(DB::table('hosted_payment_events')->where('hosted_checkout_id', $hosted)->orderByDesc('id')->paginate(25))->header('Cache-Control', 'private, no-store');
    }

    public function reference(Request $r, string $reference, HostedCheckout $s)
    {
        $h = DB::table('hosted_payment_checkouts')->where('reference', $reference)->where('user_id', $r->user()->id)->first();
        abort_unless($h, 404);

        return $this->response($s->dto($h, $r->user()));
    }

    public function retryPreparation(Request $r, int $hosted, HostedCheckout $s)
    {
        return $this->response($s->retryPreparation($r->user(), $hosted));
    }

    public function inspect(Request $r, int $hosted, HostedCheckout $s)
    {
        return $this->response($s->inspect($r->user(), $hosted));
    }

    public function capture(Request $r, int $hosted, HostedCheckout $s)
    {
        return $this->response($s->capture($r->user(), $hosted));
    }

    public function cancel(Request $r, int $hosted, HostedCheckout $s)
    {
        return $this->response($s->cancel($r->user(), $hosted));
    }

    public function simulate(Request $r, int $hosted, HostedCheckout $s)
    {
        $v = $r->validate(['outcome' => 'required|in:authorized,declined,abandoned', 'consent' => 'required|accepted']);

        return $this->response($s->simulate($r->user(), $hosted, $v['outcome']));
    }
}
