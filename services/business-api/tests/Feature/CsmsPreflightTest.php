<?php

namespace Tests\Feature;

use App\Services\Charging\CsmsPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CsmsPreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'csms.simulator_enabled' => true,
            'csms.session_bridge_enabled' => true,
            'csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql',
            'csms.graphql_secret' => str_repeat('a', 64),
            'csms.event_secret' => str_repeat('b', 64),
        ]);
    }

    public function test_offline_charging_preflight_checks_migrations_without_claiming_hardware(): void
    {
        $report = app(CsmsPreflight::class)->report();
        $this->assertSame('software_configuration_ready', $report['status']);
        $this->assertTrue($report['software_configuration_ready']);
        $this->assertSame([true, true, true, true], array_values($report['checks']));
        $this->assertSame(['1.6', '2.0.1'], $report['protocols_supported_by_simulator']);
        foreach (['source_connected', 'charger_hardware_verified', 'queue_worker_verified',
            'certificate_lifecycle_accepted', 'production_ready', 'external_pilot_approved',
            'device_commands_sent', 'database_mutations'] as $key) {
            $this->assertFalse($report[$key]);
        }
        $this->assertSame(0, Artisan::call('csms:preflight'));
        $output = Artisan::output();
        $this->assertStringContainsString('"software_configuration_ready":true', $output);
        $this->assertStringNotContainsString(str_repeat('a', 64), $output);
        $this->assertStringNotContainsString(str_repeat('b', 64), $output);
        Http::assertNothingSent();
        $this->assertDatabaseCount('csms_inbox_events', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_approved_local_charging_graphql_service_is_configuration_ready_without_source_access(): void
    {
        config(['csms.graphql_url' => 'http://charging-graphql:8080/v1/graphql']);
        $report = app(CsmsPreflight::class)->report();
        $this->assertTrue($report['software_configuration_ready']);
        $this->assertTrue($report['checks']['source_endpoint_format']);
        $this->assertFalse($report['source_connected']);
        $this->assertFalse($report['charger_hardware_verified']);
        $this->assertFalse($report['external_pilot_approved']);
        $this->assertFalse($report['production_ready']);
        $this->assertSame(0, Artisan::call('csms:preflight'));
        $this->assertStringNotContainsString('charging-graphql:8080', Artisan::output());
        $this->assertStringNotContainsString(str_repeat('a', 64), Artisan::output());
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_missing_or_reused_keys_and_incorrect_adapter_settings_are_blocked(): void
    {
        foreach ([
            ['csms.graphql_secret' => 'PRIVATE-SOURCE-SECRET'],
            ['csms.event_secret' => str_repeat('a', 64)],
            ['csms.session_bridge_enabled' => false],
            ['csms.simulator_enabled' => false],
            ['csms.graphql_url' => 'http://source.example/graphql?admin_secret=PRIVATE-KEY'],
            ['csms.graphql_url' => 'http://operator:PRIVATE-PASSWORD@source.example/graphql'],
            ['csms.graphql_url' => 'javascript:PRIVATE-SECRET'],
            ['csms.graphql_url' => 'http://source.example/graphql'],
            ['csms.graphql_url' => 'http://charging-graphql.evil.test/graphql'],
            ['csms.graphql_url' => 'http://unreviewed-graphql:8080/v1/graphql'],
            ['csms.graphql_url' => 'http://charging-graphql:8080/v1/graphql?admin_secret=PRIVATE-KEY'],
            ['csms.graphql_url' => 'http://charging-graphql:8080/unreviewed'],
            ['csms.graphql_url' => 'http://charging-graphql:8080/v1/graphql/'],
            ['csms.graphql_url' => 'https://source.example.com/unreviewed'],
            ['csms.graphql_url' => 'https://source.example.com\unexpected/v1/graphql'],
            ['csms.graphql_url' => "https://source.example.com\t/v1/graphql"],
            ['csms.graphql_url' => 'https://source.example.com:0/v1/graphql'],
            ['csms.graphql_url' => 'https://source%2eexample.com/v1/graphql'],
        ] as $changes) {
            $original = [];
            foreach ($changes as $key => $value) {
                $original[$key] = config($key);
            }
            config($changes);
            $this->assertSame(1, Artisan::call('csms:preflight'));
            $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
            $this->assertFalse($report['software_configuration_ready']);
            $this->assertSame('blocked', $report['status']);
            $this->assertFalse($report['production_ready']);
            $this->assertFalse($report['device_commands_sent']);
            $this->assertStringNotContainsString('PRIVATE-', Artisan::output());
            config($original);
        }
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('csms_inbox_events')->count());
        $this->assertSame(0, DB::table('finance_invoices')->count());
    }
}
