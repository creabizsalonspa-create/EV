<?php

namespace Tests\Feature;

use App\Http\Controllers\RiderSourceController;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Services\Charging\ConfigurationProbe;
use App\Services\Charging\ContinuousFaultObserver;
use App\Services\Charging\CsmsGraphqlTransport;
use App\Services\Charging\RemoteSessionAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CsmsGraphqlTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'csms.graphql_secret' => str_repeat('s', 64),
            'csms.fault_worker_enabled' => true,
            'csms.session_bridge_enabled' => true,
        ]);
    }

    public function test_only_fixed_local_http_or_sanitized_https_source_transport_is_approved(): void
    {
        $guard = app(CsmsGraphqlTransport::class);
        foreach ([
            'http://127.0.0.1:8095/v1/graphql',
            'http://localhost:8095/v1/graphql',
            'http://charging-graphql:8080/v1/graphql',
            'http://citrine-hasura:8080/v1/graphql',
            'https://source.test/graphql',
            'https://source.example.com/v1/graphql',
        ] as $url) {
            $this->assertTrue($guard->approved($url), 'Rejected an approved CSMS transport.');
        }
        foreach ([
            '', 'javascript:alert(1)', 'http://source.test/graphql',
            'http://charging-graphql.evil.test/v1/graphql',
            'http://169.254.169.254/latest/meta-data',
            'http://unreviewed-graphql:8080/v1/graphql',
            'http://operator:password@charging-graphql:8080/v1/graphql',
            'http://charging-graphql:8080/v1/graphql?secret=PRIVATE',
            'http://charging-graphql:8080/v1/graphql#PRIVATE',
            'http://charging-graphql:8080/other',
            'https://operator:password@source.test/graphql',
            'https://source.test/graphql?secret=PRIVATE',
            'https://source.test/graphql#PRIVATE',
            'https://source.test/',
            'https://source.test/graphql'.chr(10),
        ] as $url) {
            $this->assertFalse($guard->approved($url), 'Unapproved source transport was accepted.');
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_unreviewed_plaintext_source_never_receives_a_remote_authorization_or_rider_secret(): void
    {
        config(['csms.graphql_url' => 'http://unapproved-source.test/graphql']);
        $binding = (object) ['identifier' => 'ev-test-source', 'protocol' => '1.6'];
        $map = new ConnectorMapping(['source_connector_number' => 1]);
        $evse = new Evse(['source_evse_number' => 0]);

        $this->assertFalse(app(RiderSourceController::class)->targetMatches(
            $binding, $map, $evse, ['connectorId' => 7]
        ));
        try {
            app(RemoteSessionAuthorization::class)->graph(
                'query($name:String!){ChargingStations(where:{ocppConnectionName:{_eq:$name}}){id}}',
                ['name' => 'ev-test-source']
            );
            $this->fail('An unauthorised CSMS source transport accepted the admin key.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        try {
            app(ConfigurationProbe::class)->graph('query{ChargingStations(limit:1){id}}', []);
            $this->fail('Unapproved configuration source probe sent a Hasura admin key.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_fault_worker_rejects_bad_source_transport_before_touching_a_cursor_or_operator_inbox(): void
    {
        config(['csms.graphql_url' => 'http://charging-graphql.evil.test/v1/graphql']);
        try {
            app(ContinuousFaultObserver::class)->observe(999);
            $this->fail('Fault observer admitted untrusted plaintext admin endpoint.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('charging_fault_cursors', 0);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertDatabaseCount('inbox_notifications', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_approved_local_compose_source_still_supports_authenticated_read_only_graphql(): void
    {
        $url = 'http://charging-graphql:8080/v1/graphql';
        config(['csms.graphql_url' => $url]);
        Http::fake([
            $url => Http::response(['data' => ['ChargingStations' => []]]),
        ]);
        $source = app(RemoteSessionAuthorization::class)->graph(
            'query{ChargingStations(limit:1){id}}', []
        );
        $this->assertSame(['ChargingStations' => []], $source);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === $url
            && ($request->header('x-hasura-admin-secret')[0] ?? null) === str_repeat('s', 64));
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
