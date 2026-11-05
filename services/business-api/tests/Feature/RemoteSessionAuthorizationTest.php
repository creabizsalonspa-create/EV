<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\RemoteSessionAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RemoteSessionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        config(['csms.remote_authorization_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.rider_enabled' => true, 'csms.rider_client_secret' => str_repeat('s', 64), 'csms.rider_client_id' => 'creabiz-csms-rider', 'csms.ingress_allow_local_http' => true, 'csms.rider_source_api_url' => 'http://source.test']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Remote fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-remote', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $reservation = Reservation::create(['station_id' => $station->id, 'user_id' => $customer->id, 'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'remote']);
        $response = $this->actingAs($customer)->postJson('/api/reservations/'.$reservation->id.'/csms-start')->assertAccepted();

        return [$customer, $reservation, $response, ChargerCommand::firstOrFail(), SessionLink::firstOrFail()];
    }

    public function test_generated_remote_credential_is_opaque_stable_and_not_exposed_in_session_responses(): void
    {
        [$customer,$reservation,$response,$command,$link] = $this->fixture();
        $tag = $command->payload['id_token'];
        $this->assertMatchesRegularExpression('/^EV-[A-F0-9]{16}$/D', $tag);
        $this->assertSame(hash('sha256', $tag), $link->remote_token_digest);
        $this->assertTrue(Str::isUuid($link->remote_registration_nonce));
        $this->postJson('/api/reservations/'.$reservation->id.'/csms-start')->assertAccepted()->assertJsonPath('start_command.id', $command->id);
        $this->assertSame($tag, $command->fresh()->payload['id_token']);
        $this->assertStringNotContainsString($tag, $response->getContent());
        $this->assertStringNotContainsString($tag, $this->getJson('/api/simulations')->assertOk()->getContent());
        foreach (['remote_token_digest', 'remote_registration_nonce', 'remote_authorization_id'] as $key) {
            $this->assertArrayNotHasKey($key, $link->toArray());
        }
    }

    public function test_unverified_source_cannot_receive_an_accepted_remote_authorization(): void
    {
        [,,,$command,$link] = $this->fixture();
        Http::fake(['source.test/*' => Http::response(['ready' => true, 'remote_session_authorization' => true, 'tenant_id' => 1, 'offline_fallback' => false, 'cache_seconds' => 0, 'proof' => str_repeat('0', 64)])]);
        try {
            app(RemoteSessionAuthorization::class)->publish($command);
            $this->fail('Unverified source must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        Http::assertSentCount(1);
        $this->assertNull($link->fresh()->remote_authorization_id);
        $this->assertSame('pending', $command->fresh()->status);
    }

    public function test_unknown_service_credentials_and_unknown_remote_token_fail_closed(): void
    {
        $this->fixture();
        $body = ['idToken' => 'EV-0123456789ABCDEF', 'idTokenType' => 'Central', 'ocppConnectionName' => 'ev-test-remote'];
        $this->postJson('/api/internal/remote-session-authorize', $body)->assertUnauthorized();
        $bearer = $this->postJson('/api/internal/rider-oauth/token', ['grant_type' => 'client_credentials', 'client_id' => 'creabiz-csms-rider', 'client_secret' => str_repeat('s', 64), 'audience' => 'creabiz-rider-authorize'])->assertOk()->json('access_token');
        $this->withToken($bearer)->postJson('/api/internal/remote-session-authorize', $body)->assertOk()->assertJsonPath('data.allowed', 'NOT_ALLOWED');
        Http::assertNothingSent();
        $this->assertDatabaseCount('csms_session_links',1);
    }
}
