<?php

namespace Tests\Feature;

use App\Jobs\Agent3SyntheticQueueRecoveryProbe as Probe;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class Agent3SyntheticQueueRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function makeSyntheticStore(): void
    {
        Schema::create('ev_agent3_recovery_probe', function (Blueprint $t) {
            $t->string('marker_key')->primary();
            $t->text('marker_value');
        });
        DB::table('ev_agent3_recovery_probe')->insert([
            'marker_key' => Probe::INTENT, 'marker_value' => Probe::TOKEN,
        ]);
        config([
            'queue.default' => 'database',
            'csms.command_worker_enabled' => false,
            'csms.simulator_enabled' => false,
            'csms.session_bridge_enabled' => false,
            'csms.fault_worker_enabled' => false,
        ]);
    }

    public function test_serialized_database_job_is_durable_and_executes_once_without_commands(): void
    {
        $this->makeSyntheticStore();
        Bus::dispatch(new Probe(Probe::TOKEN));
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseMissing('ev_agent3_recovery_probe', [
            'marker_key' => Probe::COMPLETED,
        ]);
        $this->artisan('queue:work database --once --stop-when-empty --tries=1 --timeout=10')
            ->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseHas('ev_agent3_recovery_probe', [
            'marker_key' => Probe::COMPLETED, 'marker_value' => Probe::TOKEN,
        ]);
        (new Probe(Probe::TOKEN))->handle();
        $this->assertSame(1, DB::table('ev_agent3_recovery_probe')
            ->where('marker_key', Probe::COMPLETED)->count());
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_invalid_token_and_missing_intent_fail_without_proof(): void
    {
        $this->makeSyntheticStore();
        foreach (['different-token', '', 'SYNTHETIC_AGENT3_RECOVERY_V2'] as $bad) {
            try {
                (new Probe($bad))->handle();
                $this->fail('Invalid recovery probe token was accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('disabled', $error->getMessage());
            }
        }
        DB::table('ev_agent3_recovery_probe')->where('marker_key', Probe::INTENT)->delete();
        $this->expectException(RuntimeException::class);
        (new Probe(Probe::TOKEN))->handle();
    }

    public function test_production_environment_refuses_synthetic_probe(): void
    {
        $this->makeSyntheticStore();
        $this->app->instance('env', 'production');
        try {
            (new Probe(Probe::TOKEN))->handle();
            $this->fail('The synthetic job ran in production.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('disabled', $error->getMessage());
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertDatabaseMissing('ev_agent3_recovery_probe', [
            'marker_key' => Probe::COMPLETED,
        ]);
    }

    public function test_command_worker_or_simulator_enabled_refuses_probe(): void
    {
        $this->makeSyntheticStore();
        foreach ([
            'csms.command_worker_enabled', 'csms.simulator_enabled',
            'csms.session_bridge_enabled', 'csms.fault_worker_enabled',
        ] as $flag) {
            config([$flag => true]);
            try {
                (new Probe(Probe::TOKEN))->handle();
                $this->fail('High-risk capability enabled during queue recovery.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('disabled', $error->getMessage());
            } finally {
                config([$flag => false]);
            }
        }
        $this->assertDatabaseMissing('ev_agent3_recovery_probe', [
            'marker_key' => Probe::COMPLETED,
        ]);
    }
}
