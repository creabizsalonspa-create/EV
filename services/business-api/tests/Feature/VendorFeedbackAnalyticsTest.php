<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\CustomerReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorFeedbackAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $driver = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $station = Station::create([
            'vendor_id' => $vendor->id, 'name' => 'Local test station',
            'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $reservation = Reservation::create([
            'user_id' => $driver->id, 'station_id' => $station->id,
            'starts_at' => now()->subHour(), 'status' => 'completed',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $session = ChargingSession::create([
            'reservation_id' => $reservation->id, 'status' => 'stopped',
            'energy_wh' => 100, 'rate_minor_per_kwh' => 100,
            'currency' => 'AED', 'total_minor' => 10, 'stopped_at' => now(),
        ]);
        return compact('vendor', 'driver', 'admin', 'station', 'session');
    }

    private function createReview(array $fixture): array
    {
        return $this->actingAs($fixture['driver'])
            ->postJson('/api/charging-reviews/sessions/'.$fixture['session']->id, [
                'request_key' => (string) Str::uuid(), 'rating' => 4,
                'body' => 'Test-only published review', 'consent' => true,
                'terms_version' => CustomerReviews::TERMS,
            ])->assertOk()->json();
    }

    public function test_vendor_feedback_is_provenance_qualified_and_excludes_pending_reviews(): void
    {
        $f = $this->fixture();
        $path = '/api/vendor/feedback-analytics';
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs($f['driver'])->getJson($path)->assertForbidden();
        $this->actingAs($f['vendor'])->getJson($path)
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('average_rating', null)
            ->assertJsonPath('physical_charging_verified', false);

        $review = $this->createReview($f);
        $this->actingAs($f['vendor'])->getJson($path)
            ->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($f['admin'])->postJson('/api/charging-reviews/'.$review['id'].'/moderate', [
            'request_key' => (string) Str::uuid(),
            'expected_version' => $review['version'],
            'action' => 'publish', 'reason' => 'Independent synthetic moderation',
        ])->assertOk()->assertJsonPath('state', 'published');

        $result = $this->actingAs($f['vendor'])->getJson($path.'?days=30')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('average_rating', 4)
            ->assertJsonPath('star_distribution.4', 1)
            ->assertJsonPath('stations.0.station_name', 'Local test station')
            ->assertJsonPath('evidence_counts.SIMULATED_APP_ONLY', 1)
            ->assertJsonPath('source', 'MODERATED_SIMULATOR_REVIEWS_ONLY');

        foreach (['user_id', 'public_id', 'body', 'session_id', $f['driver']->email] as $private) {
            $this->assertStringNotContainsString($private, $result->getContent());
        }
    }

    public function test_other_vendor_cannot_select_or_learn_about_foreign_station(): void
    {
        $f = $this->fixture();
        $otherVendor = User::factory()->create(['role' => 'vendor']);
        $path = '/api/vendor/feedback-analytics?station_id='.$f['station']->id;
        $this->actingAs($otherVendor)->getJson($path)->assertNotFound();
        $this->actingAs($f['vendor'])->getJson($path)->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($f['vendor'])->getJson('/api/vendor/feedback-analytics?days=366')
            ->assertUnprocessable();
    }

    public function test_native_vendor_route_has_the_same_tenant_restrictions(): void
    {
        $f = $this->fixture();
        $otherVendor = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($otherVendor, 'sanctum')->getJson('/api/v1/vendor/feedback-analytics?station_id='.$f['station']->id)
            ->assertNotFound();
        $this->actingAs($f['vendor'], 'sanctum')->getJson('/api/v1/vendor/feedback-analytics')
            ->assertOk()->assertJsonPath('physical_charging_verified', false)
            ->assertJsonPath('total', 0);
    }
}
