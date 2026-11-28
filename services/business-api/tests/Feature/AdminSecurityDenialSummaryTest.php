<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminSecurityDenialSummaryController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminSecurityDenialSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function denial(User $actor, string $action, string $subject, string $when): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => $actor->id,
            'action' => $action,
            'subject' => $subject,
            'created_at' => $when,
        ]);
    }

    public function test_only_live_admin_can_review_denial_evidence_without_disclosing_subjects(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $secret = 'PRIVATE-SECURITY-SOURCE-EXPLANATION-123';
        $this->denial($other, 'admin.second_factor.verify_denied', $secret, now()->toDateTimeString());
        $this->denial($other, 'admin.second_factor.recovery_denied', $secret, now()->toDateTimeString());
        $this->denial($admin, 'admin.second_factor.verify_denied', $secret, now()->toDateTimeString());
        $this->denial($admin, 'privacy.requested.access', $secret, now()->toDateTimeString());
        $this->denial($admin, 'admin.second_factor.verify_denied', 'FUTURE-UNTRUSTED-AUDIT', now()->addMinutes(5)->toDateTimeString());

        $this->getJson('/api/admin/security-denials')->assertUnauthorized();
        $this->actingAs($vendor)->getJson('/api/admin/security-denials')->assertForbidden();
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'admin.private_list.security_denials_viewed')->count());

        $response = $this->actingAs($admin)->getJson('/api/admin/security-denials')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('window', '24h')
            ->assertJsonPath('actor_id', null)
            ->assertJsonPath('observed_denials', 3)
            ->assertJsonPath('top_actors.0.actor_id', $other->id)
            ->assertJsonPath('top_actors.0.observed_denials', 2)
            ->assertJsonPath('complete_for_selected_actions', true)
            ->assertJsonPath('suspicious_activity_verified', false)
            ->assertJsonPath('incident_confirmed', false)
            ->assertJsonPath('automated_action_taken', false);
        $this->assertSame(2, $response->json('per_action')['admin.second_factor.verify_denied']);
        $this->assertSame(1, $response->json('per_action')['admin.second_factor.recovery_denied']);
        $response->assertJsonMissingPath('top_actors.0.email')
            ->assertJsonMissingPath('top_actors.0.name');
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.private_list.security_denials_viewed',
            'subject' => '24h:all',
        ]);
    }

    public function test_bounded_recent_sample_and_hour_window_are_not_misreported_as_global_totals(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->denial($admin, 'admin.second_factor.verify_denied', 'old', now()->subHours(3)->toDateTimeString());
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = [
                'actor_id' => $admin->id,
                'action' => 'admin.second_factor.verify_denied',
                'subject' => 'synthetic-denial-'.$i,
                'created_at' => now()->toDateTimeString(),
            ];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('audit_events')->insert($batch);
        }

        $response = $this->actingAs($admin)->getJson('/api/admin/security-denials?window=1h&actor_id='.$admin->id)
            ->assertOk()->assertJsonPath('actor_id', $admin->id)->assertJsonPath('observed_denials', 500)
            ->assertJsonPath('sample_truncated', true)
            ->assertJsonPath('complete_for_selected_actions', false)
            ->assertJsonPath('sample_limit', 500)
            ->assertJsonPath('top_actors.0.observed_denials', 500);
        $this->assertStringNotContainsString('synthetic-denial-500', $response->getContent());
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.private_list.security_denials_viewed',
            'subject' => '1h:actor:'.$admin->id,
        ]);
        $this->getJson('/api/admin/security-denials?window=1d')->assertUnprocessable();
        $this->getJson('/api/admin/security-denials?actor_id=0')->assertUnprocessable();
        $this->getJson('/api/admin/security-denials?actor_id=abc')->assertUnprocessable();
        foreach (['9007199254740992', '01', '+1', '1.5'] as $invalid) {
            $this->getJson('/api/admin/security-denials?actor_id='.rawurlencode($invalid))->assertUnprocessable();
        }
        $this->postJson('/api/admin/security-denials', [])->assertStatus(405);
    }

    public function test_stale_admin_cannot_read_or_audit_security_denials(): void
    {
        foreach ([
            'role' => 'vendor',
            'account_status' => 'suspended',
            'auth_epoch' => 1,
            'password' => bcrypt('rotated-secret'),
        ] as $column => $value) {
            $admin = User::factory()->create(['role' => 'admin']);
            DB::table('users')->where('id', $admin->id)->update([$column => $value]);
            $request = Request::create('/api/admin/security-denials', 'GET');
            $request->setUserResolver(fn () => $admin);
            try {
                app(AdminSecurityDenialSummaryController::class)->index($request);
                $this->fail('Stale Admin session was authorized.');
            } catch (HttpException $error) {
                $this->assertSame(in_array($column, ['auth_epoch', 'password'], true) ? 409 : 403, $error->getStatusCode());
            }
        }
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'admin.private_list.security_denials_viewed')->count());
    }

    public function test_denial_period_and_actor_filter_exclude_old_foreign_and_future_evidence(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);
        $actor = User::factory()->create(['role' => 'admin']);
        $foreignActor = User::factory()->create(['role' => 'admin']);
        $action = 'admin.second_factor.enrollment_code_denied';

        $this->denial($actor, $action, 'recent', now()->subMinutes(30)->toDateTimeString());
        $this->denial($actor, $action, 'two-hours-old', now()->subHours(2)->toDateTimeString());
        $this->denial($actor, $action, 'eight-days-old', now()->subDays(8)->toDateTimeString());
        $this->denial($actor, $action, 'future', now()->addMinutes(30)->toDateTimeString());
        $this->denial($foreignActor, $action, 'foreign', now()->subMinutes(15)->toDateTimeString());

        $this->actingAs($viewer);
        $scope = '/api/admin/security-denials?actor_id='.$actor->id;
        $this->getJson($scope.'&window=1h')->assertOk()
            ->assertJsonPath('observed_denials', 1)
            ->assertJsonPath('sample_truncated', false);
        $this->getJson($scope.'&window=24h')->assertOk()
            ->assertJsonPath('observed_denials', 2);
        $this->getJson($scope.'&window=7d')->assertOk()
            ->assertJsonPath('observed_denials', 2);
        $this->getJson('/api/admin/security-denials?window=1h')->assertOk()
            ->assertJsonPath('observed_denials', 2);
    }
}
