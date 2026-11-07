<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\FaultMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChargerDiagnosticLogTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $protocol = '1.6'): array
    {
        Http::preventStrayRequests();
        config(['csms.session_bridge_enabled' => true, 'csms.graphql_secret' => str_repeat('s', 32), 'csms.graphql_url' => 'https://source.test/graphql']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Diagnostics fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Fixture', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Fixture', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'DIAGNOSTICS', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-diagnostics-'.$charger->id, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $company, $charger, '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/source-diagnostics'];
    }

    private array $payload = [];

    private function setupLog(string $protocol = '1.6'): array
    {
        [$owner,$company,$charger,$old] = $this->fixture($protocol);
        config(['csms.diagnostic_logs_enabled' => true, 'csms.diagnostic_upload_origin' => 'http://127.0.0.1:8000', 'csms.operations_base_url' => 'http://127.0.0.1:8080', 'csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql']);
        Http::swap(new Factory);
        Http::fake(function ($request) use ($protocol) {
            if (str_ends_with($request->url(), '/dev/token')) {
                return Http::response(['accessToken' => 'private-service-token']);
            }
            if (str_contains($request->url(), '/reporting/')) {
                $this->payload = $request->data();

                return Http::response([['success' => true]]);
            }
            $q = $request['query'];
            if (str_contains($q, 'ChargingStations')) {
                return Http::response(['data' => ['ChargingStations' => [['id' => 42, 'protocol' => 'ocpp'.$protocol, 'isOnline' => true]]]]);
            }

            return Http::response(['data' => ['OCPPMessages' => []]]);
        });

        return [$owner, $company, $charger, str_replace('source-diagnostics', 'diagnostic-logs', $old)];
    }

    private function requestBody(): array
    {
        return ['request_key' => (string) Str::uuid(), 'reason' => 'Inspect isolated source diagnostic file'];
    }

    private function upload(int $id, string $bytes, ?string $token = null)
    {
        $op = DB::table('charger_diagnostic_logs')->find($id);
        $p = json_decode(Crypt::decryptString($op->payload_ciphertext), true);
        $url = $p['location'] ?? $p['log']['remoteLocation'];
        $password = parse_url($url, PHP_URL_PASS);

        return $this->call('PUT', '/api/internal/diagnostic-logs/'.$id, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => (string) strlen($bytes), 'PHP_AUTH_USER' => 'diagnostic', 'PHP_AUTH_PW' => $token ?? $password, 'HTTP_ACCEPT' => 'application/json'], $bytes);
    }

    public function test_one_shot_dispatch_authenticated_encrypted_upload_and_owner_download_without_secret_metadata(): void
    {
        [$owner,$company,$charger,$url] = $this->setupLog();
        $body = $this->requestBody();
        $created = $this->actingAs($owner)->postJson($url, $body)->assertCreated()->assertJsonPath('dispatch_state', 'transport_submitted');
        $id = $created->json('id');
        $this->postJson($url, $body)->assertOk()->assertJsonPath('id', $id);
        $this->assertCount(1, Http::recorded(fn ($q) => str_contains($q->url(), '/reporting/')));
        $changed = $body;
        $changed['reason'] = 'A changed diagnostic reason';
        $this->postJson($url, $changed)->assertConflict();
        $this->postJson($url, $this->requestBody())->assertConflict();
        $content = "PRIVATE device log\0with binary bytes";
        $this->upload($id, $content, 'incorrect')->assertNotFound();
        $this->upload($id, $content)->assertCreated();
        $this->upload($id, $content)->assertOk()->assertJsonPath('replayed', true);
        $this->upload($id, 'Different immutable log')->assertConflict();
        $this->get($url.'/'.$id.'/download')->assertNotFound();
        DB::table('charger_diagnostic_logs')->where('id', $id)->update(['accepted_at' => now(), 'source_request_id' => 100, 'source_reply_id' => 200, 'source_reply_digest' => hash('sha256', 'synthetic-correlated-reply')]);
        $download = $this->get($url.'/'.$id.'/download')->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertSame($content, $download->getContent());
        $this->assertStringNotContainsString('PRIVATE', DB::table('charger_diagnostic_logs')->find($id)->file_ciphertext);
        $list = $this->getJson($url)->assertOk()->assertJsonPath('data.0.can_download', true);
        $this->assertStringNotContainsString('diagnostic:', $list->getContent());
        $this->assertStringNotContainsString('reason_ciphertext', $list->getContent());
        $this->assertStringNotContainsString('payload_ciphertext', $list->getContent());
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $staff = User::factory()->create();
        VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'email' => $staff->email, 'status' => 'active', 'permissions' => ['inventory.read']]);
        $this->actingAs($staff)->getJson($url)->assertOk()->assertJsonPath('data.0.can_download', false)->assertJsonPath('can_request', false);
        $this->get($url.'/'.$id.'/download')->assertNotFound();
        $this->postJson($url, $this->requestBody())->assertNotFound();
    }

    public function test_expiration_cancellation_retention_and_rebinding_never_accept_late_content(): void
    {
        [$owner,,,$url] = $this->setupLog();
        $this->actingAs($owner);
        $id = $this->postJson($url, $this->requestBody())->assertCreated()->json('id');
        $this->upload($id, 'Private retained diagnostic data')->assertCreated();
        DB::table('charger_diagnostic_logs')->where('id', $id)->update(['accepted_at' => now(), 'source_request_id' => 100, 'source_reply_id' => 200, 'source_reply_digest' => hash('sha256', 'synthetic-correlated-reply')]);
        $this->travel(16)->minutes();
        $this->upload($id, 'Private retained diagnostic data')->assertNotFound();
        $this->get($url.'/'.$id.'/download')->assertOk();
        $this->artisan('csms:expire-diagnostic-files --limit=10')->assertSuccessful();
        $this->assertNull(DB::table('charger_diagnostic_logs')->find($id)->upload_token_digest);
        $this->travel(25)->hours();
        $this->get($url.'/'.$id.'/download')->assertNotFound();
        $this->artisan('csms:expire-diagnostic-files')->assertSuccessful();
        $this->assertNull(DB::table('charger_diagnostic_logs')->find($id)->file_ciphertext);
        $this->travelBack();
        $this->deleteJson($url.'/'.$id)->assertOk();
        $second = $this->postJson($url, $this->requestBody())->assertCreated()->json('id');
        $this->deleteJson($url.'/'.$second)->assertOk()->assertJsonPath('source_command_sent', false);
        $this->deleteJson($url.'/'.$second)->assertOk();
        $this->assertNull(DB::table('charger_diagnostic_logs')->find($second)->payload_ciphertext);
        $this->call('PUT', '/api/internal/diagnostic-logs/'.$second, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => '1', 'PHP_AUTH_USER' => 'diagnostic', 'PHP_AUTH_PW' => str_repeat('a', 64)], 'x')->assertNotFound();
    }

    public function test_oversized_invalid_auth_foreign_actor_and_production_are_rejected(): void
    {
        [$owner,,,$url] = $this->setupLog('2.0.1');
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        $this->actingAs($owner);
        $id = $this->postJson($url, $this->requestBody())->assertCreated()->json('id');
        $this->upload($id, str_repeat('x', 524289))->assertStatus(413);
        $this->assertNull(DB::table('charger_diagnostic_logs')->find($id)->file_ciphertext);
        $this->app->instance('env', 'production');
        $this->upload($id, 'A log body')->assertServiceUnavailable();
    }

    public function test_canonical_reply_and_protocol_specific_status_are_sanitized_and_do_not_redispatch(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            [$owner,,,$url] = $this->setupLog($protocol);
            $this->actingAs($owner);
            $id = $this->postJson($url, $this->requestBody())->assertCreated()->json('id');
            $op = DB::table('charger_diagnostic_logs')->find($id);
            $time = CarbonImmutable::parse($op->attempted_at);
            $corrupt = false;
            Http::swap(new Factory);
            Http::fake(function ($request) use ($protocol, $id, $time, &$corrupt) {
                $q = $request['query'];
                if (str_contains($q, 'ChargingStations')) {
                    return Http::response(['data' => ['ChargingStations' => [['id' => 42, 'protocol' => $protocol, 'isOnline' => false]]]]);
                }
                if (str_contains($q, 'requestMessageId')) {
                    return Http::response(['data' => ['OCPPMessages' => [['id' => 200, 'type' => 3, 'createdAt' => $time->addSecond()->toISOString(), 'payload' => $corrupt ? ['status' => 'Rejected'] : ($protocol === '1.6' ? ['fileName' => 'PRIVATE-filename-with-token.log'] : ['status' => 'Accepted'])]]]]);
                }
                if (str_contains($q, 'payload:{_contains')) {
                    return Http::response(['data' => ['OCPPMessages' => [['id' => 300, 'createdAt' => $time->addSeconds(2)->toISOString(), 'payload' => ['requestId' => $id, 'status' => 'Uploaded', 'private' => 'PRIVATE-source-field']]]]]);
                }

                return Http::response(['data' => ['OCPPMessages' => [['id' => 100, 'createdAt' => $time->toISOString()]]]]);
            });
            $reply = $this->postJson($url.'/'.$id.'/observe')->assertOk()->assertJsonPath('device_status', $protocol === '1.6' ? 'legacy_status_unattributed' : 'Uploaded');
            $this->assertStringNotContainsString('PRIVATE', $reply->getContent());
            $this->assertNotNull($reply->json('accepted_at'));
            $this->assertCount(0, Http::recorded(fn ($q) => str_contains($q->url(), '/reporting/')));
            $corrupt = true;
            $this->postJson($url.'/'.$id.'/observe')->assertConflict();
        }
    }

    public function test_work_order_links_are_scoped_to_the_same_canonical_fault_generation(): void
    {
        [$owner,$company,$charger,$url] = $this->setupLog();
        $this->actingAs($owner);
        $b = DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->first();
        app(FaultMonitor::class)->ingest($b, ['source_generation' => FaultMonitor::generation($b), 'events' => [['id' => 1, 'evseId' => 1, 'connectorId' => 1, 'connectorStatus' => 'Faulted', 'errorCode' => 'GroundFailure', 'timestamp' => now()->toISOString()]]]);
        $alert = DB::table('charging_fault_alerts')->first()->id;
        $repair = $this->postJson(str_replace('/diagnostic-logs', '/repair-orders', $url), ['request_key' => (string) Str::uuid(), 'alert_id' => $alert, 'expected_version' => 0, 'state' => 'open', 'note' => 'Inspect fault and request diagnostic evidence'])->assertOk()->json('id');
        $this->postJson($url, $this->requestBody() + ['repair_order_id' => 99999])->assertNotFound();
        $this->getJson($url.'?repair_order_id=99999')->assertNotFound();
        $body = $this->requestBody() + ['repair_order_id' => $repair];
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('repair_order_id', $repair);
        $this->getJson($url.'?repair_order_id='.$repair)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.repair_order_id', $repair);
        unset($body['repair_order_id']);
        $this->postJson($url, $body)->assertConflict();
    }
}
