<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\CustomerReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChargingReviewsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $driver = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Review test station', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $r = Reservation::create(['user_id' => $driver->id, 'station_id' => $station->id, 'starts_at' => now()->subHour(), 'status' => 'completed', 'idempotency_key' => (string) Str::uuid()]);
        $s = ChargingSession::create(['reservation_id' => $r->id, 'status' => 'stopped', 'energy_wh' => 100, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 10, 'stopped_at' => now()]);

        return [$driver, $vendor, $admin, $station, $s];
    }

    private function submission(): array
    {
        return ['request_key' => (string) Str::uuid(), 'rating' => 4, 'body' => 'Useful synthetic feedback <script>not HTML</script>', 'consent' => true, 'terms_version' => CustomerReviews::TERMS];
    }

    private function postReview(User $driver, ChargingSession $s): array
    {
        $v = $this->submission();
        $reply = $this->actingAs($driver)->postJson('/api/charging-reviews/sessions/'.$s->id, $v)->assertOk()->assertJsonPath('state', 'pending')->assertJsonPath('physical_charging_verified', false);

        return [$reply->json(), $v];
    }

    private function decision(User $admin, array $row, string $action = 'publish')
    {
        $this->actingAs($admin);

        return $this->postJson('/api/charging-reviews/'.$row['id'].'/moderate', ['request_key' => (string) Str::uuid(), 'expected_version' => $row['version'], 'action' => $action, 'reason' => 'Synthetic moderation reviewed']);
    }

    public function test_publication_requires_owned_completed_session_consent_and_independent_admin_decision(): void
    {
        [$driver,$vendor,$admin,$station,$session] = $this->fixture();
        $this->getJson('/api/charging-reviews/eligible')->assertUnauthorized();
        $this->actingAs($driver)->getJson('/api/charging-reviews/eligible')->assertOk()->assertJsonPath('data.0.can_review', true);
        $v = $this->submission();
        $v['consent'] = false;
        $this->postJson('/api/charging-reviews/sessions/'.$session->id, $v)->assertUnprocessable();
        [$row,$v] = $this->postReview($driver, $session);
        $this->getJson('/api/stations/'.$station->id.'/reviews')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($vendor)->postJson('/api/charging-reviews/'.$row['id'].'/moderate', ['request_key' => (string) Str::uuid(), 'expected_version' => 1, 'action' => 'publish', 'reason' => 'Vendor cannot self-publish'])->assertForbidden();
        $this->decision($admin, $row)->assertOk()->assertJsonPath('state', 'published');
        $public = $this->getJson('/api/stations/'.$station->id.'/reviews')->assertOk()->assertJsonPath('groups.1.count', 1)->assertJsonPath('groups.1.rating_sum', 4)->assertJsonPath('data.0.evidence', 'SIMULATED_APP_ONLY');
        foreach (['user_id', 'session_id', 'source_fingerprint', 'request_key', 'reason', 'ciphertext', $driver->email] as $secret) {
            $this->assertStringNotContainsString($secret, $public->getContent());
        }
        $this->assertStringNotContainsString($v['body'], DB::table('charging_review_events')->value('body_ciphertext'));
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        Http::assertNothingSent();
    }

    public function test_exact_replay_scope_versions_and_revisions_do_not_republish_unreviewed_edits(): void
    {
        [$driver,,$admin,$station,$s] = $this->fixture();
        [$row,$v] = $this->postReview($driver, $s);
        $this->postJson('/api/charging-reviews/sessions/'.$s->id, $v)->assertOk()->assertJsonPath('id', $row['id']);
        $this->assertDatabaseCount('charging_review_events', 1);
        $this->postJson('/api/charging-reviews/sessions/'.$s->id, array_replace($v, ['rating' => 1]))->assertConflict();
        $published = $this->decision($admin, $row)->assertOk()->json();
        $body = $this->submission() + ['expected_version' => 1];
        $this->actingAs($driver)->putJson('/api/charging-reviews/'.$row['id'], $body)->assertConflict();
        $body['expected_version'] = $published['version'];
        $body['body'] = 'Revised source feedback';
        $revised = $this->putJson('/api/charging-reviews/'.$row['id'], $body)->assertOk()->assertJsonPath('state', 'pending')->assertJsonPath('revision', 2)->json();
        $this->getJson('/api/stations/'.$station->id.'/reviews')->assertOk()->assertJsonCount(0, 'data');
        $this->decision($admin, $row)->assertConflict();
        $this->decision($admin, $revised)->assertOk();
        $this->actingAs(User::factory()->create())->deleteJson('/api/charging-reviews/'.$row['id'], ['request_key' => (string) Str::uuid(), 'expected_version' => 4])->assertNotFound();
        $this->actingAs($driver)->deleteJson('/api/charging-reviews/'.$row['id'], ['request_key' => (string) Str::uuid(), 'expected_version' => 4])->assertOk()->assertJsonPath('state', 'withdrawn');
        $this->getJson('/api/stations/'.$station->id.'/reviews')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/charging-reviews/'.$row['id'].'/history')->assertOk()->assertJsonCount(5, 'data');
        $this->assertDatabaseCount('charging_reviews', 1);
    }

    public function test_foreign_unfinished_zero_energy_and_changed_station_feedback_fail_closed(): void
    {
        [$driver,$vendor,$admin,$station,$s] = $this->fixture();
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/charging-reviews/sessions/'.$s->id, $this->submission())->assertNotFound();
        $s->update(['energy_wh' => 0]);
        $this->actingAs($driver)->postJson('/api/charging-reviews/sessions/'.$s->id, $this->submission())->assertConflict();
        $s->update(['energy_wh' => 100, 'status' => 'charging']);
        $this->postJson('/api/charging-reviews/sessions/'.$s->id, $this->submission())->assertConflict();
        $s->update(['status' => 'stopped']);
        [$row] = $this->postReview($driver, $s);
        $station->update(['vendor_id' => $other->id]);
        $this->decision($admin, $row)->assertConflict();
        $station->update(['vendor_id' => $vendor->id]);
        $this->decision($admin, $row)->assertOk();
        $station->update(['source' => 'discovery']);
        $this->getJson('/api/stations/'.$station->id.'/reviews')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('groups.1.count', 0);
    }

    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_source_bound_feedback_requires_applied_canonical_start_and_end_and_keeps_evidence_separate(string $protocol): void
    {
        [$driver,,$admin,$station,$s] = $this->fixture();
        $start = now()->subMinute()->startOfSecond();
        $end = now()->startOfSecond();
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'identifier' => 'ev-test-review', 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $link = DB::table('csms_session_links')->insertGetId(['charging_session_id' => $s->id, 'binding_id' => $binding, 'start_command_id' => null, 'transaction_id' => 'review-transaction', 'protocol' => $protocol, 'identifier' => 'ev-test-review', 'last_sequence' => 1, 'meter_start_wh' => 100, 'last_meter_wh' => 200, 'started_at' => $start, 'ended_at' => $end, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['started', 0, 100, $start], ['ended', 1, 200, $end]] as [$type,$seq,$meter,$at]) {
            DB::table('csms_inbox_events')->insert(['link_id' => $link, 'event_id' => (string) Str::uuid(), 'fingerprint' => hash('sha256', $type), 'type' => $type, 'sequence' => $seq, 'meter_wh' => $meter, 'occurred_at' => $at, 'outcome' => $type === 'ended' ? 'stale' : 'applied', 'created_at' => now()]);
        }
        $this->actingAs($driver)->postJson('/api/charging-reviews/sessions/'.$s->id, $this->submission())->assertConflict();
        DB::table('csms_inbox_events')->where('link_id', $link)->where('type', 'ended')->update(['outcome' => 'applied']);
        [$row] = $this->postReview($driver, $s);
        $this->assertSame('SIMULATED_SOURCE_OBSERVED', $row['evidence']);
        $this->decision($admin, $row)->assertOk();
        $this->getJson('/api/stations/'.$station->id.'/reviews')->assertOk()->assertJsonPath('groups.0.count', 1)->assertJsonPath('groups.1.count', 0);
    }
}
