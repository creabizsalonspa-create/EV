<?php

namespace App\Http\Controllers;

use App\Services\Commercial\Payments\CorporateHostedCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CorporateHostedCheckoutController extends Controller
{
    private function response(array $dto, int $status = 200)
    {
        return response()->json(['checkout' => $dto], $status)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $r, CorporateHostedCollection $s)
    {
        $v = $r->validate(['statement_id' => 'nullable|integer|min:1']);
        $q = DB::table('corporate_hosted_checkouts')->when(isset($v['statement_id']), fn ($q) => $q->where('statement_id', $v['statement_id']));
        if ($r->user()->role !== 'admin') {
            $q->where('payer_id', $r->user()->id)->whereIn('statement_id', DB::table('corporate_statements as s')->join('fleets as f', 'f.id', '=', 's.fleet_id')->where('f.owner_id', $r->user()->id)->select('s.id'));
        }

        return response()->json($q->orderByDesc('id')->paginate(25)->through(fn ($h) => $s->dto($h, $r->user())))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, CorporateHostedCollection $s)
    {
        $v = $r->validate(['statement_id' => 'required|integer|min:1', 'consent' => 'required|accepted', 'idempotency_key' => 'required|string|max:100', 'amount_minor' => 'prohibited', 'currency' => 'prohibited', 'provider' => 'prohibited', 'return_url' => 'prohibited', 'token' => 'prohibited', 'card_number' => 'prohibited', 'pan' => 'prohibited', 'cvv' => 'prohibited', 'cvc' => 'prohibited']);

        return $this->response($s->create($r->user(), (int) $v['statement_id'], $v['idempotency_key']), 201);
    }

    public function show(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        return $this->response($s->dto($s->access($r->user(), $hosted), $r->user()));
    }

    public function reference(Request $r, string $reference, CorporateHostedCollection $s)
    {
        $h = DB::table('corporate_hosted_checkouts')->where('reference', $reference)->where('payer_id', $r->user()->id)->first() ?? abort(404);

        return $this->response($s->dto($s->access($r->user(), (int) $h->id, true), $r->user()));
    }

    public function events(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        $s->access($r->user(), $hosted);

        return response()->json(DB::table('corporate_hosted_events')->where('checkout_id', $hosted)->orderByDesc('id')->paginate(25))->header('Cache-Control', 'private, no-store');
    }

    public function inspect(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        return $this->response($s->inspect($r->user(), $hosted));
    }

    public function retryPreparation(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        return $this->response($s->retryPreparation($r->user(), $hosted));
    }

    public function capture(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        return $this->response($s->capture($r->user(), $hosted));
    }

    public function cancel(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        return $this->response($s->cancel($r->user(), $hosted));
    }

    public function simulate(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        $v = $r->validate(['consent' => 'required|accepted', 'outcome' => 'required|in:authorized,declined,abandoned']);

        return $this->response($s->simulate($r->user(), $hosted, $v['outcome']));
    }

    public function webhook(Request $r, CorporateHostedCollection $s)
    {
        return response()->json($s->webhook($r->getContent(), $r->header('Stripe-Signature', '')));
    }
}
