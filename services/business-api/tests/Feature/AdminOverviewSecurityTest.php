<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminOverviewSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_authenticated_admin_can_read_audited_private_overview(): void
    {
        $this->getJson('/api/admin/overview')->assertUnauthorized();
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson('/api/admin/overview')->assertForbidden();
        }
        $this->assertDatabaseCount('audit_events', 0);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->getJson('/api/admin/overview')
            ->assertOk()->assertJsonPath('users', 3)
            ->assertJsonPath('stations', 0)
            ->assertJsonPath('reservations', 0)
            ->assertJsonPath('simulations', 0)
            ->assertJsonPath('audit_events', [])
            ->assertHeader('Cache-Control', 'no-store, private');

        $response->assertJsonMissingPath('password');
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.private_list.overview_viewed',
            'subject' => 'overview',
        ]);
    }

    public function test_stale_suspended_demoted_or_rotated_admin_cannot_read_private_audit_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web');

        DB::table('users')->where('id', $admin->id)->update(['account_status' => 'suspended']);
        $this->getJson('/api/admin/overview')->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'account_status' => 'active',
            'role' => 'vendor',
        ]);
        $this->getJson('/api/admin/overview')->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'role' => 'admin',
            'auth_epoch' => 1,
        ]);
        $this->getJson('/api/admin/overview')->assertStatus(409);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_overview_history_is_bounded_and_explicitly_allowlisted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        for ($i = 0; $i < 60; $i++) {
            DB::table('audit_events')->insert([
                'actor_id' => $admin->id,
                'action' => 'synthetic.audit',
                'subject' => 'case:'.$i,
                'created_at' => now(),
            ]);
        }

        $response = $this->actingAs($admin)->getJson('/api/admin/overview')
            ->assertOk()->assertJsonCount(50, 'audit_events')
            ->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('audit_events.0.subject', 'case:59')
            ->assertJsonMissingPath('audit_events.0.password')
            ->assertJsonMissingPath('audit_events.0.reason_encrypted');

        $this->assertDatabaseCount('audit_events', 61);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.private_list.overview_viewed',
            'subject' => 'overview',
        ]);
    }
}
