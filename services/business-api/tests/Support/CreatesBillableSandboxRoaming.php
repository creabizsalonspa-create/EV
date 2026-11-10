<?php

namespace Tests\Support;

use App\Models\OcpiRecord;
use App\Models\VendorOps\VendorCompany;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Support\Facades\DB;

trait CreatesBillableSandboxRoaming
{
    use CreatesSandboxRoaming;

    private function billable(string $currency = 'EUR', int $units = 2, string $rate = '0.25', int $rateMinor = 25, ?string $sourceCost = null): array
    {
        [$owner,$driver,$other,$p,$location] = $this->fixture();
        DB::table('regions')->insert(['country' => 'NL', 'currency' => $currency, 'minor_units' => $units, 'timezone' => 'Europe/Amsterdam', 'commerce_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        $tariff = OcpiRecord::where('ocpi_provider_id', $p->id)->where('module', 'tariffs')->sole();
        $raw = $tariff->payload;
        $raw['currency'] = $currency;
        $raw['elements'][0]['price_components'][0]['price'] = $rate;
        $raw['last_updated'] = now()->toISOString();
        app(RecordSynchronizer::class)->ingest($p, 'tariffs', [$raw]);
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $token = $this->writes[0]['body'];
        $session = $this->sourceSessionFixture($p, $order, $token, 'COMPLETED');
        $session['id'] = 'BILL-'.$id;
        $session['kwh'] = '1.0';
        $session['currency'] = $currency;
        $session['total_cost'] = ['excl_vat' => $sourceCost ?? $rate];
        $session['last_updated'] = now()->addSecond()->toISOString();
        $this->withHeaders($this->peer())->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/'.$session['id'], $session)->assertCreated();
        $cdr = ['country_code' => 'NL', 'party_id' => 'ABC', 'id' => 'CDR-'.$id, 'session_id' => $session['id'], 'last_updated' => $session['last_updated'], 'start_date_time' => $session['start_date_time'], 'end_date_time' => $session['end_date_time'], 'currency' => $currency, 'total_energy' => '1.0', 'total_time' => '0.001', 'total_cost' => $session['total_cost'], 'cdr_location' => ['id' => 'L1', 'evse_uid' => 'E1', 'connector_id' => '1'], 'cdr_token' => $session['cdr_token'], 'authorization_reference' => $order->authorization_reference];
        $this->postJson('/api/roaming-protocol/2.2.1/cdrs', $cdr)->assertCreated();
        $this->withHeaders(['Authorization' => ''])->postJson('/api/roaming/sandbox-orders/'.$id.'/reconcile')->assertOk()->assertJsonPath('state', 'source_completed')->assertJsonPath('reconciliation_state', 'source_consistent');
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic approved TEST merchant', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-ONLY', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        config(['ocpi.test_billing_enabled' => true, 'ocpi.connections.roaming.test_billing_contract' => ['reference' => 'isolated_billing_v1', 'revision' => 1, 'command_contract' => 'isolated_contract_v1', 'company_id' => $company->id, 'country' => 'NL', 'currency' => $currency, 'minor_units' => $units, 'rate_minor_per_kwh' => $rateMinor, 'tax_bps' => 0, 'commission_bps' => 1000]]);

        return [$owner, $driver, $other, $p, $company, $id, $session];
    }
}
