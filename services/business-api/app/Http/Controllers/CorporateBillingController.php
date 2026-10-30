<?php

namespace App\Http\Controllers;

use App\Services\Commercial\CorporateBilling;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CorporateBillingController extends Controller
{
    public function index(Request $r, CorporateBilling $service)
    {
        $fleet = $r->validate(['fleet_id' => 'required|integer|min:1']);
        $service->fleet((int) $fleet['fleet_id'], $r->user()->id);
        $manager = $r->user()->role === 'admin' || (int) DB::table('fleets')->find($fleet['fleet_id'])->owner_id === $r->user()->id;

        return response()->json(['agreements' => DB::table('corporate_agreements')->where('fleet_id', $fleet['fleet_id'])->orderByDesc('id')->get()->map(fn ($a) => (array) $a + ['acceptance' => DB::table('corporate_agreement_acceptances')->where('agreement_id', $a->id)->first()]), 'can_manage' => $manager, 'real_payment_enabled' => false]);
    }

    public function publish(Request $r, int $fleet, CorporateBilling $s)
    {
        $v = $r->validate(['currency' => 'required|string|size:3|uppercase', 'minor_units' => 'required|integer|between:0,3', 'credit_limit_minor' => 'required|integer|min:1|max:1000000000000', 'payment_terms_days' => 'required|integer|between:0,365', 'billing_day' => 'required|integer|between:1,28', 'effective_from' => 'required|date', 'expires_at' => 'required|date|after:effective_from', 'legal_name' => 'required|string|max:200', 'terms' => 'required|string|max:2000', 'idempotency_key' => 'required|string|max:100']);

        return response()->json(['agreement' => $s->publish($fleet, $r->user()->id, $v)], 201);
    }

    public function accept(Request $r, int $agreement, CorporateBilling $s)
    {
        $r->validate(['consent' => 'required|accepted']);

        return response()->json(['agreement' => $s->accept($agreement, $r->user()->id)]);
    }

    public function claim(Request $r, int $agreement, CorporateBilling $s)
    {
        $v = $r->validate(['invoice_id' => 'required|integer|min:1', 'cost_center_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|max:100']);

        return response()->json(['claim' => $s->claim($agreement, $r->user()->id, $v)], 201);
    }

    public function claims(Request $r, int $agreement, CorporateBilling $s)
    {
        $a = $s->agreement($agreement);
        $f = $s->fleet((int) $a->fleet_id, $r->user()->id);
        $manager = $r->user()->role === 'admin' || (int) $f->owner_id === $r->user()->id;

        return DB::table('corporate_claims')->where('agreement_id', $agreement)->when(! $manager, fn ($q) => $q->where('driver_id', $r->user()->id))->orderByDesc('id')->paginate(25)->through(fn ($row) => (array) $row + ['decision' => DB::table('corporate_claim_decisions')->where('claim_id', $row->id)->first(), 'statement_id' => DB::table('corporate_statement_lines')->where('claim_id', $row->id)->value('statement_id')]);
    }

    public function decide(Request $r, int $claim, CorporateBilling $s)
    {
        $v = $r->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'required|string|max:500']);

        return response()->json(['decision' => $s->decide($claim, $r->user()->id, $v['decision'], $v['reason'])]);
    }

    public function issue(Request $r, int $agreement, CorporateBilling $s)
    {
        $v = $r->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/D'], 'claim_ids' => 'required|array|min:1|max:100', 'claim_ids.*' => 'required|integer|min:1', 'idempotency_key' => 'required|string|max:100']);

        return response()->json(['statement' => $s->issue($agreement, $r->user()->id, $v)], 201);
    }

    public function statements(Request $r, int $agreement, CorporateBilling $s)
    {
        $a = $s->agreement($agreement);
        $s->fleet((int) $a->fleet_id, $r->user()->id, true);

        return DB::table('corporate_statements')->where('agreement_id', $agreement)->orderByDesc('id')->paginate(25)->through(fn ($row) => (array) $row + ['receipt' => DB::table('corporate_receipts')->where('statement_id', $row->id)->first()]);
    }

    public function show(Request $r, int $statement, CorporateBilling $s)
    {
        return response()->json($s->statement($statement, $r->user()->id));
    }

    public function pay(Request $r, int $statement, CorporateBilling $s)
    {
        $v = $r->validate(['idempotency_key' => 'required|string|max:100']);

        return response()->json(['receipt' => $s->simulatePayment($statement, $r->user()->id, $v['idempotency_key']), 'real_payment_processed' => false]);
    }

    public function csv(Request $r, int $statement, CorporateBilling $s)
    {
        $v = $s->statement($statement, $r->user()->id);
        $f = fopen('php://temp', 'w+');
        fputcsv($f, ['statement', 'invoice', 'cost_center', 'subtotal_minor', 'tax_minor', 'total_minor', 'currency', 'minor_units'], ',', '"', '');
        foreach ($v['lines'] as $line) {
            $safe = static fn (string $text) => preg_match('/^[\s\p{C}]*[=+\-@]/u', $text) ? "'".$text : $text;
            fputcsv($f, [$v['statement']->number, $line->invoice_number, $safe($line->cost_center_code), (int) $line->subtotal_minor, (int) $line->tax_minor, (int) $line->total_minor, $v['statement']->currency, (int) $v['statement']->minor_units], ',', '"', '');
        }
        rewind($f);
        $data = stream_get_contents($f);
        fclose($f);

        return response($data)->header('Content-Type', 'text/csv; charset=utf-8')->header('Content-Disposition', 'attachment; filename="corporate-statement-'.$statement.'.csv"')->header('Cache-Control', 'private, no-store');
    }
}
