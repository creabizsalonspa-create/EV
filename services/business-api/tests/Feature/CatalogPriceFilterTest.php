<?php

namespace Tests\Feature;

use App\Models\Finance\TariffVersion;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogPriceFilterTest extends TestCase
{
    use RefreshDatabase;

    private function station(string $source = 'simulator'): Station
    {
        return Station::create(['vendor_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => 'Price search', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => $source, 'connector_type' => 'CCS2', 'power_kw' => 50]);
    }

    private function tariff(Station $station, int $rate, string $effective, string $currency = 'AED', int $units = 2): void
    {
        TariffVersion::create(['station_id' => $station->id, 'created_by' => $station->vendor_id, 'rate_minor_per_kwh' => $rate, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => $currency, 'minor_units' => $units, 'effective_from' => $effective]);
    }

    private function search(string $maximum = '1.50', string $currency = 'AED')
    {
        return $this->getJson('/api/stations?'.http_build_query(['max_rate_per_kwh' => $maximum, 'price_currency' => $currency]));
    }

    public function test_only_current_effective_rate_matches_with_deterministic_ties(): void
    {
        $this->freezeTime();
        $match = $this->station();
        $this->tariff($match, 150, now()->subMinute()->toDateTimeString());
        $this->tariff($match, 900, now()->addMinute()->toDateTimeString());
        $expensive = $this->station();
        $this->tariff($expensive, 10, now()->subHour()->toDateTimeString());
        $this->tariff($expensive, 151, now()->subMinute()->toDateTimeString());
        $tied = $this->station();
        $this->tariff($tied, 1, now()->toDateTimeString());
        $this->tariff($tied, 152, now()->toDateTimeString());
        $this->station(); // Unknown price is never treated as zero.
        $discovery = $this->station('discovery');
        $this->tariff($discovery, 1, now()->subMinute()->toDateTimeString());
        $this->search()->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('data.0.evidence', 'SIMULATED')->assertJsonPath('data.0.availability', 'unknown');
        $this->search('1.49')->assertJsonPath('total', 0);
    }

    public function test_currency_precision_is_exact_and_never_compares_other_currency_or_bad_tariff_units(): void
    {
        $station = $this->station();
        $this->tariff($station, 1, now()->subMinute()->toDateTimeString(), 'USD');
        $this->search()->assertJsonPath('total', 0);
        $other = $this->station();
        $this->tariff($other, 1, now()->subMinute()->toDateTimeString(), 'AED', 3);
        $this->search()->assertJsonPath('total', 0);
        DB::table('regions')->insert(['country' => 'JP', 'currency' => 'JPY', 'minor_units' => 0, 'timezone' => 'Asia/Tokyo']);
        $yen = $this->station();
        $yen->update(['currency' => 'JPY']);
        $this->tariff($yen, 2, now()->subMinute()->toDateTimeString(), 'JPY', 0);
        $this->search('2', 'JPY')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $yen->id);
        $this->search('2.0', 'JPY')->assertUnprocessable();
        $this->search('1.501')->assertUnprocessable();
        $this->search('1.50', 'ZZZ')->assertUnprocessable();
    }

    public function test_invalid_partial_or_ambiguous_filters_are_rejected(): void
    {
        foreach (['-1', '1e2', '1,50', '1000000', '1.0000', 'NaN'] as $value) {
            $this->search($value)->assertUnprocessable();
        }
        $this->getJson('/api/stations?price_currency=AED')->assertUnprocessable();
        $this->getJson('/api/stations?max_rate_per_kwh=1')->assertUnprocessable();
        $this->getJson('/api/stations?max_rate_per_kwh[]=1&price_currency=AED')->assertUnprocessable();
        DB::table('regions')->insert(['country' => 'XX', 'currency' => 'AED', 'minor_units' => 3, 'timezone' => 'UTC']);
        $this->search()->assertUnprocessable();
    }
}
