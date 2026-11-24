<?php

namespace Tests\Feature;

use App\Http\Controllers\ChargerCommandController;
use App\Models\ChargerCommand;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\CsmsDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ChargerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulator_charger_binding_rejects_cross_company_and_foreign_owner_topology(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $foreign = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $station = $this->station($owner);
        $company = VendorCompany::create([
            'owner_id' => $foreign->id, 'legal_name' => 'Synthetic foreign operator',
            'country' => 'AE', 'trade_license_number' => 'TEST-SIMULATOR-ONLY',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'approved',
        ]);
        $site = VendorSite::create([
            'company_id' => $company->id, 'station_id' => $station->id,
            'name' => 'Synthetic linked site', 'country' => 'AE', 'timezone' => 'Asia/Dubai',
            'address' => 'Local fixture', 'latitude' => 25, 'longitude' => 55,
        ]);
        $charger = VendorCharger::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'asset_reference' => 'cross-owner-source', 'manufacturer' => 'Simulator',
            'model' => 'No physical charger', 'ocpp_protocol' => '1.6',
            'source' => 'simulator',
        ]);
        $input = ['identifier' => 'ev-test-cross-owner-lineage',
            'protocol' => '1.6', 'vendor_charger_id' => $charger->id];
        $this->actingAs($admin)->postJson('/api/stations/'.$station->id.'/csms-binding', $input)
            ->assertConflict();
        $this->assertDatabaseCount('csms_bindings', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        Http::assertNothingSent();

        // An aligned owner is necessary but not sufficient: cross-country
        // source inventory must remain ineligible for this station.
        $company->update(['owner_id' => $owner->id]);
        $site->update(['country' => 'US']);
        $this->postJson('/api/stations/'.$station->id.'/csms-binding', $input)
            ->assertConflict();
        $this->assertDatabaseCount('csms_bindings', 0);
        $site->update(['country' => 'AE']);
        $this->postJson('/api/stations/'.$station->id.'/csms-binding', $input)
            ->assertCreated();
        $this->assertDatabaseHas('csms_bindings', [
            'station_id' => $station->id, 'vendor_charger_id' => $charger->id,
            'identifier' => 'ev-test-cross-owner-lineage',
        ]);
        $this->assertDatabaseCount('csms_bindings', 1);
        Http::assertNothingSent();
    }

    public function test_changed_canonical_station_source_blocks_both_pending_start_and_stop_without_transport(): void
    {
        config(['csms.simulator_enabled' => true]);
        Http::preventStrayRequests();
        $vendor = User::factory()->create(['role' => 'vendor']);
        foreach (['start', 'stop'] as $action) {
            $station = $this->station($vendor);
            DB::table('csms_bindings')->insert([
                'station_id' => $station->id,
                'identifier' => 'ev-test-source-change-'.$action,
                'protocol' => '1.6',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $command = ChargerCommand::create([
                'consent_auth_epoch' => $action === 'start' ? (int) $vendor->auth_epoch : null,
                'actor_id' => $vendor->id, 'station_id' => $station->id,
                'action' => $action, 'idempotency_key' => 'source-change-'.$action,
                'payload_hash' => str_repeat('a', 64),
                'payload' => $action === 'stop' ? ['transaction_id' => '123'] : [],
            ]);
            DB::table('stations')->where('id', $station->id)->update(['source' => 'partner']);
            $result = app(CsmsDispatcher::class)->dispatch($command->id);
            $this->assertSame('pending', $result->status);
            $this->assertSame('pre_dispatch_source_changed', $result->failure_code);
            $this->assertDatabaseHas('charger_commands', [
                'id' => $command->id, 'status' => 'pending',
                'failure_code' => 'pre_dispatch_source_changed',
            ]);
            Http::assertNothingSent();
        }
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    private function station(User $vendor): Station
    {
        return Station::create(['vendor_id' => $vendor->id, 'name' => 'CSMS simulation', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    }

    private function revokeSnapshot(User $actor, string $change): void
    {
        DB::table('users')->where('id', $actor->id)->update(match ($change) {
            'role' => ['role' => 'customer'],
            'status' => ['account_status' => 'suspended'],
            'password' => ['password' => Hash::make('synthetic-revoked-credential')],
            'epoch' => ['auth_epoch' => $actor->auth_epoch + 1],
        });
    }

    #[TestWith(['vendor', 'role'])]
    #[TestWith(['vendor', 'status'])]
    #[TestWith(['vendor', 'password'])]
    #[TestWith(['vendor', 'epoch'])]
    #[TestWith(['admin', 'role'])]
    #[TestWith(['admin', 'status'])]
    #[TestWith(['admin', 'password'])]
    #[TestWith(['admin', 'epoch'])]
    public function test_retained_actor_snapshot_cannot_read_private_command_history(string $role, string $change): void
    {
        Http::preventStrayRequests();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $actor = $role === 'vendor' ? $vendor : User::factory()->create(['role' => 'admin']);
        $station = $this->station($vendor);
        $command = ChargerCommand::create(['actor_id' => $vendor->id, 'station_id' => $station->id,
            'action' => 'start', 'idempotency_key' => 'retained-reader', 'payload_hash' => str_repeat('a', 64),
            'payload' => ['id_token' => 'PRIVATE-SYNTHETIC-REMOTE-TOKEN']]);
        $this->actingAs($actor)->getJson('/api/charger-commands')->assertOk()->assertJsonPath('data.0.id', $command->id);
        $snapshot = clone $actor;
        $request = Request::create('/api/charger-commands', 'GET');
        $request->setUserResolver(static fn () => $snapshot);
        $before = DB::table('audit_events')->count();
        $this->revokeSnapshot($actor, $change);
        try {
            app(ChargerCommandController::class)->index($request);
            $this->fail('A retained revoked actor read private charger commands.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('PRIVATE-SYNTHETIC-REMOTE-TOKEN', $command->fresh()->payload['id_token']);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame($before, DB::table('audit_events')->count());
        Http::assertNothingSent();
    }

    #[TestWith(['role'])]
    #[TestWith(['status'])]
    #[TestWith(['password'])]
    #[TestWith(['epoch'])]
    public function test_retained_admin_snapshot_cannot_provision_a_new_source_binding(string $change): void
    {
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => 'admin']);
        $station = $this->station(User::factory()->create(['role' => 'vendor']));
        $snapshot = clone $admin;
        $request = Request::create('/api/stations/'.$station->id.'/csms-binding', 'POST', ['identifier' => 'ev-test-revoked-admin', 'protocol' => '2.0.1']);
        $request->setUserResolver(static fn () => $snapshot);
        $before = DB::table('audit_events')->count();
        $this->revokeSnapshot($admin, $change);
        try {
            app(ChargerCommandController::class)->bind($request, $station);
            $this->fail('A retained revoked Admin provisioned a charger source binding.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('csms_bindings', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame($before, DB::table('audit_events')->count());
        Http::assertNothingSent();
    }

    #[TestWith(['role'])]
    #[TestWith(['status'])]
    #[TestWith(['password'])]
    #[TestWith(['epoch'])]
    public function test_idempotent_command_replay_rechecks_the_current_actor(string $change): void
    {
        Http::preventStrayRequests();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-replay-actor', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $values = ['action' => 'start', 'idempotency_key' => 'original-queued-command'];
        $id = $this->actingAs($vendor)->postJson('/api/stations/'.$station->id.'/commands', $values)->assertAccepted()->json('id');
        $snapshot = clone $vendor;
        $request = Request::create('/api/stations/'.$station->id.'/commands', 'POST', $values);
        $request->setUserResolver(static fn () => $snapshot);
        $before = DB::table('audit_events')->count();
        $this->revokeSnapshot($vendor, $change);
        try {
            app(ChargerCommandController::class)->create($request, $station);
            $this->fail('A revoked actor replayed a private command response.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseHas('charger_commands', ['id' => $id, 'status' => 'pending']);
        $this->assertSame($before, DB::table('audit_events')->count());
        Http::assertNothingSent();
    }

    #[TestWith(['owner', 404])]
    #[TestWith(['source', 409])]
    public function test_cached_station_cannot_admit_a_command_after_inventory_changes(string $change, int $status): void
    {
        Http::preventStrayRequests();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-stale-station', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stations')->where('id', $station->id)->update($change === 'owner'
            ? ['vendor_id' => User::factory()->create(['role' => 'vendor'])->id]
            : ['source' => 'partner']);
        $request = Request::create('/api/stations/'.$station->id.'/commands', 'POST', ['action' => 'start', 'idempotency_key' => 'stale-route-station']);
        $request->setUserResolver(static fn () => $vendor);
        $before = DB::table('audit_events')->count();
        try {
            app(ChargerCommandController::class)->create($request, $station);
            $this->fail('A stale route-bound station admitted a new command.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame($before, DB::table('audit_events')->count());
        Http::assertNothingSent();
    }

    public function test_only_admin_can_bind_and_only_owning_vendor_can_queue_idempotent_commands(): void
    {
        $v = User::factory()->create(['role' => 'vendor']);
        $s = $this->station($v);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($v)->postJson('/api/stations/'.$s->id.'/csms-binding', ['identifier' => 'ev-test-case', 'protocol' => '1.6'])->assertForbidden();
        $this->actingAs($admin)->postJson('/api/stations/'.$s->id.'/csms-binding', ['identifier' => 'physical-charger', 'protocol' => '1.6'])->assertUnprocessable();
        $this->postJson('/api/stations/'.$s->id.'/csms-binding', ['identifier' => 'ev-test-case', 'protocol' => '1.6'])->assertCreated();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson('/api/stations/'.$s->id.'/commands', ['action' => 'start', 'idempotency_key' => 'start-1'])->assertNotFound();
        $this->actingAs($v)->postJson('/api/stations/'.$s->id.'/commands', ['action' => 'start', 'idempotency_key' => 'start-1'])->assertAccepted()->assertJsonPath('status', 'pending');
        $this->postJson('/api/stations/'.$s->id.'/commands', ['action' => 'start', 'idempotency_key' => 'start-1'])->assertOk();
        $this->postJson('/api/stations/'.$s->id.'/commands', ['action' => 'stop', 'transaction_id' => '1', 'idempotency_key' => 'start-1'])->assertConflict();
        $this->assertDatabaseCount('charger_commands', 1);
    }

    public function test_vendor_command_idempotency_is_actor_scoped_and_rejects_a_cross_station_key_reuse(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $a = $this->station($vendor);
        $b = $this->station($vendor);
        foreach ([$a, $b] as $station) {
            DB::table('csms_bindings')->insert([
                'station_id' => $station->id,
                'identifier' => 'ev-test-idempotency-'.$station->id,
                'protocol' => '1.6',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs($vendor);
        $first = $this->postJson('/api/stations/'.$a->id.'/commands', [
            'action' => 'start', 'idempotency_key' => 'same-actor-same-key',
        ])->assertAccepted()->json('id');
        $this->postJson('/api/stations/'.$a->id.'/commands', [
            'action' => 'start', 'idempotency_key' => 'same-actor-same-key',
        ])->assertOk()->assertJsonPath('id', $first);
        $this->postJson('/api/stations/'.$b->id.'/commands', [
            'action' => 'start', 'idempotency_key' => 'same-actor-same-key',
        ])->assertConflict();
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseHas('charger_commands', [
            'id' => $first, 'actor_id' => $vendor->id, 'station_id' => $a->id,
        ]);
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_vendor_command_history_redacts_customer_tokens_without_corrupting_dispatch_input(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $token = 'EV-1234567890ABCDEF';
        $sourceSecret = 'PRIVATE-RESPONSE-ACCESS-TOKEN';
        $command = ChargerCommand::create([
            'actor_id' => $vendor->id, 'station_id' => $station->id,
            'action' => 'start', 'idempotency_key' => 'redact-start',
            'payload_hash' => str_repeat('a', 64),
            'payload' => ['session_id' => 18, 'id_token' => $token],
            'confirmation' => [
                'success' => true,
                'nested' => ['accessToken' => $sourceSecret, 'token' => $sourceSecret],
            ],
        ]);
        $this->actingAs($vendor)->getJson('/api/charger-commands')
            ->assertOk()->assertJsonPath('data.0.id', $command->id)
            ->assertJsonPath('data.0.payload.session_id', 18)
            ->assertJsonPath('data.0.payload.id_token', '[REDACTED]')
            ->assertJsonPath('data.0.confirmation.nested.accessToken', '[REDACTED]')
            ->assertJsonPath('data.0.confirmation.nested.token', '[REDACTED]')
            ->assertJsonPath('data.0.confirmation.success', true);
        $serialized = json_encode($command->fresh(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($token, $serialized);
        $this->assertStringNotContainsString($sourceSecret, $serialized);
        $this->assertSame($token, $command->fresh()->payload['id_token']);
        $this->assertSame($sourceSecret, $command->fresh()->confirmation['nested']['accessToken']);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_role_demoted_vendor_cannot_read_charging_command_history(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $command = ChargerCommand::create([
            'actor_id' => $vendor->id, 'station_id' => $station->id,
            'action' => 'start', 'idempotency_key' => 'history-before-demotion',
            'payload_hash' => str_repeat('f', 64),
            'payload' => ['id_token' => 'EV-DEADBEEF01234567'],
        ]);
        $this->actingAs($vendor)->getJson('/api/charger-commands')
            ->assertOk()->assertJsonPath('data.0.id', $command->id)
            ->assertJsonPath('data.0.payload.id_token', '[REDACTED]');
        DB::table('users')->where('id', $vendor->id)->update([
            'role' => 'customer', 'auth_epoch' => $vendor->auth_epoch + 1,
        ]);
        // Changing the authorization epoch revokes the existing token
        // before endpoint RBAC is reached: 401 is the intended stronger gate.
        $this->actingAs($vendor->fresh())->getJson('/api/charger-commands')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/charger-commands')->assertOk()
            ->assertJsonPath('data.0.id', $command->id);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_legacy_station_wide_stop_cannot_cross_a_current_operator_transfer(): void
    {
        config(['csms.simulator_enabled' => true]);
        Http::preventStrayRequests();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        DB::table('csms_bindings')->insert([
            'station_id' => $station->id, 'identifier' => 'ev-test-legacy-stop-transfer',
            'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $stop = ChargerCommand::create([
            'actor_id' => $vendor->id, 'station_id' => $station->id,
            'action' => 'stop', 'idempotency_key' => 'before-transfer',
            'payload_hash' => str_repeat('c', 64),
            'payload' => ['transaction_id' => '123'],
        ]);
        $station->update(['vendor_id' => User::factory()->create(['role' => 'vendor'])->id]);
        Http::fake();
        $result = app(CsmsDispatcher::class)->dispatch($stop->id);
        $this->assertSame('pending', $result->status);
        $this->assertSame('pre_dispatch_source_lineage_changed', $result->failure_code);
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_repointing_a_pending_owned_charger_source_blocks_dispatch_to_another_operator(): void
    {
        config(['csms.simulator_enabled' => true]);
        Http::preventStrayRequests();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $company = VendorCompany::create([
            'owner_id' => $vendor->id, 'legal_name' => 'Source-bound operator',
            'country' => 'AE', 'trade_license_number' => 'SYNTHETIC',
            'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved',
        ]);
        $site = VendorSite::create([
            'company_id' => $company->id, 'station_id' => $station->id,
            'name' => 'Bound site', 'country' => 'AE', 'timezone' => 'Asia/Dubai',
            'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55,
        ]);
        $charger = VendorCharger::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'asset_reference' => 'SOURCE-BOUND', 'manufacturer' => 'Synthetic',
            'model' => 'Bound', 'source' => 'simulator', 'ocpp_protocol' => '1.6',
        ]);
        $binding = DB::table('csms_bindings')->insertGetId([
            'station_id' => $station->id, 'vendor_charger_id' => $charger->id,
            'identifier' => 'ev-test-lineage-control', 'protocol' => '1.6',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake([
            '*/dev/token' => Http::response(['accessToken' => 'synthetic-token']),
            '*/ocpp/*' => Http::response([['success' => true]]),
        ]);
        $safe = ChargerCommand::create([
            'actor_id' => $vendor->id, 'station_id' => $station->id,
            'action' => 'stop', 'idempotency_key' => 'lineage-safe',
            'payload_hash' => str_repeat('a', 64), 'payload' => ['transaction_id' => '123'],
        ]);
        $this->assertSame('submitted', app(CsmsDispatcher::class)->dispatch($safe->id)->status);
        Http::assertSentCount(2);

        $foreign = User::factory()->create(['role' => 'vendor']);
        $otherCompany = VendorCompany::create([
            'owner_id' => $foreign->id, 'legal_name' => 'Unrelated source operator',
            'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-OTHER',
            'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved',
        ]);
        $otherSite = VendorSite::create([
            'company_id' => $otherCompany->id, 'name' => 'Unrelated site',
            'country' => 'AE', 'timezone' => 'Asia/Dubai',
            'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55,
        ]);
        $otherCharger = VendorCharger::create([
            'company_id' => $otherCompany->id, 'site_id' => $otherSite->id,
            'asset_reference' => 'FOREIGN-SOURCE', 'manufacturer' => 'Synthetic',
            'model' => 'Bound', 'source' => 'simulator', 'ocpp_protocol' => '1.6',
        ]);
        DB::table('csms_bindings')->where('id', $binding)
            ->update(['vendor_charger_id' => $otherCharger->id]);
        $blocked = ChargerCommand::create([
            'actor_id' => $vendor->id, 'station_id' => $station->id,
            'action' => 'stop', 'idempotency_key' => 'lineage-repointed',
            'payload_hash' => str_repeat('b', 64), 'payload' => ['transaction_id' => '123'],
        ]);
        $result = app(CsmsDispatcher::class)->dispatch($blocked->id);
        $this->assertSame('pending', $result->status);
        $this->assertSame('pre_dispatch_source_lineage_changed', $result->failure_code);
        app(CsmsDispatcher::class)->dispatch($blocked->id);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_source_binding_reassignment_during_token_fetch_does_not_send_a_charger_command(): void
    {
        config(['csms.simulator_enabled' => true]);
        Http::preventStrayRequests();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $binding = DB::table('csms_bindings')->insertGetId([
            'station_id' => $station->id, 'identifier' => 'ev-test-token-race-original',
            'protocol' => '2.0.1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $command = ChargerCommand::create([
            'actor_id' => $vendor->id, 'station_id' => $station->id,
            'consent_auth_epoch' => (int) $vendor->auth_epoch,
            'action' => 'start', 'idempotency_key' => 'token-race',
            'payload_hash' => str_repeat('d', 64), 'payload' => [],
        ]);
        Http::fake(function ($request) use ($binding) {
            if (str_ends_with($request->url(), '/dev/token')) {
                DB::table('csms_bindings')->where('id', $binding)->update([
                    'identifier' => 'ev-test-token-race-reassigned',
                ]);

                return Http::response(['accessToken' => 'synthetic-short-lived-token']);
            }

            return Http::response([['success' => true]]);
        });
        $blocked = app(CsmsDispatcher::class)->dispatch($command->id);
        $this->assertSame('pending', $blocked->status);
        $this->assertSame('pre_dispatch_source_lineage_changed', $blocked->failure_code);
        $this->assertSame('pending', $command->fresh()->status);
        $again = app(CsmsDispatcher::class)->dispatch($command->id);
        $this->assertSame('pending', $again->status);
        $this->assertSame('pre_dispatch_source_lineage_changed', $again->failure_code);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ocpp/'));
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('command_source_observations', 0);
    }

    #[TestWith(['1.6', 'epoch'])]
    #[TestWith(['2.0.1', 'epoch'])]
    #[TestWith(['1.6', 'origin'])]
    #[TestWith(['2.0.1', 'origin'])]
    #[TestWith(['1.6', 'disabled'])]
    #[TestWith(['2.0.1', 'disabled'])]
    public function test_authority_change_during_service_token_fetch_never_dispatches_or_retargets_start(string $protocol, string $change): void
    {
        config(['csms.simulator_enabled' => true]);
        $base = config('csms.base_url');
        Http::preventStrayRequests();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        DB::table('csms_bindings')->insert([
            'station_id' => $station->id, 'identifier' => 'ev-test-authority-race',
            'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $command = ChargerCommand::create([
            'actor_id' => $vendor->id, 'station_id' => $station->id,
            'consent_auth_epoch' => (int) $vendor->auth_epoch,
            'action' => 'start', 'idempotency_key' => 'authority-race',
            'payload_hash' => str_repeat('d', 64), 'payload' => [],
        ]);
        Http::fake(function ($request) use ($vendor, $change) {
            if (str_ends_with($request->url(), '/dev/token')) {
                match ($change) {
                    'epoch' => DB::table('users')->where('id', $vendor->id)->increment('auth_epoch'),
                    'origin' => config(['csms.base_url' => 'https://alternate.example.test']),
                    'disabled' => config(['csms.simulator_enabled' => false]),
                };

                return Http::response(['accessToken' => 'synthetic-short-lived-token']);
            }

            return Http::response([['success' => true]]);
        });
        $blocked = app(CsmsDispatcher::class)->dispatch($command->id);
        $this->assertSame('pending', $blocked->status);
        $this->assertSame('pre_dispatch_authority_changed', $blocked->failure_code);
        config(['csms.simulator_enabled' => true, 'csms.base_url' => $base]);
        $again = app(CsmsDispatcher::class)->dispatch($command->id);
        $this->assertSame('pending', $again->status);
        $this->assertSame('pre_dispatch_authority_changed', $again->failure_code);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ocpp/') || str_contains($request->url(), 'alternate.example.test'));
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('command_source_observations', 0);
    }

    public function test_http_submission_does_not_claim_charger_acceptance_and_is_not_replayed(): void
    {
        config(['csms.simulator_enabled' => true]);
        $v = User::factory()->create(['role' => 'vendor']);
        $s = $this->station($v);
        DB::table('csms_bindings')->insert(['station_id' => $s->id, 'identifier' => 'ev-test-dispatch', 'protocol' => '2.0.1', 'created_at' => now(), 'updated_at' => now()]);
        $c = ChargerCommand::create(['consent_auth_epoch' => 0, 'actor_id' => $v->id, 'station_id' => $s->id, 'action' => 'start', 'payload' => [], 'payload_hash' => str_repeat('a', 64), 'idempotency_key' => 'abc']);
        Http::fake(['*/dev/token' => Http::response(['accessToken' => 'test-only-token']), '*/ocpp/*' => Http::response([['success' => true, 'messageId' => 'sim-123']])]);
        $r = app(CsmsDispatcher::class)->dispatch($c->id);
        $this->assertSame('submitted', $r->status);
        $this->assertNotSame('accepted', $r->status);
        app(CsmsDispatcher::class)->dispatch($c->id);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/ocpp/2.0.1/evdriver/requestStartTransaction') && $r['evseId'] === 1 && $r->hasHeader('Authorization', 'Bearer test-only-token'));
    }

    public function test_timeout_requires_reconciliation_and_never_automatic_retry(): void
    {
        config(['csms.simulator_enabled' => true]);
        $v = User::factory()->create(['role' => 'vendor']);
        $s = $this->station($v);
        DB::table('csms_bindings')->insert(['station_id' => $s->id, 'identifier' => 'ev-test-timeout', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $c = ChargerCommand::create(['consent_auth_epoch' => 0, 'actor_id' => $v->id, 'station_id' => $s->id, 'action' => 'start', 'payload' => [], 'payload_hash' => str_repeat('b', 64), 'idempotency_key' => 'timeout']);
        Http::fake(['*/dev/token' => Http::response(['accessToken' => 'test-token']), '*/ocpp/*' => Http::failedConnection()]);
        $this->assertSame('outcome_unknown', app(CsmsDispatcher::class)->dispatch($c->id)->status);
        app(CsmsDispatcher::class)->dispatch($c->id);
        Http::assertSentCount(2);
    }

    public function test_negative_dispatch_confirmation_is_rejected_without_replay(): void
    {
        config(['csms.simulator_enabled' => true]);
        $v = User::factory()->create(['role' => 'vendor']);
        $s = $this->station($v);
        DB::table('csms_bindings')->insert(['station_id' => $s->id, 'identifier' => 'ev-test-offline', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $c = ChargerCommand::create(['consent_auth_epoch' => 0, 'actor_id' => $v->id, 'station_id' => $s->id, 'action' => 'start', 'payload' => [], 'payload_hash' => str_repeat('c', 64), 'idempotency_key' => 'offline']);
        Http::fake(['*/dev/token' => Http::response(['accessToken' => 'test-token']), '*/ocpp/*' => Http::response([['success' => false]])]);
        $this->assertSame('rejected', app(CsmsDispatcher::class)->dispatch($c->id)->status);
        app(CsmsDispatcher::class)->dispatch($c->id);
        Http::assertSentCount(2);
    }

    public function test_ocpp16_stop_rejects_noncanonical_source_transaction_id_without_dispatch(): void
    {
        config(['csms.simulator_enabled' => true]);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        DB::table('csms_bindings')->insert([
            'station_id' => $station->id, 'identifier' => 'ev-test-stop-range',
            'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs($vendor);
        foreach (['-1', '01', 'abc', '2.0', '2147483648'] as $n => $value) {
            $this->postJson('/api/stations/'.$station->id.'/commands', [
                'action' => 'stop', 'transaction_id' => $value,
                'idempotency_key' => 'bad-stop-'.$n,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('charger_commands', 0);
        foreach (['0', '2147483647'] as $n => $value) {
            $this->postJson('/api/stations/'.$station->id.'/commands', [
                'action' => 'stop', 'transaction_id' => $value,
                'idempotency_key' => 'good-stop-'.$n,
            ])->assertAccepted()->assertJsonPath('payload.transaction_id', $value);
        }
        Http::assertNothingSent();

        $bad = ChargerCommand::create([
            'actor_id' => $vendor->id, 'station_id' => $station->id,
            'action' => 'stop', 'payload' => ['transaction_id' => '2147483648'],
            'payload_hash' => str_repeat('f', 64),
            'idempotency_key' => 'legacy-overflow',
        ]);
        $blocked = app(CsmsDispatcher::class)->dispatch($bad->id);
        $this->assertSame('pending', $blocked->status);
        $this->assertSame('pre_dispatch_invalid_transaction_id', $blocked->failure_code);
        app(CsmsDispatcher::class)->dispatch($bad->id);
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_production_guard_rejects_dev_token_dispatch(): void
    {
        $this->expectException(HttpException::class);
        config(['csms.simulator_enabled' => false]);
        app(CsmsDispatcher::class)->dispatch(1);
    }
}
