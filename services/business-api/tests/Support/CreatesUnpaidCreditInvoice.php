<?php

namespace Tests\Support;

use App\Models\Finance\Invoice;
use App\Models\OcpiRecord;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesUnpaidCreditInvoice
{
    use CreatesBillableSandboxRoaming;

    private function scenario(string $currency = 'EUR', int $units = 2, string $rate = '0.25', int $rateMinor = 25, int $taxBps = 0): array
    {
        [$owner,$driver,$other,$provider,,$order] = $this->billable($currency, $units, $rate, $rateMinor, null, $taxBps);
        $preview = $this->actingAs($driver)->getJson('/api/roaming/sandbox-orders/'.$order.'/test-invoice-preview')->assertOk()->json();
        $invoice = $this->postJson('/api/roaming/sandbox-orders/'.$order.'/test-invoice', ['request_key' => (string) Str::uuid(), 'preview_token' => $preview['preview_token'], 'terms_version' => $preview['terms_version'], 'consent' => true, 'expected_total_minor' => $preview['total_minor'], 'expected_currency' => $preview['currency'], 'expected_minor_units' => $preview['minor_units'], 'expected_energy_wh' => $preview['energy_wh']])->assertOk()->json('invoice');
        $receipt = DB::table('ocpi_test_invoice_receipts')->where('invoice_id', $invoice['id'])->first();
        $original = OcpiRecord::findOrFail($receipt->source_cdr_record_id);
        app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [array_replace($original->payload, ['id' => 'unpaid-source-credit', 'credit' => true, 'credit_reference_id' => $original->external_id])]);
        $credit = OcpiRecord::where('external_id', 'unpaid-source-credit')->sole();
        $intent = ['idempotency_key' => (string) Str::uuid(), 'credit_record_id' => $credit->id, 'expected_actor_id' => $owner->id, 'expected_invoice_number' => $invoice['number'], 'expected_total_minor' => $invoice['total_minor'], 'expected_currency' => $invoice['currency'], 'expected_minor_units' => $invoice['minor_units'], 'reason' => 'Full source reversal before any collection', 'consent' => true];

        return [$owner, $driver, $other, Invoice::findOrFail($invoice['id']), $credit, $intent, $original, $provider];
    }
}
