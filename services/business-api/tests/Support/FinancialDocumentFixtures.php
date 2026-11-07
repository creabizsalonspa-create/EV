<?php

namespace Tests\Support;

use App\Models\ChargingSession;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\Documents\FinancialProfiles;
use App\Services\Commercial\SubscriptionCreditService;
use App\Services\Commercial\SubscriptionService;
use App\Services\Finance\FinanceService;
use Illuminate\Support\Facades\DB;

trait FinancialDocumentFixtures
{
    private function profileValues(?int $vendor, string $country = 'AE', string $currency = 'AED', int $precision = 2, string $key = 'profile'): array
    {
        return ['vendor_id' => $vendor, 'country' => $country, 'currency' => $currency, 'minor_units' => $precision, 'legal_name' => 'Crea-Biz Test Issuer', 'legal_name_ar' => 'كريا بيز للطاقة التجريبية', 'address' => 'Synthetic business address, Dubai', 'address_ar' => 'عنوان تجريبي، دبي', 'tax_registration' => 'TEST-REGISTRATION-NOT-VERIFIED', 'effective_from' => now()->subYear()->toDateTimeString(), 'idempotency_key' => $key];
    }

    private function documentFixture(string $country = 'AE', string $currency = 'AED', int $precision = 2): array
    {
        config(['payments.provider' => 'mock']);
        if ($country !== 'AE') {
            DB::table('regions')->insert(['country' => $country, 'currency' => $currency, 'minor_units' => $precision, 'timezone' => 'UTC', 'tax_bps' => null, 'commerce_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['name' => 'Synthetic Document Customer']);
        $profiles = app(FinancialProfiles::class);
        $profile = $profiles->publish($admin, $this->profileValues($vendor->id, $country, $currency, $precision, 'vendor-profile'));
        $platformProfile = $profiles->publish($admin, $this->profileValues(null, $country, $currency, $precision, 'platform-profile'));
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Document test simulator', 'country' => $country, 'currency' => $currency, 'timezone' => 'UTC', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $tariff = TariffVersion::create(['station_id' => $station->id, 'created_by' => $admin->id, 'rate_minor_per_kwh' => 100, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => $currency, 'minor_units' => $precision, 'effective_from' => now()->subMinute()]);
        $reservation = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now(), 'status' => 'confirmed', 'idempotency_key' => 'document-fixture']);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 12345, 'rate_minor_per_kwh' => 100, 'currency' => $currency, 'total_minor' => 1235, 'stopped_at' => now()]);
        $finance = app(FinanceService::class);
        $finance->snapshotSession($session, $tariff);
        $invoice = $finance->issue($session, $customer->id);

        return compact('admin', 'vendor', 'customer', 'invoice', 'profile', 'platformProfile');
    }

    private function subscriptionDocumentFixture(array $f): array
    {
        $service = app(SubscriptionService::class);
        $plan = $service->publish($f['admin'], ['code' => 'document-customer', 'name' => 'Customer document plan', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 1000, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => json_encode(['priority_support' => true])]);
        $summary = $service->subscribe($f['customer'], $plan->id, 'document-enroll');
        $subscription = $summary['subscription']->id;
        $invoice = $summary['invoices']->first()->id;
        $service->mockPay($f['customer'], $subscription, $invoice, 'succeeded', 'document-payment');
        $credit = app(SubscriptionCreditService::class)->refund($f['admin'], $subscription, $invoice, 300, 'Synthetic subscription credit', 'document-credit')['credit'];

        return [$invoice, $credit->id];
    }
}
