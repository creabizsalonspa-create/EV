<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\RecordNormalizer;
use App\Services\Ocpi\RecordSynchronizer;
use App\Services\Ocpi\StoredSourceIntegrity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OcpiStoredSourceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $suffix): OcpiProvider
    {
        return OcpiProvider::create([
            'owner_id' => User::factory()->create(['role' => 'vendor'])->id,
            'name' => 'Synthetic operator '.$suffix,
            'connection_key' => 'integrity-'.$suffix,
            'authorized' => true,
            'version' => '2.2.1',
        ]);
    }

    private function location(string $id): array
    {
        return [
            'id' => $id, 'country_code' => 'AE', 'party_id' => 'TST',
            'last_updated' => now()->subMinute()->toISOString(),
            'publish' => true, 'name' => 'PRIVATE-SOURCE-NAME',
            'address' => 'PRIVATE-SOURCE-ADDRESS', 'city' => 'Test city', 'country' => 'ARE',
            'coordinates' => ['latitude' => '25.1', 'longitude' => '55.2'],
            'evses' => [],
        ];
    }

    public function test_operator_inspects_only_selected_module_and_provider_in_bounded_pages(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $provider = $this->provider('one');
        $other = $this->provider('two');
        $sync = app(RecordSynchronizer::class);
        $sync->ingest($provider, 'locations', [$this->location('first'), $this->location('second')]);
        $sync->ingest($other, 'locations', [$this->location('other')]);
        $sync->ingest($provider, 'tariffs', [[
            'id' => 'tariff', 'country_code' => 'AE', 'party_id' => 'TST',
            'last_updated' => now()->subMinute()->toISOString(), 'currency' => 'AED',
            'elements' => [['price_components' => [['type' => 'ENERGY', 'price' => '0.25', 'step_size' => 1]]]],
        ]]);
        $provider->update(['authorized' => false, 'version' => null]);
        $before = DB::table('ocpi_records')->orderBy('id')->get()->toArray();
        $integrity = app(StoredSourceIntegrity::class);
        $first = $integrity->inspect($provider->id, 'locations', 0, 1);
        $this->assertSame('page_consistent', $first['status']);
        $this->assertSame(1, $first['examined']);
        $this->assertTrue($first['has_more']);
        $second = $integrity->inspect($provider->id, 'locations', $first['next_after_id'], 1);
        $this->assertSame(1, $second['consistent']);
        $this->assertFalse($second['has_more']);
        $this->assertNull($second['next_after_id']);
        $this->assertSame(1, $integrity->inspect($provider->id, 'tariffs')['consistent']);
        $this->assertSame('no_evidence', $integrity->inspect($provider->id, 'cdrs')['status']);
        $this->assertFalse($first['whole_catalog_verified']);
        $this->assertFalse($first['partner_conformance_verified']);
        $this->assertFalse($first['charging_authority']);
        $this->assertFalse($first['billing_authority']);
        $this->assertSame(0, $first['external_requests']);
        $this->assertSame(0, $first['source_mutations']);
        $this->assertEquals($before, DB::table('ocpi_records')->orderBy('id')->get()->toArray());
        $this->assertStringNotContainsString('PRIVATE-SOURCE', json_encode($first, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_inconsistent_and_unreadable_stored_rows_are_reported_without_repair_or_leakage(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $provider = $this->provider('review');
        app(RecordSynchronizer::class)->ingest($provider, 'locations', [
            $this->location('altered'), $this->location('cipher'), $this->location('intact'),
        ]);
        $first = OcpiRecord::where('external_id', 'altered')->firstOrFail();
        $changed = $first->normalized;
        $changed['remote_control'] = true;
        DB::table('ocpi_records')->where('id', $first->id)->update([
            'normalized' => json_encode($changed, JSON_THROW_ON_ERROR),
            'external_id' => 'unbound-identity',
            'content_hash' => str_repeat('a', 64),
            'source_updated_at' => now()->subHour()->format('Y-m-d H:i:s.u'),
        ]);
        DB::table('ocpi_records')->where('external_id', 'cipher')->update(['payload' => 'PRIVATE-UNREADABLE-CIPHERTEXT']);
        $before = DB::table('ocpi_records')->orderBy('id')->get()->toArray();

        $this->assertSame(1, Artisan::call('ocpi:inspect-source-integrity', ['provider' => $provider->id, 'module' => 'locations']));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('review_required', $report['status']);
        $this->assertSame(3, $report['examined']);
        $this->assertSame(1, $report['consistent']);
        $this->assertSame(2, $report['review_required']);
        foreach (['digest_mismatch', 'identity_mismatch', 'source_timestamp_mismatch', 'projection_mismatch', 'payload_unreadable'] as $finding) {
            $this->assertSame(1, $report['findings'][$finding]);
        }
        foreach (['PRIVATE-', 'unbound-identity', str_repeat('a', 64)] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        $this->assertEquals($before, DB::table('ocpi_records')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_final_session_and_cdr_pages_are_read_only_and_invalid_legacy_payload_requires_review(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $provider = $this->provider('final');
        $sync = app(RecordSynchronizer::class);
        $identity = [
            'country_code' => 'AE', 'party_id' => 'TST',
            'last_updated' => now()->subMinute()->toISOString(),
            'start_date_time' => now()->subHours(2)->toISOString(),
            'end_date_time' => now()->subHour()->toISOString(),
            'currency' => 'AED',
        ];
        $sync->ingest($provider, 'sessions', [$identity + [
            'id' => 'final-session', 'status' => 'COMPLETED', 'kwh' => '2.25',
            'location_id' => 'private-location', 'evse_uid' => 'private-evse', 'connector_id' => '1',
        ]]);
        $sync->ingest($provider, 'cdrs', [$identity + [
            'id' => 'final-cdr', 'session_id' => 'final-session', 'total_energy' => '2.25',
            'total_time' => '1', 'total_cost' => ['excl_vat' => '3.75'],
            'cdr_location' => ['id' => 'private-location', 'evse_uid' => 'private-evse', 'connector_id' => '1'],
            'cdr_token' => ['uid' => 'PRIVATE-DRIVER-TOKEN'],
        ]]);
        $location = $this->location('legacy');
        $sync->ingest($provider, 'locations', [$location]);
        unset($location['coordinates']);
        DB::table('ocpi_records')->where('external_id', 'legacy')->update([
            'payload' => Crypt::encryptString(json_encode($location, JSON_THROW_ON_ERROR)),
            'content_hash' => app(RecordNormalizer::class)->hash($location),
        ]);
        $before = DB::table('ocpi_records')->orderBy('id')->get()->toArray();
        foreach (['sessions', 'cdrs'] as $module) {
            $this->assertSame(0, Artisan::call('ocpi:inspect-source-integrity', [
                'provider' => $provider->id, 'module' => $module,
            ]));
            $output = Artisan::output();
            $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $report['consistent']);
            $this->assertFalse($report['billing_authority']);
            $this->assertStringNotContainsString('PRIVATE-DRIVER-TOKEN', $output);
            $this->assertStringNotContainsString('private-location', $output);
        }
        $report = app(StoredSourceIntegrity::class)->inspect($provider->id, 'locations');
        $this->assertSame('review_required', $report['status']);
        $this->assertSame(1, $report['findings']['current_subset_validation_failed']);
        $this->assertSame(0, $report['findings']['digest_mismatch']);
        $this->assertEquals($before, DB::table('ocpi_records')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_bounds_and_no_evidence_never_return_success(): void
    {
        $provider = $this->provider('empty');
        $args = ['provider' => $provider->id, 'module' => 'locations'];
        foreach ([['--limit' => '101'], ['--limit' => '0'], ['--after' => '-1'], ['module' => 'commands'], ['provider' => '1e2']] as $invalid) {
            $this->assertSame(2, Artisan::call('ocpi:inspect-source-integrity', array_replace($args, $invalid)));
        }
        $this->assertSame(1, Artisan::call('ocpi:inspect-source-integrity', $args));
        $this->assertSame('no_evidence', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status']);
    }
}
