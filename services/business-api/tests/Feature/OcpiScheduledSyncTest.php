<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\ScheduledSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OcpiScheduledSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $key = 'polling-'.$owner->id;
        config(['ocpi.scheduled_sync_enabled' => true, 'ocpi.connections.'.$key => ['origin' => 'https://partner.example', 'authorization_token' => base64_encode('synthetic-read-only'), 'party_country' => 'AE', 'party_id' => 'ABC', 'party_role' => 'CPO']]);
        $provider = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Polling fixture', 'connection_key' => $key, 'authorized' => true, 'version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations', 'tariffs' => 'https://partner.example/tariffs', 'sessions' => 'https://partner.example/sessions', 'cdrs' => 'https://partner.example/cdrs']]);

        return [$owner, $provider, '/api/ocpi/providers/'.$provider->id.'/sync-plans'];
    }

    private function enable(User $owner, string $url): void
    {
        $this->actingAs($owner)->putJson($url.'/locations', ['enabled' => true, 'confirmed' => true, 'interval_minutes' => 15, 'expected_revision' => '0'])->assertOk()->assertJsonPath('consent_current', true);
    }

    private function response(): array
    {
        return ['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => [['id' => 'LOC-1', 'country_code' => 'AE', 'party_id' => 'ABC', 'publish' => true, 'name' => 'Partner reported fixture', 'address' => 'Synthetic', 'city' => 'Dubai', 'country' => 'ARE', 'coordinates' => ['latitude' => '25.1', 'longitude' => '55.1'], 'time_zone' => 'Asia/Dubai', 'last_updated' => now()->subMinute()->toISOString(), 'evses' => []]]];
    }

    public function test_explicit_plan_polls_canonical_records_once_per_interval_and_exposes_only_private_receipts(): void
    {
        [$owner, $provider, $url] = $this->fixture();
        $this->actingAs($owner)->putJson($url.'/locations', ['enabled' => true, 'interval_minutes' => 15, 'expected_revision' => '0'])->assertUnprocessable();
        $this->enable($owner, $url);
        Http::fake(['https://partner.example/*' => Http::response($this->response())]);
        $service = app(ScheduledSync::class);
        $this->assertSame('completed', $service->run()['state']);
        $this->assertSame('idle', $service->run()['state']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 1);
        $this->assertDatabaseCount('ocpi_sync_runs', 1);
        $this->getJson($url)->assertOk()->assertJsonPath('history.data.0.state', 'completed')->assertJsonPath('read_only_polling', true)->assertJsonPath('billing_authority', false)->assertDontSee('configuration_hash')->assertDontSee('synthetic-read-only');
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_opt_out_during_pull_fences_ingress_and_never_changes_charging_capacity(): void
    {
        [$owner, $provider, $url] = $this->fixture();
        $this->enable($owner, $url);
        Http::fake(function () use ($owner, $provider) {
            app(ScheduledSync::class)->update($owner, $provider, 'locations', false, 15, 0);

            return Http::response($this->response());
        });
        $this->assertSame('cancelled', app(ScheduledSync::class)->run()['state']);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_owner_revocation_or_changed_credentials_invalidate_persisted_consent_before_request(): void
    {
        foreach (['epoch', 'credentials', 'authorization'] as $change) {
            [$owner, $provider, $url] = $this->fixture();
            $this->enable($owner, $url);
            if ($change === 'epoch') {
                DB::table('users')->where('id', $owner->id)->increment('auth_epoch');
            }
            if ($change === 'credentials') {
                config(['ocpi.connections.'.$provider->connection_key.'.authorization_token' => base64_encode('changed-credential')]);
            }
            if ($change === 'authorization') {
                $provider->update(['authorized' => false]);
            }
            $this->assertSame('idle', app(ScheduledSync::class)->run()['state']);
            $this->assertFalse((bool) DB::table('ocpi_sync_plans')->where('provider_id', $provider->id)->value('enabled'));
        }
    }

    public function test_failure_waits_until_next_interval_and_does_not_expose_provider_errors(): void
    {
        [$owner, , $url] = $this->fixture();
        $this->enable($owner, $url);
        Http::fake(['*' => Http::response(['PRIVATE PROVIDER SECRET'], 503)]);
        $this->assertSame('failed', app(ScheduledSync::class)->run()['state']);
        $this->assertSame('idle', app(ScheduledSync::class)->run()['state']);
        $this->getJson($url)->assertOk()->assertJsonPath('history.data.0.result_code', 'poll_requires_review')->assertDontSee('PRIVATE PROVIDER SECRET');
    }

    public function test_other_owner_and_admin_bearer_cannot_read_or_change_plans_and_default_off_does_no_io(): void
    {
        [$owner, $provider, $url] = $this->fixture();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($url)->assertNotFound();
        config(['ocpi.scheduled_sync_enabled' => false]);
        $this->assertSame(['state' => 'disabled'], app(ScheduledSync::class)->run());
        $this->app['auth']->forgetGuards();
        $token = User::factory()->create(['role' => 'admin'])->createToken('forbidden')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/ocpi/providers/'.$provider->id.'/sync-plans')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_actual_vendor_bearer_api_preserves_private_plan_contract_and_exact_retries(): void
    {
        [$owner, $provider] = $this->fixture();
        $url = '/api/v1/ocpi/providers/'.$provider->id.'/sync-plans';
        $this->app['auth']->forgetGuards();
        $this->withToken($owner->createToken('polling-plans')->plainTextToken)->getJson($url)->assertOk()->assertJsonPath('plans.0.revision', '0');
        $body = ['enabled' => true, 'confirmed' => true, 'interval_minutes' => 15, 'expected_revision' => '0'];
        $this->putJson($url.'/locations', $body)->assertOk()->assertJsonPath('revision', '1');
        $this->putJson($url.'/locations', $body)->assertOk()->assertJsonPath('revision', '1');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'ocpi.polling_enabled')->count());
        $this->putJson($url.'/locations', array_replace($body, ['interval_minutes' => 30]))->assertConflict();
        Http::assertNothingSent();
    }

    public function test_interrupted_claim_stops_plan_until_explicit_operator_renewal(): void
    {
        [$owner, $provider, $url] = $this->fixture();
        $this->enable($owner, $url);
        $plan = DB::table('ocpi_sync_plans')->first();
        $id = (string) Str::uuid();
        DB::table('ocpi_sync_runs')->insert(['id' => $id, 'plan_id' => $plan->id, 'plan_revision' => 1, 'state' => 'claimed', 'started_at' => now()->subMinutes(31)]);
        DB::table('ocpi_sync_plans')->where('id', $plan->id)->update(['active_run' => $id, 'claimed_at' => now()->subMinutes(31)]);
        $this->assertSame('idle', app(ScheduledSync::class)->run()['state']);
        $this->getJson($url)->assertOk()->assertJsonPath('plans.0.enabled', false)->assertJsonPath('plans.0.stopped_reason', 'interrupted_poll')->assertJsonPath('history.data.0.state', 'interrupted');
        Http::assertNothingSent();
        $this->putJson($url.'/locations', ['enabled' => true, 'confirmed' => true, 'interval_minutes' => 15, 'expected_revision' => '1'])->assertOk()->assertJsonPath('revision', '2');
        Http::fake(['*' => Http::response($this->response())]);
        $result = app(ScheduledSync::class)->run();
        $this->assertSame('completed', $result['state']);
        $this->assertNotSame($id, $result['run_id']);
        $this->assertSame('interrupted', DB::table('ocpi_sync_runs')->where('id', $id)->value('state'));
        Http::assertSentCount(1);
    }
}
