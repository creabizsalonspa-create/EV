<?php

namespace App\Http\Controllers;

use App\Models\ChargingSession;
use App\Models\Finance\Invoice;
use App\Models\Finance\Journal;
use App\Models\Finance\JournalEntry;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Security\CurrentFinanceReader;
use App\Services\Finance\ChargingProvenance;
use App\Services\Finance\FinanceService;
use App\Services\Ocpi\CreditRefundEvidence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    /** Must be called inside the same transaction as the private read. */
    private function invoiceAccess(Request $r, Invoice $invoice): void
    {
        $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
        abort_unless($actor->id === $invoice->user_id
            || ($actor->role === 'vendor' && $actor->id === $invoice->vendor_id)
            || $actor->role === 'admin', 404);
    }

    public function tariffs(Request $r, Station $station)
    {
        return DB::transaction(function () use ($r, $station) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            // Route binding precedes this transaction. Re-read the station
            // under the same lock used by tariff publishers before disclosure.
            $station = Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->role === 'admin'
                || ($actor->role === 'vendor' && $station->vendor_id === $actor->id), 404);
            $region = DB::table('regions')->where('country', $station->country)
                ->where('currency', $station->currency)->lockForUpdate()->first();
            $asOf = now();
            $active = $station->source === 'simulator'
                ? app(FinanceService::class)->activeTariff($station, $asOf) : null;
            // History ordering is not effective-price ordering. Return the
            // independently selected version even when it is on another page.
            $matchesContext = $active !== null && $region !== null
                && $active->currency === $station->currency
                && (int) $active->minor_units === (int) $region->minor_units;

            return response()->json(TariffVersion::where('station_id', $station->id)
                ->orderByDesc('id')->paginate(25)->toArray() + [
                    'tariff_as_of' => $asOf->toISOString(),
                    'active_tariff' => $active,
                    'active_tariff_context_matches' => $matchesContext,
                    'pricing_context' => [
                        'currency' => $station->currency,
                        'minor_units' => $region ? (int) $region->minor_units : null,
                        'evidence' => $station->source === 'simulator'
                            ? 'SIMULATED' : 'DISCOVERY_ONLY',
                    ],
                ])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function stations(Request $r)
    {
        return DB::transaction(function () use ($r) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless(in_array($actor->role, ['vendor', 'admin'], true), 403);
            $regions = DB::table('regions')->get()
                ->keyBy(fn ($region) => $region->country.':'.$region->currency);

            return response()->json(Station::where('source', 'simulator')
                ->when($actor->role !== 'admin', fn ($q) => $q->where('vendor_id', $actor->id))
                ->orderByDesc('id')->paginate(25)->through(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name,
                    'country' => $s->country, 'currency' => $s->currency,
                    'minor_units' => isset($regions[$s->country.':'.$s->currency])
                        ? (int) $regions[$s->country.':'.$s->currency]->minor_units : null,
                    'evidence' => 'SIMULATED',
                ]))->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function createTariff(Request $r, Station $station)
    {
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $station->vendor_id === $r->user()->id), 404);
        $v = $r->validate(['rate_minor_per_kwh' => 'required|integer|between:0,1000000', 'tax_bps' => 'required|integer|between:0,10000', 'commission_bps' => 'required|integer|between:0,10000', 'idempotency_key' => 'sometimes|required|string|max:100', 'expected_active_tariff_id' => 'sometimes|nullable|integer|min:1', 'expected_minor_units' => 'sometimes|required|integer|between:0,3', 'expected_currency' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{3}$/']]);
        abort_if($r->user()->role !== 'admin' && (int) $v['commission_bps'] !== 0, 403, 'Only administrators configure commission.');
        $key = isset($v['idempotency_key']) ? hash('sha256', $r->user()->id.':'.$v['idempotency_key']) : null;
        $hasExpectedActive = array_key_exists('expected_active_tariff_id', $v);
        $expectedActive = isset($v['expected_active_tariff_id']) ? (int) $v['expected_active_tariff_id'] : null;
        $payload = [$station->id, (int) $v['rate_minor_per_kwh'], (int) $v['tax_bps'], (int) $v['commission_bps'], isset($v['expected_minor_units']) ? (int) $v['expected_minor_units'] : null, $v['expected_currency'] ?? null];
        // Preserve historical fingerprints for legacy retries without this field.
        if ($hasExpectedActive) {
            $payload[] = ['expected_active_tariff_id' => $expectedActive];
        }
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $expectedUnits = isset($v['expected_minor_units']) ? (int) $v['expected_minor_units'] : null;
        $expectedCurrency = $v['expected_currency'] ?? null;
        unset($v['idempotency_key'], $v['expected_minor_units'], $v['expected_currency'], $v['expected_active_tariff_id']);
        try {
            return DB::transaction(function () use ($r, $station, $v, $key, $fingerprint, $expectedUnits, $expectedCurrency, $hasExpectedActive, $expectedActive) {
                $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
                // All publishers serialize on the station; a vendor price
                // change cannot erase an Admin's commission policy.
                $station = Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
                abort_unless($actor->role === 'admin'
                    || ($actor->role === 'vendor' && $station->vendor_id === $actor->id), 404);
                abort_unless($station->source === 'simulator', 409, 'Only simulated tariffs are enabled.');
                if ($key && ($old = TariffVersion::where('publishing_key', $key)->first())) {
                    abort_unless(hash_equals($old->publishing_fingerprint, $fingerprint), 409, 'Tariff publishing key payload mismatch.');

                    return response()->json($old, 201);
                }
                // Do not let an unreviewed future full-rate version undo
                // a newly published price, tax or commission. Apply this
                // even if scheduling is subsequently disabled; existing
                // future immutable records do not vanish with a flag.
                abort_if(TariffVersion::where('station_id', $station->id)
                    ->where('effective_from', '>', now())->exists(), 409,
                    'A future tariff is scheduled; review or correct it before immediate publication.');
                $region = DB::table('regions')->where('country', $station->country)->where('currency', $station->currency)->lockForUpdate()->first();
                abort_unless($region, 409, 'Configure the matching region/currency first.');
                abort_if(($expectedUnits !== null && $expectedUnits !== (int) $region->minor_units) || ($expectedCurrency !== null && $expectedCurrency !== $station->currency), 409, 'Pricing currency or precision changed; refresh the station.');
                $clock = now();
                $active = app(FinanceService::class)->activeTariff($station, $clock);
                abort_if($hasExpectedActive && $active?->id !== $expectedActive, 409, 'Effective tariff changed; refresh before publishing.');
                $values = $v;
                if ($actor->role !== 'admin') {
                    $values['commission_bps'] = $active?->commission_bps ?? 0;
                }
                $t = TariffVersion::create($values + ['station_id' => $station->id, 'created_by' => $actor->id, 'currency' => $station->currency, 'minor_units' => $region->minor_units, 'effective_from' => $clock, 'publishing_key' => $key, 'publishing_fingerprint' => $key ? $fingerprint : null]);
                DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'finance.simulated_tariff_published', 'subject' => (string) $t->id, 'created_at' => now()]);

                return response()->json($t, 201);
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            abort(409, 'Tariff publishing key conflict; refresh history before publishing again.');
        }
    }

    public function invoices(Request $r)
    {
        return DB::transaction(function () use ($r) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $v = $r->validate([
                'q' => ['sometimes', 'string', 'max:80', 'regex:/^[A-Za-z0-9-]+$/'],
                'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
                'from' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'],
                'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:0001-01-01',
                    Rule::when($r->filled('from'), 'after_or_equal:from')],
            ]);
            $q = Invoice::query();
            if (isset($v['q'])) {
                $q->where('number', 'like', $v['q'].'%');
            }
            if (isset($v['currency'])) {
                $q->where('currency', $v['currency']);
            }
            if (isset($v['from'])) {
                $q->where('created_at', '>=', $v['from'].' 00:00:00');
            }
            if (isset($v['to'])) {
                $q->where('created_at', '<=', $v['to'].' 23:59:59.999999');
            }
            if ($actor->role === 'vendor') {
                $q->where('vendor_id', $actor->id);
            } elseif ($actor->role !== 'admin') {
                $q->where('user_id', $actor->id);
            }

            return response()->json($q->select('finance_invoices.*')
                ->selectSub(DB::table('finance_unpaid_credits')->select('amount_minor')
                    ->whereColumn('invoice_id', 'finance_invoices.id')
                    ->limit(1), 'unpaid_credited_minor')
                ->orderByDesc('id')->paginate(25))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function issue(Request $r, ChargingSession $session, FinanceService $finance)
    {
        return DB::transaction(function () use ($r, $session, $finance) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $reservation = Reservation::findOrFail($session->reservation_id);
            $station = Station::findOrFail($reservation->station_id);
            abort_unless($actor->id === $reservation->user_id
                || ($actor->role === 'vendor' && $actor->id === $station->vendor_id)
                || $actor->role === 'admin', 404);

            return response()->json(
                $finance->summary($finance->issue($session, $actor->id)), 201
            )->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function show(Request $r, Invoice $invoice, FinanceService $finance)
    {
        return DB::transaction(function () use ($r, $invoice, $finance) {
            $this->invoiceAccess($r, $invoice);
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $operator = $actor->role === 'admin'
                || ($actor->role === 'vendor' && $actor->id === $invoice->vendor_id);

            return response()->json($finance->summary($invoice) + [
                'can_review_unpaid_credit' => $operator
                    && DB::table('ocpi_test_invoice_receipts')
                        ->where('invoice_id', $invoice->id)->exists(),
                'can_issue_unpaid_credit' => $operator
                    && $finance->unpaidCreditAvailable($invoice),
                'payments' => Payment::where('invoice_id', $invoice->id)->get(),
                'refunds' => Refund::where('invoice_id', $invoice->id)->get(),
            ])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function sourceEvidence(Request $r, Invoice $invoice, ChargingProvenance $provenance)
    {
        return DB::transaction(function () use ($r, $invoice, $provenance) {
            $this->invoiceAccess($r, $invoice);

            return response()->json($provenance->inspect($invoice))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function sourceCredits(Request $r, Invoice $invoice, CreditRefundEvidence $evidence)
    {
        return DB::transaction(function () use ($r, $invoice, $evidence) {
            $this->invoiceAccess($r, $invoice);
            $v = $r->validate([
                'page' => 'sometimes|integer|min:1|max:100000',
                'full_reversal' => 'sometimes|boolean',
            ]);

            return response()->json($evidence->options(
                $invoice, (int) ($v['page'] ?? 1), (bool) ($v['full_reversal'] ?? false)
            ))->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function unpaidCredit(Request $r, Invoice $invoice, FinanceService $finance)
    {
        $actor = DB::transaction(function () use ($r, $invoice) {
            $current = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($current->role === 'admin'
                || ($current->role === 'vendor' && $current->id === $invoice->vendor_id), 404);

            return $current;
        }, 3);
        $v = $r->validate(['expected_actor_id' => 'required|integer|min:1', 'expected_invoice_number' => 'required|string|max:100', 'credit_record_id' => 'required|integer|min:1', 'idempotency_key' => 'required|uuid', 'reason' => 'required|string|min:10|max:500', 'consent' => 'required|boolean', 'expected_total_minor' => 'required|integer|min:1', 'expected_currency' => 'required|regex:/^[A-Z]{3}$/D', 'expected_minor_units' => 'required|integer|between:0,3']);
        foreach (['expected_actor_id', 'credit_record_id', 'expected_total_minor', 'expected_minor_units'] as $field) {
            $v[$field] = (int) $v[$field];
        }
        $v['consent'] = (bool) $v['consent'];

        return response()->json(['credit_note' => $finance->creditUnpaid($invoice, $actor->id, $v), 'invoice_id' => $invoice->id, 'cash_moved' => false, 'refund_created' => false])->header('Cache-Control', 'private, no-store');
    }

    public function resolveUnpaidCredit(Request $r, Invoice $invoice, FinanceService $finance)
    {
        $actor = DB::transaction(function () use ($r, $invoice) {
            $current = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($current->role === 'admin'
                || ($current->role === 'vendor' && $current->id === $invoice->vendor_id), 404);

            return $current;
        }, 3);
        $v = $r->validate(['expected_actor_id' => 'required|integer|min:1', 'expected_invoice_number' => 'required|string|max:100', 'idempotency_key' => 'required|uuid', 'cancel_unissued' => 'required|boolean']);
        $v['expected_actor_id'] = (int) $v['expected_actor_id'];
        $v['cancel_unissued'] = (bool) $v['cancel_unissued'];

        return response()->json($finance->resolveUnpaidCredit($invoice, $actor->id, $v))->header('Cache-Control', 'private, no-store');
    }

    private function mockOnly(Invoice $invoice): void
    {
        abort_unless(config('payments.provider', 'mock') === 'mock' && ! DB::table('gateway_checkouts')->where('invoice_id', $invoice->id)->where('provider', '!=', 'mock')->exists(), 503, 'Use provider refund/payment execution; ledger-only simulation is disabled.');
    }

    public function payment(Request $r, Invoice $invoice, FinanceService $finance)
    {
        $actor = DB::transaction(function () use ($r, $invoice) {
            $current = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($invoice->user_id === $current->id, 404);

            return $current;
        }, 3);
        $this->mockOnly($invoice);
        $v = $r->validate(['amount_minor' => 'required|integer|between:1,2000000000', 'idempotency_key' => 'required|string|max:100']);

        return response()->json($finance->pay($invoice, $actor->id, (int) $v['amount_minor'], $v['idempotency_key']), 201);
    }

    public function refund(Request $r, Invoice $invoice, FinanceService $finance)
    {
        $actor = DB::transaction(function () use ($r, $invoice) {
            $current = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($current->role === 'admin'
                || ($current->role === 'vendor' && $invoice->vendor_id === $current->id), 404);

            return $current;
        }, 3);
        $this->mockOnly($invoice);
        $v = $r->validate(['amount_minor' => 'required|integer|between:1,2000000000', 'idempotency_key' => 'required|string|max:100', 'reason' => 'required|string|max:500']);

        return response()->json($finance->refund($invoice, $actor->id, (int) $v['amount_minor'], $v['idempotency_key'], $v['reason']), 201);
    }

    public function journal(Request $r, Invoice $invoice)
    {
        return DB::transaction(function () use ($r, $invoice) {
            $this->invoiceAccess($r, $invoice);
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($actor->role === 'admin'
                || ($actor->role === 'vendor' && $actor->id === $invoice->vendor_id), 403);
            $journals = Journal::where('invoice_id', $invoice->id)->orderBy('id')->get();

            return response()->json([
                'journals' => $journals,
                'entries' => JournalEntry::whereIn('journal_id', $journals->pluck('id'))
                    ->orderBy('id')->get(),
            ])->header('Cache-Control', 'private, no-store');
        }, 3);
    }
}
