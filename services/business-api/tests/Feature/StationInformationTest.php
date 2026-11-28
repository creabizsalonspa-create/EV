<?php

namespace Tests\Feature;

use App\Http\Controllers\StationAvailabilityBlockController;
use App\Http\Controllers\StationInformationController;
use App\Models\Station;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StationInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_station_information_rejects_unbounded_route_ids(): void
    {
        foreach (['/api', '/api/v1'] as $prefix) {
            foreach (['invalid', '-1', '1.5', '9223372036854775808', str_repeat('9', 128)] as $id) {
                $this->getJson($prefix.'/stations/'.$id.'/information')->assertNotFound();
            }
        }
    }

    public function test_anonymous_information_is_public_metadata_only_and_writes_stay_authenticated(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $this->actingAs($vendor)->putJson('/api/stations/'.$station->id.'/information', $this->details())->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertGuest();
        $expected = ['availability_blocks', 'station_id', 'revision', 'operator_name', 'address_en', 'address_ar', 'description_en', 'description_ar', 'access_en', 'access_ar',
            'notice_en', 'notice_ar', 'notice_until', 'notice_active', 'weekly_hours', 'closed_dates', 'enforce_operating_hours',
            'amenities', 'accessibility', 'source_label', 'updated_at'];
        sort($expected);
        foreach (['/api', '/api/v1'] as $prefix) {
            $route = $prefix.'/stations/'.$station->id.'/information';
            $response = $this->getJson($route)->assertOk()
                ->assertJsonPath('source_label', 'VENDOR_REPORTED_UNVERIFIED');
            $keys = array_keys($response->json());
            sort($keys);
            $this->assertSame($expected, $keys, 'Public metadata must never expose owner, audit or charging credentials.');
            $this->assertStringNotContainsString($vendor->email, $response->getContent());
            $this->putJson($route, $this->details(1))->assertUnauthorized();
        }
        $this->assertSame(1, DB::table('station_information')->where('station_id', $station->id)->value('revision'));
    }

    public function test_public_discovery_labels_require_owner_and_preserve_older_client_updates(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $labels = ['operator_name' => 'Published Operator', 'address_en' => 'Public plaza', 'address_ar' => 'الساحة'];
        $this->actingAs($vendor)->putJson('/api/stations/'.$station->id.'/information', $this->details() + $labels)
            ->assertOk()->assertJsonPath('operator_name', 'Published Operator')->assertJsonPath('address_ar', 'الساحة');
        $this->putJson('/api/stations/'.$station->id.'/information', $this->details(1))
            ->assertOk()->assertJsonPath('operator_name', 'Published Operator')->assertJsonPath('address_en', 'Public plaza');
        $this->putJson('/api/stations/'.$station->id.'/information', $this->details(2) + ['operator_name' => str_repeat('a', 101)])
            ->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))
            ->putJson('/api/stations/'.$station->id.'/information', $this->details(2) + $labels)->assertNotFound();
        $this->actingAs($vendor)->putJson('/api/stations/'.$station->id.'/information', $this->details(2) + ['operator_name' => null, 'address_en' => null, 'address_ar' => null])
            ->assertOk()->assertJsonPath('operator_name', null)->assertJsonPath('address_en', null);
    }

    private function station(User $vendor): Station
    {
        return Station::create([
            'vendor_id' => $vendor->id,
            'name' => 'Access Test', 'country' => 'AE', 'currency' => 'AED',
            'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3,
            'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
    }

    private function details(int $revision = 0): array
    {
        return [
            'revision' => $revision,
            'description_en' => 'Level B, entrance beside the plaza',
            'description_ar' => 'المدخل بجانب الساحة',
            'access_en' => 'Gate B, visitors register at reception',
            'access_ar' => null,
            'weekly_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], ['open' => '08:00', 'close' => '22:00']),
            'closed_dates' => ['2026-12-02'],
            'amenities' => ['parking', 'wifi'],
            'accessibility' => ['step_free'],
        ];
    }

    public function test_public_catalog_information_is_source_labeled_and_unpublished_is_not_guessed(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $this->getJson('/api/stations/'.$station->id.'/information')->assertOk()
            ->assertJsonPath('station_id', $station->id)
            ->assertJsonPath('source_label', 'NOT_PROVIDED');
        $this->getJson('/api/stations')->assertOk()->assertJsonPath('data.0.information.source_label', 'NOT_PROVIDED');
        $this->actingAs($vendor)->putJson('/api/stations/'.$station->id.'/information', $this->details())
            ->assertOk()->assertJsonPath('station_id', $station->id)
            ->assertJsonPath('revision', 1)->assertJsonPath('source_label', 'VENDOR_REPORTED_UNVERIFIED');
        $this->getJson('/api/stations')->assertOk()->assertJsonPath('data.0.information.weekly_hours.mon.open', '08:00')
            ->assertJsonPath('data.0.information.amenities.0', 'parking')
            ->assertJsonPath('data.0.availability', 'unknown');
    }

    public function test_vendor_isolation_and_optimistic_version_protect_updates(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $other = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $station = $this->station($owner);
        $route = '/api/stations/'.$station->id.'/information';
        $this->actingAs($other)->putJson($route, $this->details())->assertNotFound();
        $this->actingAs($customer)->putJson($route, $this->details())->assertNotFound();
        $this->actingAs($owner)->putJson($route, $this->details())->assertOk()->assertJsonPath('revision', 1);
        $this->actingAs($owner)->putJson($route, $this->details())->assertStatus(409);
        $this->actingAs($owner)->putJson($route, $this->details(1))->assertOk()->assertJsonPath('revision', 2);
        $this->assertSame(2, DB::table('audit_events')->where('action', 'station.information.updated')->count());
    }

    public function test_invalid_partial_hours_or_holiday_does_not_replace_existing_record(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($owner);
        $route = '/api/v1/stations/'.$station->id.'/information';
        $this->actingAs($owner, 'sanctum')->putJson($route, $this->details())->assertOk();
        $bad = $this->details(1);
        $bad['weekly_hours']['mon'] = ['open' => '22:00', 'close' => null];
        $this->actingAs($owner, 'sanctum')->putJson($route, $bad)->assertUnprocessable();
        $bad = $this->details(1);
        $bad['closed_dates'] = ['2027-02-30'];
        $this->actingAs($owner, 'sanctum')->putJson($route, $bad)->assertUnprocessable();
        $this->getJson($route)->assertOk()->assertJsonPath('revision', 1);
    }

    public function test_overnight_and_twenty_four_hour_schedules_are_preserved_without_claiming_live_availability(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $details = $this->details();
        $details['weekly_hours']['mon'] = ['open' => '20:00', 'close' => '02:00'];
        $details['weekly_hours']['tue'] = ['open' => '00:00', 'close' => '00:00'];
        $this->actingAs($vendor)->putJson('/api/stations/'.$station->id.'/information', $details)
            ->assertOk()->assertJsonPath('weekly_hours.mon.close', '02:00')
            ->assertJsonPath('weekly_hours.tue.close', '00:00');
        $this->getJson('/api/stations')->assertOk()->assertJsonPath('data.0.availability', 'unknown');
    }

    public function test_expiring_vendor_access_notices_are_source_qualified_and_do_not_change_availability(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $route = '/api/stations/'.$station->id.'/information';
        $input = $this->details();
        $input['notice_en'] = 'Temporary gate access from the rear entrance';
        $input['notice_ar'] = 'استخدم المدخل الخلفي مؤقتاً';
        $input['notice_until'] = now('UTC')->addDays(2)->toDateString();

        $this->actingAs($vendor)->putJson($route, $input)->assertOk()
            ->assertJsonPath('notice_active', true)
            ->assertJsonPath('notice_en', $input['notice_en']);
        $this->getJson('/api/stations')->assertOk()
            ->assertJsonPath('data.0.information.notice_active', true)
            ->assertJsonPath('data.0.availability', 'unknown');

        // An older web/native client must not silently erase an active notice.
        $legacy = $this->details(1);
        $this->actingAs($vendor)->putJson($route, $legacy)->assertOk()
            ->assertJsonPath('notice_active', true)
            ->assertJsonPath('notice_until', $input['notice_until']);

        $this->travel(3)->days();
        $this->getJson($route)->assertOk()->assertJsonPath('notice_active', false);
        $this->travelBack();
        $this->assertSame('simulator', $station->fresh()->source);
    }

    public function test_public_notice_does_not_block_booking_and_can_be_cleared(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17T08:00:00Z'));
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = $this->station($vendor);
        $route = '/api/stations/'.$station->id.'/information';
        $notice = ['notice_en' => 'Use the rear gate', 'notice_ar' => 'استخدم المدخل الخلفي', 'notice_until' => '2026-11-17'];

        $this->actingAs($vendor)->putJson($route, $this->details() + $notice)
            ->assertOk()->assertJsonPath('notice_active', true)
            ->assertJsonPath('enforce_operating_hours', false)
            ->assertJsonCount(0, 'availability_blocks');

        $this->actingAs($customer)->postJson('/api/reservations', [
            'station_id' => $station->id, 'starts_at' => '2026-11-17T09:00:00Z', 'idempotency_key' => 'during-operator-notice',
        ])->assertCreated();
        $this->getJson('/api/v1/stations/'.$station->id.'/information')->assertOk()
            ->assertJsonPath('notice_en', $notice['notice_en'])->assertJsonPath('notice_ar', $notice['notice_ar']);

        $this->travelTo(CarbonImmutable::parse('2026-11-17T23:59:59.999999Z'));
        $this->getJson($route)->assertOk()->assertJsonPath('notice_active', true);
        $this->travelTo(CarbonImmutable::parse('2026-11-18T00:00:00Z'));
        $this->getJson($route)->assertOk()->assertJsonPath('notice_active', false);

        $clear = $this->details(1) + ['notice_en' => null, 'notice_ar' => null, 'notice_until' => null];
        $this->putJson($route, $clear)->assertNotFound();
        $this->actingAs($vendor)->putJson($route, $clear)->assertOk()
            ->assertJsonPath('revision', 2)->assertJsonPath('notice_active', false);
        $this->getJson($route)->assertOk()
            ->assertJsonPath('notice_en', null)->assertJsonPath('notice_ar', null)->assertJsonPath('notice_until', null);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_revoked_bearer_cannot_publish_a_notice_or_an_availability_block(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($owner);
        $token = $owner->createToken('mobile')->accessToken;
        $owner->withAccessToken($token);
        $route = '/api/v1/stations/'.$station->id;
        $notice = Request::create($route.'/information', 'PUT', $this->details() + [
            'notice_en' => 'Use the rear gate',
            'notice_ar' => 'استخدم المدخل الخلفي',
            'notice_until' => now('UTC')->toDateString(),
        ]);
        $notice->setUserResolver(fn () => $owner);
        $block = Request::create($route.'/availability-blocks', 'POST', [
            'idempotency_key' => (string) Str::uuid(),
            'starts_at' => now('UTC')->addHour()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => now('UTC')->addHours(2)->format('Y-m-d\TH:i:s\Z'),
            'reason_en' => 'Scheduled maintenance',
            'reason_ar' => 'صيانة مجدولة',
        ]);
        $block->setUserResolver(fn () => $owner);
        $token->delete();

        foreach ([
            fn () => app(StationInformationController::class)->save($notice, $station),
            fn () => app(StationAvailabilityBlockController::class)->store($block, $station),
        ] as $publish) {
            try {
                $publish();
                $this->fail('The revoked bearer published station policy.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('station_information', 0);
        $this->assertDatabaseCount('station_availability_blocks', 0);
    }

    #[TestWith(['auth_epoch', 3, 409])]
    #[TestWith(['role', 'customer', 409])]
    #[TestWith(['account_status', 'suspended', 403])]
    #[TestWith(['password', 'changed-synthetic-password-hash', 409])]
    public function test_captured_vendor_login_cannot_replace_operating_hours_or_notices(string $field, mixed $value, int $status): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($owner);
        $route = '/api/stations/'.$station->id.'/information';
        $this->actingAs($owner)->putJson($route, $this->details() + [
            'notice_en' => 'Original public notice',
            'notice_ar' => 'إشعار',
            'notice_until' => now('UTC')->addDay()->toDateString(),
        ])->assertOk();
        $request = Request::create($route, 'PUT', $this->details(1) + [
            'enforce_operating_hours' => true,
            'notice_en' => 'Unauthorized replacement',
            'notice_ar' => 'تغيير',
            'notice_until' => now('UTC')->addDays(2)->toDateString(),
        ]);
        $request->setUserResolver(fn () => $owner);
        DB::table('users')->where('id', $owner->id)->update([$field => $value]);
        try {
            app(StationInformationController::class)->save($request, $station);
            $this->fail('A captured stale login published station policy.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
        $this->assertDatabaseHas('station_information', [
            'station_id' => $station->id, 'revision' => 1,
            'notice_en' => 'Original public notice', 'enforce_operating_hours' => false,
        ]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'station.information.updated')->count());
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_invalid_or_partial_notice_never_changes_existing_station_information(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($vendor);
        $route = '/api/stations/'.$station->id.'/information';
        $this->actingAs($vendor)->putJson($route, $this->details())->assertOk();

        $withoutExpiry = $this->details(1) + [
            'notice_en' => 'Temporary restricted entry', 'notice_ar' => null,
        ];
        $this->actingAs($vendor)->putJson($route, $withoutExpiry)->assertUnprocessable();

        $invalid = array_merge($this->details(1), [
            'notice_en' => 'Temporary restricted entry',
            'notice_ar' => null,
            'notice_until' => '2027-02-30',
        ]);
        $this->actingAs($vendor)->putJson($route, $invalid)->assertUnprocessable();
        $this->getJson($route)->assertOk()->assertJsonPath('revision', 1)
            ->assertJsonPath('notice_active', false);
    }

    public function test_injected_station_or_status_fields_cannot_change_authoritative_state(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = $this->station($owner);
        $this->actingAs($owner)->putJson('/api/stations/'.$station->id.'/information', $this->details() + [
            'availability' => 'available', 'remote_control' => true, 'vendor_id' => 999,
        ])->assertOk();
        $this->assertSame('simulator', $station->fresh()->source);
        $this->getJson('/api/stations')->assertOk()->assertJsonPath('data.0.remote_control', false);
    }
}
