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
        abort_unless($r->user()->role === 'admin' || $station->vendor_id === $r->user()->id, 404);

        return TariffVersion::where('station_id', $station->id)->orderByDesc('id')->paginate(25);
    }

    public function createTariff(Request $r, Station $station)
    {
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $station->vendor_id === $r->user()->id), 404);
        abort_unless($station->source === 'simulator', 409, 'Only simulated tariffs are enabled.');
        $v = $r->validate(['rate_minor_per_kwh' => 'required|integer|between:0,1000000', 'tax_bps' => 'required|integer|between:0,10000', 'commission_bps' => 'required|integer|between:0,10000']);
        abort_if($r->user()->role !== 'admin' && (int) $v['commission_bps'] !== 0, 403, 'Only administrators configure commission.');
        $region = DB::table('regions')->where('country', $station->country)->where('currency', $station->currency)->first();
        abort_unless($region, 409, 'Configure the matching region/currency first.');

        return DB::transaction(function () use ($r, $station, $v, $region) {
            $t = TariffVersion::create($v + ['station_id' => $station->id, 'created_by' => $r->user()->id, 'currency' => $station->currency, 'minor_units' => $region->minor_units, 'effective_from' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'finance.simulated_tariff_published', 'subject' => (string) $t->id, 'created_at' => now()]);

            return response()->json($t, 201);
        });
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

    public function payment(Request $r, Invoice $invoice, FinanceService $finance)
    {
        abort_unless($invoice->user_id === $r->user()->id, 404);
        $v = $r->validate(['amount_minor' => 'required|integer|between:1,2000000000', 'idempotency_key' => 'required|string|max:100']);

        return response()->json($finance->pay($invoice, $r->user()->id, (int) $v['amount_minor'], $v['idempotency_key']), 201);
    }

    public function refund(Request $r, Invoice $invoice, FinanceService $finance)
    {
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $invoice->vendor_id === $r->user()->id), 404);
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
