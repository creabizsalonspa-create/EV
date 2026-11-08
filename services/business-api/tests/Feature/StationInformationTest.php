<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StationInformationTest extends TestCase
{
    use RefreshDatabase;

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
        $this->getJson('/api/stations/'.$station->id.'/information')->assertOk()->assertJsonPath('source_label', 'NOT_PROVIDED');
        $this->getJson('/api/stations')->assertOk()->assertJsonPath('data.0.information.source_label', 'NOT_PROVIDED');
        $this->actingAs($vendor)->putJson('/api/stations/'.$station->id.'/information', $this->details())
            ->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('source_label', 'VENDOR_REPORTED_UNVERIFIED');
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
