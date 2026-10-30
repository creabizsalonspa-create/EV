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
use App\Services\Finance\FinanceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    private function invoiceAccess(Request $r, Invoice $invoice): void
    {
        abort_unless($r->user()->id === $invoice->user_id || ($r->user()->role === 'vendor' && $r->user()->id === $invoice->vendor_id) || $r->user()->role === 'admin', 404);
    }

    public function tariffs(Request $r, Station $station)
    {
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $station->vendor_id === $r->user()->id), 404);

        $region = DB::table('regions')->where('country', $station->country)->where('currency', $station->currency)->first();

        return response()->json(TariffVersion::where('station_id', $station->id)->orderByDesc('id')->paginate(25)->toArray() + [
            'pricing_context' => ['currency' => $station->currency, 'minor_units' => $region ? (int) $region->minor_units : null, 'evidence' => $station->source === 'simulator' ? 'SIMULATED' : 'DISCOVERY_ONLY'],
        ]);
    }

    public function stations(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['vendor', 'admin'], true), 403);
        $regions = DB::table('regions')->get()->keyBy(fn ($r) => $r->country.':'.$r->currency);

        return Station::where('source', 'simulator')->when($r->user()->role !== 'admin', fn ($q) => $q->where('vendor_id', $r->user()->id))
            ->orderByDesc('id')->paginate(25)->through(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'country' => $s->country, 'currency' => $s->currency,
                'minor_units' => isset($regions[$s->country.':'.$s->currency]) ? (int) $regions[$s->country.':'.$s->currency]->minor_units : null,
                'evidence' => 'SIMULATED',
            ]);
    }

    public function createTariff(Request $r, Station $station)
    {
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $station->vendor_id === $r->user()->id), 404);
        $v = $r->validate(['rate_minor_per_kwh' => 'required|integer|between:0,1000000', 'tax_bps' => 'required|integer|between:0,10000', 'commission_bps' => 'required|integer|between:0,10000', 'idempotency_key' => 'sometimes|required|string|max:100', 'expected_minor_units' => 'sometimes|required|integer|between:0,3', 'expected_currency' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{3}$/']]);
        abort_if($r->user()->role !== 'admin' && (int) $v['commission_bps'] !== 0, 403, 'Only administrators configure commission.');
        $key = isset($v['idempotency_key']) ? hash('sha256', $r->user()->id.':'.$v['idempotency_key']) : null;
        $fingerprint = hash('sha256', json_encode([$station->id, (int) $v['rate_minor_per_kwh'], (int) $v['tax_bps'], (int) $v['commission_bps'], isset($v['expected_minor_units']) ? (int) $v['expected_minor_units'] : null, $v['expected_currency'] ?? null], JSON_THROW_ON_ERROR));
        $expectedUnits = isset($v['expected_minor_units']) ? (int) $v['expected_minor_units'] : null;
        $expectedCurrency = $v['expected_currency'] ?? null;
        unset($v['idempotency_key'], $v['expected_minor_units'], $v['expected_currency']);
        try {
            return DB::transaction(function () use ($r, $station, $v, $key, $fingerprint, $expectedUnits, $expectedCurrency) {
                // All publishers serialize on the station; a vendor price change cannot erase a commission policy.
                $station = Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
                abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $station->vendor_id === $r->user()->id), 404);
                abort_unless($station->source === 'simulator', 409, 'Only simulated tariffs are enabled.');
                if ($key && ($old = TariffVersion::where('publishing_key', $key)->first())) {
                    abort_unless(hash_equals($old->publishing_fingerprint, $fingerprint), 409, 'Tariff publishing key payload mismatch.');

                    return response()->json($old, 201);
                }
                $region = DB::table('regions')->where('country', $station->country)->where('currency', $station->currency)->lockForUpdate()->first();
                abort_unless($region, 409, 'Configure the matching region/currency first.');
                abort_if(($expectedUnits !== null && $expectedUnits !== (int) $region->minor_units) || ($expectedCurrency !== null && $expectedCurrency !== $station->currency), 409, 'Pricing currency or precision changed; refresh the station.');
                $values = $v;
                if ($r->user()->role !== 'admin') {
                    $values['commission_bps'] = TariffVersion::where('station_id', $station->id)->where('effective_from', '<=', now())->orderByDesc('effective_from')->orderByDesc('id')->value('commission_bps') ?? 0;
                }
                $t = TariffVersion::create($values + ['station_id' => $station->id, 'created_by' => $r->user()->id, 'currency' => $station->currency, 'minor_units' => $region->minor_units, 'effective_from' => now(), 'publishing_key' => $key, 'publishing_fingerprint' => $key ? $fingerprint : null]);
                DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'finance.simulated_tariff_published', 'subject' => (string) $t->id, 'created_at' => now()]);

                return response()->json($t, 201);
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            abort(409, 'Tariff publishing key conflict; refresh history before publishing again.');
        }
    }

    public function invoices(Request $r)
    {
        $v = $r->validate([
            'q' => ['sometimes', 'string', 'max:80', 'regex:/^[A-Za-z0-9-]+$/'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'from' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:0001-01-01', Rule::when($r->filled('from'), 'after_or_equal:from')],
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
        if ($r->user()->role === 'vendor') {
            $q->where('vendor_id', $r->user()->id);
        } elseif ($r->user()->role !== 'admin') {
            $q->where('user_id', $r->user()->id);
        }

        return $q->orderByDesc('id')->paginate(25);
    }

    public function issue(Request $r, ChargingSession $session, FinanceService $finance)
    {
        $b = Reservation::findOrFail($session->reservation_id);
        $s = Station::findOrFail($b->station_id);
        abort_unless($r->user()->id === $b->user_id || ($r->user()->role === 'vendor' && $r->user()->id === $s->vendor_id) || $r->user()->role === 'admin', 404);

        return response()->json($finance->summary($finance->issue($session, $r->user()->id)), 201);
    }

    public function show(Request $r, Invoice $invoice, FinanceService $finance)
    {
        $this->invoiceAccess($r, $invoice);

        return response()->json($finance->summary($invoice) + ['payments' => Payment::where('invoice_id', $invoice->id)->get(), 'refunds' => Refund::where('invoice_id', $invoice->id)->get()]);
    }

    private function mockOnly(Invoice $invoice): void
    {
        abort_unless(config('payments.provider', 'mock') === 'mock' && ! DB::table('gateway_checkouts')->where('invoice_id', $invoice->id)->where('provider', '!=', 'mock')->exists(), 503, 'Use provider refund/payment execution; ledger-only simulation is disabled.');
    }

    public function payment(Request $r, Invoice $invoice, FinanceService $finance)
    {
        abort_unless($invoice->user_id === $r->user()->id, 404);
        $this->mockOnly($invoice);
        $v = $r->validate(['amount_minor' => 'required|integer|between:1,2000000000', 'idempotency_key' => 'required|string|max:100']);

        return response()->json($finance->pay($invoice, $r->user()->id, (int) $v['amount_minor'], $v['idempotency_key']), 201);
    }

    public function refund(Request $r, Invoice $invoice, FinanceService $finance)
    {
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $invoice->vendor_id === $r->user()->id), 404);
        $this->mockOnly($invoice);
        $v = $r->validate(['amount_minor' => 'required|integer|between:1,2000000000', 'idempotency_key' => 'required|string|max:100', 'reason' => 'required|string|max:500']);

        return response()->json($finance->refund($invoice, $r->user()->id, (int) $v['amount_minor'], $v['idempotency_key'], $v['reason']), 201);
    }

    public function journal(Request $r, Invoice $invoice)
    {
        $this->invoiceAccess($r, $invoice);
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $r->user()->id === $invoice->vendor_id), 403);
        $journals = Journal::where('invoice_id', $invoice->id)->orderBy('id')->get();

        return response()->json(['journals' => $journals, 'entries' => JournalEntry::whereIn('journal_id', $journals->pluck('id'))->orderBy('id')->get()]);
    }
}
