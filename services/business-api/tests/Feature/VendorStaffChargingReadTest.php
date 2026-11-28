<?php

namespace Tests\Feature;

use App\Http\Controllers\VendorStaffChargingReadController;
use App\Models\ChargerCommand;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VendorStaffChargingReadTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $sessions = 1): array
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create(['role' => 'customer', 'email_verified_at' => now()]);
        $driver = User::factory()->create(['name' => 'PRIVATE-DRIVER-IDENTITY']);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Session observation company', 'country' => 'AE', 'trade_license_number' => 'PRIVATE-LICENSE', 'license_expires_on' => now()->addYear(), 'status' => 'approved']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Owned observation station', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Observation site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $membership = VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'permissions' => ['inventory.read'], 'status' => 'active', 'accepted_at' => now()]);
        foreach (range(1, $sessions) as $i) {
            $reservation = Reservation::create(['user_id' => $driver->id, 'station_id' => $station->id, 'starts_at' => now()->subHours($i), 'idempotency_key' => 'staff-observation-'.$company->id.'-'.$i]);
            ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 1000 + $i, 'rate_minor_per_kwh' => 999, 'currency' => 'AED', 'total_minor' => 777, 'stopped_at' => now()]);
        }

        return compact('owner', 'staff', 'driver', 'company', 'station', 'site', 'membership');
    }

    private function url(VendorCompany $company): string
    {
        return '/api/vendor-ops/companies/'.$company->id.'/charging-sessions';
    }

    public function test_new_permission_requires_versioned_recipient_consent_without_promoting_customer_role(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['staff'])->getJson($this->url($f['company']))->assertNotFound();
        $this->actingAs($f['owner'])->patchJson('/api/vendor-ops/companies/'.$f['company']->id.'/staff/'.$f['membership']->id.'/permissions', ['expected_version' => 1, 'permissions' => ['inventory.read', 'charging.read']])->assertOk()->assertJsonPath('requires_acceptance', true);
        $this->actingAs($f['staff'])->getJson($this->url($f['company']))->assertNotFound();
        $accept = '/api/vendor-ops/staff-invitations/'.$f['membership']->id.'/accept';
        $this->postJson($accept, ['expected_version' => 1, 'consent' => true])->assertConflict();
        $this->postJson($accept, ['expected_version' => 2, 'consent' => true])->assertOk();
        $this->getJson($this->url($f['company']))->assertOk()->assertJsonPath('total', 1);
        $this->assertSame('customer', $f['staff']->fresh()->role);
    }

    public function test_delegated_view_is_private_read_only_and_excludes_customer_money_and_credentials(): void
    {
        Http::preventStrayRequests();
        $f = $this->fixture();
        $f['membership']->update(['permissions' => ['charging.read']]);
        $before = DB::table('charging_sessions')->get()->toJson();
        $reply = $this->actingAs($f['staff'])->getJson($this->url($f['company']))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('scope', 'company_recorded_charging_sessions')->assertJsonPath('company_id', $f['company']->id)
            ->assertJsonPath('customer_identity_fields_included', false)->assertJsonPath('financial_fields_included', false)
            ->assertJsonPath('control_actions_exposed', false)->assertJsonPath('physical_charging_verified', false)
            ->assertJsonPath('live_availability_verified', false)->assertJsonPath('data.0.energy_wh', 1001)
            ->assertJsonPath('data.0.evidence', 'SIMULATED');
        foreach (['PRIVATE-', 'user_id', 'reservation_id', 'vehicle_id', 'rate_minor', 'total_minor', 'currency', 'invoice', 'id_token', 'payload_hash'] as $private) {
            $this->assertStringNotContainsString($private, $reply->getContent());
        }
        $this->assertSame($before, DB::table('charging_sessions')->get()->toJson());
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        Http::assertNothingSent();
    }

    public function test_pagination_and_status_filters_remain_company_scoped_even_for_shared_owner(): void
    {
        $f = $this->fixture(27);
        $other = $this->fixture(2);
        $other['company']->update(['owner_id' => $f['owner']->id]);
        $other['station']->update(['vendor_id' => $f['owner']->id]);
        $this->actingAs($f['owner'])->getJson($this->url($f['company']))->assertOk()->assertJsonPath('total', 27)->assertJsonCount(25, 'data');
        $this->getJson($this->url($f['company']).'?page=2&status=stopped')->assertOk()->assertJsonPath('total', 27)->assertJsonCount(2, 'data');
        $filtered = $this->getJson($this->url($f['company']).'?status=stopped')->assertOk();
        $this->getJson($filtered->json('next_page_url'))->assertOk()->assertJsonPath('status_filter', 'stopped');
        $this->getJson($this->url($f['company']).'?status=charging')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($this->url($other['company']))->assertOk()->assertJsonPath('total', 2);
        $f['membership']->update(['permissions' => ['charging.read']]);
        $this->actingAs($f['staff'])->getJson($this->url($other['company']))->assertNotFound();
    }

    public function test_revoked_held_or_unverified_memberships_never_read_and_permission_does_not_expose_kyc(): void
    {
        $f = $this->fixture();
        $f['membership']->update(['permissions' => ['charging.read']]);
        $this->actingAs($f['staff'])->getJson('/api/vendor-ops/companies/'.$f['company']->id)->assertNotFound();
        $this->getJson('/api/charging-operations')->assertForbidden();
        foreach ([['status' => 'revoked'], ['status' => 'invited'], ['status' => 'active', 'admin_revoked_at' => now()]] as $change) {
            $f['membership']->update($change);
            $this->getJson($this->url($f['company']))->assertNotFound();
        }
        $f['membership']->update(['status' => 'active', 'admin_revoked_at' => null]);
        $f['staff']->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($f['staff']->fresh())->getJson($this->url($f['company']))->assertNotFound();
    }

    #[TestWith(['role'])]
    #[TestWith(['status'])]
    #[TestWith(['epoch'])]
    #[TestWith(['password'])]
    public function test_stale_actor_is_rejected_before_private_projection(string $change): void
    {
        $f = $this->fixture();
        $f['membership']->update(['permissions' => ['charging.read']]);
        $snapshot = $f['staff'];
        DB::table('users')->where('id', $snapshot->id)->update(match ($change) {
            'role' => ['role' => 'vendor'], 'status' => ['account_status' => 'suspended'],
            'epoch' => ['auth_epoch' => $snapshot->auth_epoch + 1], 'password' => ['password' => 'changed-private-hash'],
        });
        $request = Request::create($this->url($f['company']));
        $request->setUserResolver(fn () => $snapshot);
        try {
            app(VendorStaffChargingReadController::class)->index($request, $f['company']);
            $this->fail('Changed actor must never receive observations.');
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403, 409]);
        }
    }

    public function test_current_station_ownership_and_supported_filter_bounds_are_enforced(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner']);
        foreach (['page=0', 'page=100001', 'status=active-now', 'status[]=charging'] as $query) {
            $this->getJson($this->url($f['company']).'?'.$query)->assertUnprocessable();
        }
        $f['station']->update(['vendor_id' => User::factory()->create(['role' => 'vendor'])->id]);
        $this->getJson($this->url($f['company']))->assertOk()->assertJsonPath('total', 0);
    }

    public function test_real_customer_native_login_can_read_only_accepted_scope_without_kyc_or_operator_authority(): void
    {
        $f = $this->fixture();
        $f['membership']->update(['permissions' => ['charging.read']]);
        $login = $this->postJson('/api/v1/login', ['email' => $f['staff']->email, 'password' => 'password'])->assertOk()->assertJsonPath('user.role', 'customer');
        $this->app['auth']->forgetGuards();
        $this->withToken($login->json('token'))->getJson('/api/v1/vendor-ops/companies/'.$f['company']->id.'/charging-sessions')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/vendor-ops/companies')->assertOk()->assertJsonPath('data.0.capabilities.charging_read', true)->assertJsonPath('data.0.capabilities.company_read', false)->assertJsonPath('data.0.capabilities.inventory_read', false)->assertJsonPath('data.0.restricted_directory_entry', true);
        $this->getJson('/api/v1/vendor-ops/companies/'.$f['company']->id.'/charging-sessions/'.ChargingSession::firstOrFail()->id.'/meter-history')->assertOk()->assertJsonPath('source_linked', false)->assertJsonPath('observations.total', 0);
        $this->getJson('/api/v1/charging-operations')->assertForbidden();
    }

    public function test_anonymous_outsider_and_unknown_or_duplicate_permissions_never_gain_access(): void
    {
        $f = $this->fixture();
        $this->getJson($this->url($f['company']))->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($this->url($f['company']))->assertNotFound();
        foreach ([['charging.read', 'charging.read'], ['charging.control'], ['finance.read']] as $permissions) {
            $this->actingAs($f['owner'])->patchJson('/api/vendor-ops/companies/'.$f['company']->id.'/staff/'.$f['membership']->id.'/permissions', ['expected_version' => 1, 'permissions' => $permissions])->assertUnprocessable();
        }
        $this->assertSame(['inventory.read'], $f['membership']->fresh()->permissions);
    }

    private function meterFixture(array $f, int $count = 27, string $protocol = '1.6'): array
    {
        $session = ChargingSession::where('reservation_id', Reservation::where('station_id', $f['station']->id)->value('id'))->firstOrFail();
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $f['station']->id, 'identifier' => 'PRIVATE-device-'.$session->id, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $command = ChargerCommand::create(['actor_id' => $f['owner']->id, 'station_id' => $f['station']->id, 'action' => 'start', 'idempotency_key' => 'meter-private-'.$session->id, 'payload_hash' => hash('sha256', 'meter-'.$session->id), 'payload' => ['id_token' => 'PRIVATE-TOKEN']]);
        $link = DB::table('csms_session_links')->insertGetId(['charging_session_id' => $session->id, 'binding_id' => $binding, 'start_command_id' => $command->id, 'identifier' => 'PRIVATE-device-'.$session->id, 'protocol' => $protocol, 'transaction_id' => 'PRIVATE-transaction-'.$session->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, $count) as $i) {
            DB::table('csms_inbox_events')->insert(['link_id' => $link, 'event_id' => 'PRIVATE-event-'.$link.'-'.$i, 'fingerprint' => hash('sha256', 'PRIVATE-'.$i), 'type' => $i === 1 ? 'started' : 'meter', 'sequence' => $i - 1, 'meter_wh' => 1000000 + $i * 100, 'occurred_at' => '2026-11-19 09:00:00.123456', 'outcome' => 'applied', 'created_at' => '2026-11-19 09:00:01.654321']);
        }
        $f['membership']->update(['permissions' => ['charging.read']]);

        return ['url' => $this->url($f['company']).'/'.$session->id.'/meter-history', 'session' => $session, 'binding' => $binding, 'link' => $link];
    }

    #[TestWith(['1.6'])]
    #[TestWith(['2.0.1'])]
    public function test_staff_meter_history_uses_recorded_counters_without_customer_money_or_control_fields(string $protocol): void
    {
        Http::preventStrayRequests();
        $f = $this->fixture();
        $m = $this->meterFixture($f, 27, $protocol);
        DB::table('csms_inbox_events')->insert(['link_id' => $m['link'], 'event_id' => 'PRIVATE-stale', 'fingerprint' => hash('sha256', 'stale'), 'type' => 'meter', 'sequence' => 99, 'meter_wh' => 900, 'occurred_at' => now(), 'outcome' => 'stale', 'created_at' => now()]);
        $before = DB::table('csms_inbox_events')->orderBy('id')->get()->toJson();
        $commands = DB::table('charger_commands')->orderBy('id')->get()->toJson();
        // Existing PostgreSQL inbox columns retain whole seconds; never
        // reconstruct fractional wire timestamps that the store did not retain.
        // timestamp(0) rounds .654321 into the next retained second.
        $postgres = DB::connection()->getDriverName() === 'pgsql';
        $occurred = $postgres ? '2026-11-19T09:00:00.000000Z' : '2026-11-19T09:00:00.123456Z';
        $recorded = $postgres ? '2026-11-19T09:00:02.000000Z' : '2026-11-19T09:00:01.654321Z';
        $reply = $this->actingAs($f['staff'])->getJson($m['url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('scope', 'company_csms_meter_history')->assertJsonPath('company_id', $f['company']->id)
            ->assertJsonPath('session_id', $m['session']->id)->assertJsonPath('source_linked', true)->assertJsonPath('protocol', $protocol)
            ->assertJsonPath('observations.total', 27)->assertJsonCount(25, 'observations.data')
            ->assertJsonPath('observations.data.0.cumulative_meter_wh', 1002700)
            ->assertJsonPath('observations.data.0.source_occurred_at', $occurred)
            ->assertJsonPath('observations.data.0.recorded_at', $recorded)
            ->assertJsonPath('history_complete', false)->assertJsonPath('billing_authority', false)
            ->assertJsonPath('physical_energy_verified', false)->assertJsonPath('commands_sent', false)->assertJsonPath('network_requests', 0);
        foreach (['PRIVATE-', 'fingerprint', 'event_id', 'transaction_id', 'token', 'payload', 'reservation_id', 'user_id', 'total_minor', 'currency'] as $private) {
            $this->assertStringNotContainsString($private, $reply->getContent());
        }
        $this->assertSame($before, DB::table('csms_inbox_events')->orderBy('id')->get()->toJson());
        $this->assertSame($commands, DB::table('charger_commands')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_meter_snapshot_pages_exclude_later_events_until_explicit_refresh(): void
    {
        $f = $this->fixture();
        $m = $this->meterFixture($f);
        $this->actingAs($f['staff']);
        $first = $this->getJson($m['url'])->assertOk();
        $through = $first->json('through_id');
        DB::table('csms_inbox_events')->insert(['link_id' => $m['link'], 'event_id' => 'PRIVATE-later', 'fingerprint' => hash('sha256', 'later'), 'type' => 'ended', 'sequence' => 28, 'meter_wh' => 1005000, 'occurred_at' => now(), 'outcome' => 'applied', 'created_at' => now()]);
        $this->getJson($first->json('observations.next_page_url'))->assertOk()->assertJsonPath('through_id', $through)->assertJsonPath('observations.total', 27)->assertJsonCount(2, 'observations.data');
        $this->getJson($m['url'])->assertOk()->assertJsonPath('observations.total', 28);
        $this->getJson($m['url'].'?through=9007199254740991')->assertUnprocessable();
    }

    public function test_history_never_invents_unlinked_or_foreign_binding_readings_and_rechecks_grants(): void
    {
        $f = $this->fixture();
        $session = ChargingSession::firstOrFail();
        $url = $this->url($f['company']).'/'.$session->id.'/meter-history';
        $f['membership']->update(['permissions' => ['charging.read']]);
        $this->actingAs($f['staff'])->getJson($url)->assertOk()->assertJsonPath('source_linked', false)->assertJsonPath('evidence', 'NO_RECORDED_CSMS_LINK')->assertJsonPath('observations.total', 0);
        $m = $this->meterFixture($f, 2);
        $other = $this->fixture();
        $this->getJson($this->url($other['company']).'/'.$session->id.'/meter-history')->assertNotFound();
        $other['company']->update(['owner_id' => $f['owner']->id]);
        $other['station']->update(['vendor_id' => $f['owner']->id]);
        $this->actingAs($f['owner'])->getJson($this->url($other['company']).'/'.$session->id.'/meter-history')->assertNotFound();
        $this->actingAs($f['staff']);
        DB::table('csms_bindings')->where('id', $m['binding'])->update(['station_id' => $other['station']->id]);
        $this->getJson($url)->assertOk()->assertJsonPath('source_linked', false)->assertJsonPath('observations.total', 0);
        foreach (['invited', 'revoked'] as $state) {
            $f['membership']->update(['status' => $state]);
            $this->getJson($url)->assertNotFound();
        }
        $f['membership']->update(['status' => 'active', 'admin_revoked_at' => now()]);
        $this->getJson($url)->assertNotFound();
        $f['membership']->update(['admin_revoked_at' => null]);
        DB::table('users')->where('id', $f['staff']->id)->update(['email_verified_at' => null]);
        $this->getJson($url)->assertNotFound();
    }

    #[TestWith(['revoked'])]
    #[TestWith(['individual_expiry'])]
    #[TestWith(['global_expiry'])]
    public function test_captured_staff_bearer_cannot_read_session_or_meter_history_after_expiry(string $reason): void
    {
        $f = $this->fixture();
        $m = $this->meterFixture($f, 2);
        config(['sanctum.expiration' => 60]);
        $token = $f['staff']->createToken('staff-observation', ['*'], now()->addHours(2))->accessToken;
        $actor = $f['staff']->fresh()->withAccessToken($token);
        $request = Request::create($m['url']);
        $request->setUserResolver(fn () => $actor);
        $controller = app(VendorStaffChargingReadController::class);
        $this->assertSame(200, $controller->index($request, $f['company'])->getStatusCode());
        $this->assertSame(200, $controller->meters($request, $f['company'], $m['session']->id)->getStatusCode());
        match ($reason) {
            'revoked' => $token->delete(),
            'individual_expiry' => $token->forceFill(['expires_at' => now()->subSecond()])->save(),
            'global_expiry' => $token->forceFill(['created_at' => now()->subMinutes(61)])->save(),
        };
        foreach ([
            fn () => $controller->index($request, $f['company']),
            fn () => $controller->meters($request, $f['company'], $m['session']->id),
        ] as $read) {
            try {
                $read();
                $this->fail('Revoked bearer received private charging records.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('csms_inbox_events', 2);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[TestWith(['1.6'])]
    #[TestWith(['2.0.1'])]
    public function test_changed_binding_identity_or_protocol_is_not_reported_as_canonical_meter_history(string $protocol): void
    {
        Http::preventStrayRequests();
        $f = $this->fixture();
        $m = $this->meterFixture($f, 2, $protocol);
        $binding = DB::table('csms_bindings')->where('id', $m['binding']);
        $original = $binding->first();
        $this->actingAs($f['staff']);
        foreach (['identifier' => 'changed-source-identity', 'protocol' => $protocol === '1.6' ? '2.0.1' : '1.6'] as $field => $changed) {
            $binding->update([$field => $changed]);
            $this->getJson($m['url'])->assertOk()->assertJsonPath('source_linked', false)->assertJsonPath('observations.total', 0);
            $this->getJson($this->url($f['company']))->assertOk()
                ->assertJsonPath('data.0.evidence', 'RECORDED_SOURCE_UNVERIFIED')->assertJsonPath('data.0.protocol', null);
            $binding->update([$field => $original->{$field}]);
            $this->getJson($m['url'])->assertOk()->assertJsonPath('source_linked', true)->assertJsonPath('observations.total', 2);
            $this->getJson($this->url($f['company']))->assertOk()->assertJsonPath('data.0.protocol', $protocol)->assertJsonPath('data.0.evidence', 'SIMULATED_CSMS');
        }
        $this->assertDatabaseCount('csms_inbox_events', 2);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_meter_history_rejects_unsafe_retained_snapshot_even_on_empty_pages(): void
    {
        $f = $this->fixture();
        $m = $this->meterFixture($f, 1);
        DB::table('csms_inbox_events')->where('link_id', $m['link'])->update(['id' => 9007199254740992]);
        $this->actingAs($f['staff']);
        foreach (['', '?page=100000', '?page=100000&through=0'] as $query) {
            $response = $this->getJson($m['url'].$query)->assertUnprocessable();
            $this->assertArrayNotHasKey('through_id', $response->json());
        }
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseHas('csms_inbox_events', ['id' => 9007199254740992]);
    }

    public function test_meter_history_bounds_and_unreadable_counters_fail_without_coercing_or_billing(): void
    {
        $f = $this->fixture();
        $m = $this->meterFixture($f, 1);
        $this->actingAs($f['staff']);
        foreach (['page=0', 'page=100001', 'through=-1', 'through=9007199254740992', 'through=abc'] as $query) {
            $this->getJson($m['url'].'?'.$query)->assertUnprocessable();
        }
        $this->getJson($this->url($f['company']).'/'.str_repeat('9', 40).'/meter-history')->assertNotFound();
        $this->getJson('/api/vendor-ops/companies/'.str_repeat('9', 40).'/charging-sessions/'.$m['session']->id.'/meter-history')->assertNotFound();
        $this->getJson($this->url($f['company']).'/9007199254740992/meter-history')->assertUnprocessable();
        DB::table('csms_inbox_events')->where('link_id', $m['link'])->update(['meter_wh' => 9007199254740992]);
        $this->getJson($m['url'])->assertUnprocessable();
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
