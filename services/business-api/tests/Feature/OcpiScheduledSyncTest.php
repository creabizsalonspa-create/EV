<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\ScheduledSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_private_catalog_age_policy_counts_old_and_future_reports_without_granting_authority(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15T12:00:00Z'));
        config(['partner_catalog.max_age_seconds' => 86400]);
        [$owner, $provider, $url] = $this->fixture();
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('catalog_health.state', 'empty')->assertJsonPath('catalog_health.published_count', 0);
        foreach ([[-30, -20, true], [-1500, 0, true], [-5, -1500, true], [1, 0, true], [0, 1, true], [-1440, 0, true], [-1500, 0, false]] as $n => [$source, $receipt, $published]) {
            OcpiRecord::create(['ocpi_provider_id' => $provider->id, 'module' => 'locations', 'country_code' => 'AE', 'party_id' => 'ABC', 'external_id' => 'health-'.$n, 'payload' => ['private' => 'DO-NOT-REPORT-CATALOG-PAYLOAD'], 'content_hash' => str_repeat('a', 64), 'normalized' => ['publish' => $published, 'name' => 'DO-NOT-REPORT-LOCATION'], 'source_updated_at' => now()->addMinutes($source), 'received_at' => now()->addMinutes($receipt)]);
        }
        $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('catalog_health.state', 'attention')->assertJsonPath('catalog_health.published_count', 6)
            ->assertJsonPath('catalog_health.within_policy_count', 1)->assertJsonPath('catalog_health.stale_count', 3)
            ->assertJsonPath('catalog_health.unknown_age_count', 2)->assertJsonPath('catalog_health.future_clock_count', 2)
            ->assertJsonPath('catalog_health.checked_at', '2026-11-15T12:00:00.000000Z')
            ->assertJsonPath('catalog_health.physical_availability_verified', false)->assertJsonPath('catalog_health.remote_control', false)
            ->assertJsonPath('catalog_health.billing_authority', false)->assertDontSee('DO-NOT-REPORT');
        $recent = OcpiRecord::where('ocpi_provider_id', $provider->id)->where('external_id', 'health-0')->firstOrFail();
        $recent->update(['source_updated_at' => now()->addMicrosecond()]);
        $this->getJson($url)->assertOk()->assertJsonPath('catalog_health.within_policy_count', 0)
            ->assertJsonPath('catalog_health.unknown_age_count', 3)->assertJsonPath('catalog_health.future_clock_count', 3);
        $recent->update(['source_updated_at' => now()->subMinutes(30)]);
        if (DB::getDriverName() === 'sqlite') {
            DB::table('ocpi_records')->where('ocpi_provider_id', $provider->id)->where('external_id', 'health-0')->update(['source_updated_at' => 'malformed-legacy-time']);
            $this->getJson($url)->assertOk()->assertJsonPath('catalog_health.within_policy_count', 0)
                ->assertJsonPath('catalog_health.unknown_age_count', 3)->assertJsonPath('catalog_health.future_clock_count', 2);
        }
        foreach ([0, 'invalid', '86400.5', 604801, 86400.0, true] as $invalid) {
            config(['partner_catalog.max_age_seconds' => $invalid]);
            $this->getJson($url)->assertOk()->assertJsonPath('catalog_health.state', 'policy_unavailable')
                ->assertJsonPath('catalog_health.published_count', null)->assertJsonPath('catalog_health.max_age_seconds', null);
        }
        config(['partner_catalog.max_age_seconds' => 86400]);
        $provider->update(['authorized' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('catalog_health.state', 'unavailable')->assertJsonPath('catalog_health.published_count', null);
        $other = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($other)->getJson($url)->assertNotFound();
        Http::assertNothingSent();
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

    public function test_opt_out_after_first_page_prevents_another_provider_request(): void
    {
        [$owner, $provider, $url] = $this->fixture();
        $this->enable($owner, $url);
        Http::fake(function () use ($owner, $provider) {
            app(ScheduledSync::class)->update($owner, $provider, 'locations', false, 15, 0);

            return Http::response($this->response(), 200, ['Link' => '<https://partner.example/locations?page=2>; rel="next"']);
        });
        $this->assertSame('cancelled', app(ScheduledSync::class)->run()['state']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->getJson($url)->assertOk()->assertJsonPath('history.data.0.state', 'cancelled')
            ->assertJsonPath('plans.0.enabled', false)->assertJsonPath('plans.0.running', false);
    }

    public function test_global_polling_disable_during_ingestion_rolls_back_source_batch(): void
    {
        [$owner, $provider, $url] = $this->fixture();
        $this->enable($owner, $url);
        Http::fake(fn () => Http::response($this->response()));
        $changed = false;
        DB::listen(function ($query) use (&$changed) {
            if (! $changed && str_starts_with($query->sql, 'insert into "ocpi_records"')) {
                $changed = true;
                config(['ocpi.scheduled_sync_enabled' => false]);
            }
        });
        $this->assertSame('cancelled', app(ScheduledSync::class)->run()['state']);
        $this->assertTrue($changed);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertNull($provider->fresh()->last_synced_at);
        $this->assertSame('cancelled', DB::table('ocpi_sync_runs')->value('state'));
        $this->assertNull(DB::table('ocpi_sync_plans')->value('active_run'));
        $this->assertSame(['state' => 'disabled'], app(ScheduledSync::class)->run());
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

    public function test_same_epoch_password_and_configured_party_changes_require_explicit_polling_renewal(): void
    {
        foreach (['password', 'party', 'legacy_digest'] as $change) {
            [$owner, $provider, $url] = $this->fixture();
            $this->enable($owner, $url);
            if ($change === 'password') {
                DB::table('users')->where('id', $owner->id)->update(['password' => 'synthetic-changed-password-hash']);
            } elseif ($change === 'party') {
                config(['ocpi.connections.'.$provider->connection_key.'.party_id' => 'XYZ']);
            } else {
                DB::table('ocpi_sync_plans')->where('provider_id', $provider->id)->update(['configuration_hash' => str_repeat('a', 64)]);
            }
            $service = app(ScheduledSync::class);
            $this->assertSame('idle', $service->run()['state']);
            $plan = DB::table('ocpi_sync_plans')->where('provider_id', $provider->id)->first();
            $this->assertFalse((bool) $plan->enabled);
            $this->assertSame('consent_or_partner_changed', $plan->stopped_reason);
            $review = $service->update($owner->fresh(), $provider->fresh(), 'locations', true, 15, (int) $plan->revision);
            $this->assertTrue($review['consent_current']);
            $this->assertSame('2', $review['revision']);
            $service->update($owner->fresh(), $provider->fresh(), 'locations', false, 15, 2);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_sync_runs', 0);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_same_revision_plan_scope_change_cancels_claim_without_accepting_source(): void
    {
        [$owner, $provider, $url] = $this->fixture();
        $this->enable($owner, $url);
        Http::fake(function () use ($provider) {
            DB::table('ocpi_sync_plans')->where('provider_id', $provider->id)
                ->where('module', 'locations')->update(['interval_minutes' => 30]);

            return Http::response($this->response(), 200, ['Link' => '<https://partner.example/locations?page=2>; rel="next"']);
        });
        $this->assertSame('cancelled', app(ScheduledSync::class)->run()['state']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseHas('ocpi_sync_runs', ['state' => 'cancelled', 'result_code' => 'consent_or_partner_changed']);
        $this->assertNull(DB::table('ocpi_sync_plans')->where('provider_id', $provider->id)->value('active_run'));
        $this->getJson($url)->assertOk()->assertJsonPath('plans.0.consent_current', false);
        $this->travel(31)->minutes();
        $this->assertSame('idle', app(ScheduledSync::class)->run()['state']);
        Http::assertSentCount(1);
        $this->assertFalse((bool) DB::table('ocpi_sync_plans')->where('provider_id', $provider->id)->value('enabled'));
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
