<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartnerDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function source(): OcpiProvider
    {
        $provider = OcpiProvider::create(['owner_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => 'Approved source fixture', 'connection_key' => 'discovery-fixture', 'authorized' => true, 'version' => '2.2.1']);
        $common = ['country_code' => 'NL', 'party_id' => 'TST', 'publish' => true, 'address' => 'Public source address', 'coordinates' => ['latitude' => '25.2', 'longitude' => '55.3'], 'last_updated' => now()->subMinute()->toISOString()];
        $evse = fn ($standard) => [['uid' => 'evse-1', 'status' => 'AVAILABLE', 'connectors' => [['id' => '1', 'standard' => $standard]]]];
        app(RecordSynchronizer::class)->ingest($provider, 'locations', [
            $common + ['id' => 'harbour', 'name' => 'Harbour depot', 'city' => 'Dubai', 'country' => 'ARE', 'evses' => $evse('IEC_62196_T2_COMBO')],
            $common + ['id' => 'canal', 'name' => 'Canal depot', 'city' => 'Amsterdam', 'country' => 'NLD', 'evses' => $evse('IEC_62196_T2')],
            array_replace($common, ['id' => 'secret', 'name' => 'Harbour private', 'city' => 'Dubai', 'country' => 'ARE', 'publish' => false, 'evses' => $evse('IEC_62196_T2_COMBO')]),
        ]);

        return $provider;
    }

    public function test_public_country_text_and_connector_filters_preserve_provenance_and_authorization(): void
    {
        $provider = $this->source();
        $this->getJson('/api/roaming/locations?'.http_build_query(['q' => 'harb', 'country' => 'ARE', 'connector_standard' => 'IEC_62196_T2_COMBO']))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.location.id', 'harbour')->assertJsonPath('data.0.availability', 'unknown')->assertJsonPath('data.0.remote_control', false)->assertJsonPath('data.0.reservable', false)->assertJsonMissingPath('data.0.is_favorite');
        // Physical location country is distinct from the CPO's country identity.
        $this->getJson('/api/roaming/locations?country=NLD')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.location.id', 'canal');
        $this->getJson('/api/roaming/locations?q=Amsterdam')->assertJsonPath('total', 1);
        $this->getJson('/api/roaming/locations?connector_standard=CHADEMO')->assertJsonPath('total', 0);
        $provider->update(['authorized' => false]);
        $this->getJson('/api/roaming/locations?country=ARE')->assertJsonPath('total', 0);
    }

    public function test_invalid_filters_are_bounded_before_querying(): void
    {
        $this->getJson('/api/roaming/locations?country=AE')->assertUnprocessable();
        $this->getJson('/api/roaming/locations?q='.str_repeat('a', 81))->assertUnprocessable();
        $this->getJson('/api/roaming/locations?connector_standard='.str_repeat('a', 65))->assertUnprocessable();
    }

    public function test_historical_connector_indexing_preserves_encrypted_source_and_fingerprints(): void
    {
        $provider = $this->source();
        $record = OcpiRecord::where('ocpi_provider_id', $provider->id)->where('external_id', 'harbour')->firstOrFail();
        $n = $record->normalized;
        unset($n['connector_standards']);
        DB::table('ocpi_records')->where('id', $record->id)->update(['normalized' => json_encode($n, JSON_THROW_ON_ERROR)]);
        $ciphertext = $record->getRawOriginal('payload');
        $hash = $record->content_hash;
        $timestamp = $record->source_updated_at->toISOString();
        (require database_path('migrations/2026_11_08_000014_index_partner_connector_standards.php'))->up();
        $fresh = $record->fresh();
        $this->assertSame(['IEC_62196_T2_COMBO'], $fresh->normalized['connector_standards']);
        $this->assertSame($ciphertext, $fresh->getRawOriginal('payload'));
        $this->assertSame($hash, $fresh->content_hash);
        $this->assertSame($timestamp, $fresh->source_updated_at->toISOString());
        $this->getJson('/api/roaming/locations?connector_standard=IEC_62196_T2_COMBO')->assertOk()->assertJsonPath('total', 1);
    }
}
