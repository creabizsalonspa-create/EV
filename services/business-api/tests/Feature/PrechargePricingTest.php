<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Finance\SessionQuote;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcceptsPrechargePricing;
use Tests\TestCase;

class PrechargePricingTest extends TestCase
{
    use AcceptsPrechargePricing;
    use RefreshDatabase;

    private function scenario(string $currency = 'KWD', int $digits = 3): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18T10:00:00Z'));
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Price consent fixture', 'country' => 'AE', 'currency' => $currency, 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $tariff = TariffVersion::create(['station_id' => $station->id, 'created_by' => $vendor->id, 'rate_minor_per_kwh' => 251, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => $currency, 'minor_units' => $digits, 'effective_from' => now()->subMinute()]);
        $this->actingAs($customer);
        $reservation = $this->postJson('/api/reservations', ['station_id' => $station->id, 'starts_at' => '2026-11-18T10:30:00Z', 'idempotency_key' => 'price-fixture'])->assertCreated()->assertJsonPath('reservation_terms_snapshot.price_acceptance_required', true)->json('id');
        $this->travel(30)->minutes();

        return [$vendor, $customer, $station, $tariff, $reservation];
    }

    private function preview(int $reservation, int $energy = 1500): array
    {
        return $this->postJson('/api/reservations/'.$reservation.'/price-estimate', ['energy_wh' => $energy])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    public function test_explicit_customer_acceptance_binds_canonical_three_decimal_quote_and_final_invoice(): void
    {
        [$vendor, $customer, , $tariff, $id] = $this->scenario();
        $this->postJson('/api/reservations/'.$id.'/simulate-start')->assertConflict();
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->getJson('/api/stations')->assertJsonPath('data.0.reservation_terms.price_acceptance_required', true);
        $quote = $this->preview($id);
        $this->assertSame('251', $quote['estimate']['rate_minor_per_kwh']);
        $this->assertSame(3, $quote['estimate']['minor_units']);
        $this->assertSame('377', $quote['estimate']['subtotal_minor']);
        $this->assertSame('19', $quote['estimate']['tax_minor']);
        $this->assertSame('396', $quote['estimate']['total_minor']);
        $this->assertArrayNotHasKey('commission_bps', $quote['estimate']);
        $this->assertDatabaseCount('finance_journals', 0);
        $body = ['quote_token' => $quote['quote_token'], 'accepted' => true];
        $receipt = $this->postJson('/api/reservations/'.$id.'/price-consent', $body)->assertOk()->json('id');
        $this->postJson('/api/reservations/'.$id.'/price-consent', $body)->assertOk()->assertJsonPath('id', $receipt);
        $this->assertDatabaseCount('charging_price_consents', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'charging.price_accepted')->count());
        $session = $this->postJson('/api/reservations/'.$id.'/simulate-start')->assertCreated()->json('id');
        $this->assertSame($receipt, (int) SessionQuote::first()->price_consent_id);
        $this->assertSame($tariff->id, SessionQuote::first()->tariff_version_id);
        $this->actingAs($vendor)->putJson('/api/simulations/'.$session.'/meter', ['energy_wh' => 1500])->assertOk();
        $this->actingAs($customer)->postJson('/api/simulations/'.$session.'/stop')->assertOk();
        $invoice = app(FinanceService::class)->issue(ChargingSession::findOrFail($session), $customer->id);
        $this->assertSame(396, $invoice->total_minor);
        $this->assertSame('KWD', $invoice->currency);
        $this->assertSame(3, $invoice->minor_units);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->postJson('/api/reservations/'.$id.'/price-estimate', ['energy_wh' => 10000])->assertOk()->assertJsonPath('start_already_recorded', true)->assertJsonPath('existing_session_id', $session)->assertJsonMissingPath('quote_token');
    }

    public function test_cancellation_cross_account_invalid_energy_and_unconfirmed_or_tampered_tokens_fail_closed(): void
    {
        [, $customer, , , $id] = $this->scenario();
        $quote = $this->preview($id);
        foreach ([0, -1, 1000001, '1.5', '1e4'] as $energy) {
            $this->postJson('/api/reservations/'.$id.'/price-estimate', ['energy_wh' => $energy])->assertUnprocessable();
        }
        $this->postJson('/api/reservations/'.$id.'/price-consent', ['quote_token' => $quote['quote_token'], 'accepted' => false])->assertUnprocessable();
        $this->postJson('/api/reservations/'.$id.'/price-consent', ['quote_token' => 'altered'.$quote['quote_token'], 'accepted' => true])->assertUnprocessable();
        foreach (['customer', 'vendor', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->postJson('/api/reservations/'.$id.'/price-estimate', ['energy_wh' => 1000])->assertNotFound();
            $this->postJson('/api/reservations/'.$id.'/price-consent', ['quote_token' => $quote['quote_token'], 'accepted' => true])->assertNotFound();
            $this->postJson('/api/reservations/'.$id.'/price-estimate', [])->assertNotFound();
            $this->postJson('/api/reservations/'.$id.'/price-consent', [])->assertNotFound();
        }
        $this->actingAs($customer)->deleteJson('/api/reservations/'.$id)->assertOk();
        $this->postJson('/api/reservations/'.$id.'/price-estimate', ['energy_wh' => 1000])->assertConflict();
        $this->postJson('/api/reservations/'.$id.'/price-consent', ['quote_token' => $quote['quote_token'], 'accepted' => true])->assertConflict();
        $this->assertDatabaseCount('charging_price_consents', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_tariff_changes_before_acceptance_or_before_start_require_new_review(): void
    {
        [$vendor, , $station, , $id] = $this->scenario();
        $quote = $this->preview($id);
        TariffVersion::create(['station_id' => $station->id, 'created_by' => $vendor->id, 'rate_minor_per_kwh' => 300, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'KWD', 'minor_units' => 3, 'effective_from' => now()]);
        $this->postJson('/api/reservations/'.$id.'/price-consent', ['quote_token' => $quote['quote_token'], 'accepted' => true])->assertConflict();
        $receipt = $this->acceptPrechargePrice($id);
        $this->travel(1)->seconds();
        TariffVersion::create(['station_id' => $station->id, 'created_by' => $vendor->id, 'rate_minor_per_kwh' => 350, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'KWD', 'minor_units' => 3, 'effective_from' => now()]);
        $this->postJson('/api/reservations/'.$id.'/simulate-start')->assertConflict();
        $this->assertDatabaseCount('charging_sessions', 0);
        $newReceipt = $this->acceptPrechargePrice($id);
        $this->assertNotSame($receipt, $newReceipt);
        $session = $this->postJson('/api/reservations/'.$id.'/simulate-start')->assertCreated()->json('id');
        $this->assertSame(350, SessionQuote::first()->rate_minor_per_kwh);
        $this->assertSame($newReceipt, (int) SessionQuote::first()->price_consent_id);
        $this->travel(6)->minutes();
        $this->postJson('/api/reservations/'.$id.'/simulate-start')->assertOk()->assertJsonPath('id', $session);
        $this->assertDatabaseCount('finance_session_quotes', 1);
    }

    public function test_expiry_is_inclusive_replay_does_not_extend_it_and_epoch_revocation_requires_fresh_review(): void
    {
        [, $customer, , , $id] = $this->scenario();
        $quote = $this->preview($id);
        $body = ['quote_token' => $quote['quote_token'], 'accepted' => true];
        $receipt = $this->postJson('/api/reservations/'.$id.'/price-consent', $body)->assertOk()->json('id');
        $until = DB::table('charging_price_consents')->value('valid_until');
        $this->travel(300)->seconds();
        $this->postJson('/api/reservations/'.$id.'/price-consent', $body)->assertOk()->assertJsonPath('id', $receipt);
        $this->assertSame($until, DB::table('charging_price_consents')->value('valid_until'));
        $this->postJson('/api/reservations/'.$id.'/simulate-start')->assertConflict();
        $this->acceptPrechargePrice($id);
        $customer->forceFill(['auth_epoch' => $customer->auth_epoch + 1])->save();
        $this->actingAs($customer->fresh())->withSession(['account_auth_epoch' => $customer->fresh()->auth_epoch]);
        $this->postJson('/api/reservations/'.$id.'/price-consent', $body)->assertConflict();
        $this->postJson('/api/reservations/'.$id.'/simulate-start')->assertConflict();
        $this->acceptPrechargePrice($id);
        $this->postJson('/api/reservations/'.$id.'/simulate-start')->assertCreated();
    }

    public function test_receipt_is_reservation_bound_immutable_and_cannot_be_rolled_back(): void
    {
        [, $customer, $station, , $id] = $this->scenario();
        $quote = $this->preview($id);
        $other = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->addHour(), 'idempotency_key' => 'other-price']);
        $this->postJson('/api/reservations/'.$other->id.'/price-consent', ['quote_token' => $quote['quote_token'], 'accepted' => true])->assertConflict();
        $this->acceptPrechargePrice($id);
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($operation) {
                    $q = DB::table('charging_price_consents');
                    $operation === 'delete' ? $q->delete() : $q->update(['auth_epoch' => 100]);
                });
                $this->fail('Consent history was mutated.');
            } catch (QueryException) {
                $this->assertDatabaseCount('charging_price_consents', 1);
            }
        }
        $this->expectException(\RuntimeException::class);
        $migration = require database_path('migrations/2026_11_28_000001_create_charging_price_consents.php');
        $migration->down();
    }

    public function test_legacy_records_are_not_falsely_backfilled_with_customer_acceptance(): void
    {
        [, $customer, $station, , $id] = $this->scenario('JPY', 0);
        $this->actingAs($customer)->deleteJson('/api/reservations/'.$id)->assertOk();
        $legacy = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now(), 'idempotency_key' => 'legacy-price']);
        $this->postJson('/api/reservations/'.$legacy->id.'/simulate-start')->assertCreated();
        $this->assertNull(SessionQuote::first()->price_consent_id);
        $this->assertDatabaseCount('charging_price_consents', 0);
    }
}
