<?php

namespace App\Http\Controllers;

use App\Security\CurrentFinanceReader;
use App\Services\Commercial\CorporateBilling;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CorporateBillingController extends Controller
{
    private const MAX_EXACT_MINOR = 9_007_199_254_740_991;

    /** Source monetary fields must remain exact before immutable corporate CSV. */
    private static function exactMinor(mixed $value): int
    {
        abort_unless(is_int($value) || is_string($value), 409,
            'Corporate statement amount is not an exact minor-unit integer.');
        $minor = filter_var($value, FILTER_VALIDATE_INT);
        abort_unless($minor !== false && $minor >= 0
            && $minor <= self::MAX_EXACT_MINOR,
            409, 'Corporate statement amount exceeds exact integer bounds.');

        return $minor;
    }

    /**
     * Client responses contain business decisions, never private replay keys
     * or payload fingerprints used to prove original request identity.
     * The ledger still retains both values for all idempotency checks.
     */
    private function safe(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $data = (array) $row;
        unset($data['request_key'], $data['fingerprint']);
        // Historic statements retain the complete immutable agreement row
        // as a JSON string, including its original internal replay identity.
        // Sanitize the returned copy, not the stored financial evidence.
        if (array_key_exists('agreement_snapshot', $data)) {
            abort_unless(is_string($data['agreement_snapshot']), 409, 'Invalid corporate agreement snapshot.');
            $snapshot = json_decode($data['agreement_snapshot'], true);
            abort_unless(is_array($snapshot) && ! array_is_list($snapshot), 409, 'Invalid corporate agreement snapshot.');
            unset($snapshot['request_key'], $snapshot['fingerprint']);
            $data['agreement_snapshot'] = json_encode($snapshot, JSON_THROW_ON_ERROR);
        }

        return $data;
    }

    /** Keep any external invoice/cost-center identifiers literal in CSV tools. */
    private static function safeCsvCell(string $value): string
    {
        return preg_match('/^[\\p{Z}\\p{Cc}\\p{Cf}]*[=+\\-@]/u', $value) ? "'".$value : $value;
    }

    public function index(Request $r, CorporateBilling $service)
    {
        $fleet = $r->validate(['fleet_id' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $service, $fleet) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $company = $service->fleet((int) $fleet['fleet_id'], $actor->id);
            $manager = $actor->role === 'admin' || (int) $company->owner_id === $actor->id;

            return response()->json([
                'agreements' => DB::table('corporate_agreements')
                    ->where('fleet_id', $company->id)->orderByDesc('id')->get()->map(
                        fn ($a) => $this->safe($a) + [
                            'acceptance' => DB::table('corporate_agreement_acceptances')
                                ->where('agreement_id', $a->id)->first(),
                        ]),
                'can_manage' => $manager, 'real_payment_enabled' => false,
            ])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function publish(Request $r, int $fleet, CorporateBilling $s)
    {
        $v = $r->validate(['currency' => 'required|string|size:3|uppercase', 'minor_units' => 'required|integer|between:0,3', 'credit_limit_minor' => 'required|integer|min:1|max:1000000000000', 'payment_terms_days' => 'required|integer|between:0,365', 'billing_day' => 'required|integer|between:1,28', 'effective_from' => 'required|date', 'expires_at' => 'required|date|after:effective_from', 'legal_name' => 'required|string|max:200', 'terms' => 'required|string|max:2000', 'idempotency_key' => 'required|string|max:100']);

        return DB::transaction(function () use ($r, $fleet, $s, $v) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($actor->role === 'admin', 403);

            return response()->json(['agreement' => $this->safe($s->publish($fleet, $actor->id, $v))], 201)
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function accept(Request $r, int $agreement, CorporateBilling $s)
    {
        $r->validate(['consent' => 'required|accepted']);

        return DB::transaction(function () use ($r, $agreement, $s) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);

            return response()->json(['agreement' => $this->safe($s->accept($agreement, $actor->id))])
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function claim(Request $r, int $agreement, CorporateBilling $s)
    {
        $v = $r->validate(['invoice_id' => 'required|integer|min:1', 'cost_center_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|max:100']);

        return DB::transaction(function () use ($r, $agreement, $s, $v) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);

            return response()->json(['claim' => $this->safe($s->claim($agreement, $actor->id, $v))], 201)
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function claims(Request $r, int $agreement, CorporateBilling $s)
    {
        return DB::transaction(function () use ($r, $agreement, $s) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $a = $s->agreement($agreement);
            $f = $s->fleet((int) $a->fleet_id, $actor->id);
            $manager = $actor->role === 'admin' || (int) $f->owner_id === $actor->id;
            $page = $r->validate(['page' => 'sometimes|integer|between:1,100000']);

            return response()->json(DB::table('corporate_claims')
                ->where('agreement_id', $agreement)
                ->when(! $manager, fn ($q) => $q->where('driver_id', $actor->id))
                ->orderByDesc('id')->paginate(25, ['*'], 'page', (int) ($page['page'] ?? 1))->through(fn ($row) => $this->safe($row) + [
                    'decision' => DB::table('corporate_claim_decisions')
                        ->where('claim_id', $row->id)->first(),
                    'statement_id' => DB::table('corporate_statement_lines')
                        ->where('claim_id', $row->id)->value('statement_id'),
                ]))->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function decide(Request $r, int $claim, CorporateBilling $s)
    {
        $v = $r->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'required|string|max:500']);

        return DB::transaction(function () use ($r, $claim, $s, $v) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);

            return response()->json(['decision' => $s->decide(
                $claim, $actor->id, $v['decision'], $v['reason']
            )])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function issue(Request $r, int $agreement, CorporateBilling $s)
    {
        $v = $r->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/D'], 'claim_ids' => 'required|array|min:1|max:100', 'claim_ids.*' => 'required|integer|min:1', 'idempotency_key' => 'required|string|max:100']);

        return DB::transaction(function () use ($r, $agreement, $s, $v) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);

            return response()->json(['statement' => $this->safe($s->issue($agreement, $actor->id, $v))], 201)
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function statements(Request $r, int $agreement, CorporateBilling $s)
    {
        return DB::transaction(function () use ($r, $agreement, $s) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $a = $s->agreement($agreement);
            $s->fleet((int) $a->fleet_id, $actor->id, true);
            $page = $r->validate(['page' => 'sometimes|integer|between:1,100000']);

            return response()->json(DB::table('corporate_statements')
                ->where('agreement_id', $agreement)->orderByDesc('id')->paginate(25, ['*'], 'page', (int) ($page['page'] ?? 1))
                ->through(fn ($row) => $this->safe($row) + [
                    'receipt' => $this->safe(DB::table('corporate_receipts')
                        ->where('statement_id', $row->id)->first()),
                ]))->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function show(Request $r, int $statement, CorporateBilling $s)
    {
        return DB::transaction(function () use ($r, $statement, $s) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);

            $data = $s->statement($statement, $actor->id);
            $data['statement'] = $this->safe($data['statement']);
            $data['receipt'] = $this->safe($data['receipt']);

            return response()->json($data)
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function pay(Request $r, int $statement, CorporateBilling $s)
    {
        $v = $r->validate(['idempotency_key' => 'required|string|max:100']);

        return DB::transaction(function () use ($r, $statement, $s, $v) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);

            return response()->json([
                'receipt' => $this->safe($s->simulatePayment($statement, $actor->id, $v['idempotency_key'])),
                'real_payment_processed' => false,
            ])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function csv(Request $r, int $statement, CorporateBilling $s)
    {
        // Materialize a complete private ledger statement while the current
        // owner/Admin identity is still locked. CSV generation uses only the
        // authorized in-memory snapshot after releasing that read transaction.
        $v = DB::transaction(function () use ($r, $statement, $s) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);

            return $s->statement($statement, $actor->id);
        }, 3);
        $f = fopen('php://temp', 'w+');
        fputcsv($f, ['statement', 'invoice', 'cost_center', 'subtotal_minor', 'tax_minor', 'total_minor', 'currency', 'minor_units'], ',', '"', '');
        foreach ($v['lines'] as $line) {
            fputcsv($f, [self::safeCsvCell((string) $v['statement']->number),
                self::safeCsvCell((string) $line->invoice_number),
                self::safeCsvCell((string) $line->cost_center_code), self::exactMinor($line->subtotal_minor), self::exactMinor($line->tax_minor), self::exactMinor($line->total_minor), $v['statement']->currency, (int) $v['statement']->minor_units], ',', '"', '');
        }
        rewind($f);
        $data = stream_get_contents($f);
        fclose($f);

        return response($data)->header('Content-Type', 'text/csv; charset=utf-8')->header('Content-Disposition', 'attachment; filename="corporate-statement-'.$statement.'.csv"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
