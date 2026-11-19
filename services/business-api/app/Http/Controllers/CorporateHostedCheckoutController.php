<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Security\CurrentFinanceReader;
use App\Services\Commercial\Payments\CorporateHostedCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CorporateHostedCheckoutController extends Controller
{
    /** Short preflight; never hold a controller transaction over provider I/O. */
    private function current(Request $r): User
    {
        return DB::transaction(
            fn () => app(CurrentFinanceReader::class)->requireCurrent($r), 3
        );
    }

    private function response(array $dto, int $status = 200)
    {
        return response()->json(['checkout' => $dto], $status)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $r, CorporateHostedCollection $s)
    {
        $v = $r->validate(['statement_id' => 'nullable|integer|min:1']);

        return DB::transaction(function () use ($r, $s, $v) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $q = DB::table('corporate_hosted_checkouts')
                ->when(isset($v['statement_id']),
                    fn ($q) => $q->where('statement_id', $v['statement_id']));
            if ($actor->role !== 'admin') {
                $q->where('payer_id', $actor->id)->whereIn('statement_id',
                    DB::table('corporate_statements as s')
                        ->join('fleets as f', 'f.id', '=', 's.fleet_id')
                        ->where('f.owner_id', $actor->id)->select('s.id'));
            }

            return response()->json($q->orderByDesc('id')->paginate(25)
                ->through(fn ($h) => $s->dto($h, $actor)))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function create(Request $r, CorporateHostedCollection $s)
    {
        $v = $r->validate(['statement_id' => 'required|integer|min:1', 'consent' => 'required|accepted', 'idempotency_key' => 'required|string|max:100', 'amount_minor' => 'prohibited', 'currency' => 'prohibited', 'provider' => 'prohibited', 'return_url' => 'prohibited', 'token' => 'prohibited', 'card_number' => 'prohibited', 'pan' => 'prohibited', 'cvv' => 'prohibited', 'cvc' => 'prohibited']);

        return $this->response($s->create(
            $this->current($r), (int) $v['statement_id'], $v['idempotency_key']
        ), 201);
    }

    public function show(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        return DB::transaction(function () use ($r, $hosted, $s) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);

            return $this->response($s->dto($s->access($actor, $hosted), $actor));
        }, 3);
    }

    public function reference(Request $r, string $reference, CorporateHostedCollection $s)
    {
        return DB::transaction(function () use ($r, $reference, $s) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $h = DB::table('corporate_hosted_checkouts')
                ->where('reference', $reference)->where('payer_id', $actor->id)
                ->first() ?? abort(404);

            return $this->response($s->dto($s->access($actor, (int) $h->id, true), $actor));
        }, 3);
    }

    public function events(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        return DB::transaction(function () use ($r, $hosted, $s) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $s->access($actor, $hosted);

            return response()->json(DB::table('corporate_hosted_events')
                ->where('checkout_id', $hosted)->orderByDesc('id')->paginate(25))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function inspect(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        return $this->response($s->inspect($this->current($r), $hosted));
    }

    public function retryPreparation(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        return $this->response($s->retryPreparation($this->current($r), $hosted));
    }

    public function capture(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        return $this->response($s->capture($this->current($r), $hosted));
    }

    public function cancel(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        return $this->response($s->cancel($this->current($r), $hosted));
    }

    public function simulate(Request $r, int $hosted, CorporateHostedCollection $s)
    {
        $v = $r->validate(['consent' => 'required|accepted', 'outcome' => 'required|in:authorized,declined,abandoned']);

        return $this->response($s->simulate($this->current($r), $hosted, $v['outcome']));
    }

    public function webhook(Request $r, CorporateHostedCollection $s)
    {
        return response()->json($s->webhook($r->getContent(), $r->header('Stripe-Signature', '')));
    }
}
