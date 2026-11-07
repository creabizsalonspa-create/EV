<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminOperatorRoleHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function change(User $target, string $from, string $to, ?string $ticket = null): int
    {
        return DB::table('operator_role_changes')->insertGetId([
            'target_user_id' => $target->id,
            'previous_role' => $from,
            'assigned_role' => $to,
            'source' => 'platform:role',
            'ticket_reference' => $ticket,
            'created_at' => now(),
        ]);
    }

    public function test_role_change_history_is_readable_only_by_authenticated_admin(): void
    {
        $route = '/api/admin/operator-role-changes';
        $this->getJson($route)->assertUnauthorized();
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson($route)->assertForbidden();
        }
        $this->assertDatabaseCount('audit_events', 0);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson($route)->assertOk()
            ->assertJsonPath('events', [])
            ->assertJsonPath('operator_identity_available', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.operator_role_history.viewed',
            'subject' => 'all',
        ]);
    }

    public function test_cli_operator_changes_are_visible_with_truthful_provenance_and_no_credentials(): void
    {
        $target = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame(0, Artisan::call('platform:role', [
            'email' => $target->email,
            'role' => 'vendor',
            '--ticket' => 'SEC-ROLE:872',
        ]));
        $response = $this->actingAs($admin)
            ->getJson('/api/admin/operator-role-changes?target_user_id='.$target->id)
            ->assertOk()->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.target_user_id', $target->id)
            ->assertJsonPath('events.0.target_name', $target->name)
            ->assertJsonPath('events.0.target_email', $target->email)
            ->assertJsonPath('events.0.previous_role', 'customer')
            ->assertJsonPath('events.0.assigned_role', 'vendor')
            ->assertJsonPath('events.0.source', 'platform:role')
            ->assertJsonPath('events.0.ticket_reference', 'SEC-ROLE:872')
            ->assertJsonPath('operator_identity_available', false)
            ->assertJsonMissingPath('events.0.operator_user_id')
            ->assertJsonMissingPath('events.0.password')
            ->assertJsonMissingPath('events.0.remember_token');
        $this->assertStringNotContainsString($target->password, $response->getContent());
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.operator_role_history.viewed',
            'subject' => (string) $target->id,
        ]);
    }

    public function test_role_timeline_filters_are_stable_bounded_and_exclude_newer_events(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'vendor']);
        for ($i = 0; $i < 7; $i++) {
            $this->change($target, 'customer', 'vendor', 'QA-'.$i);
        }
        $this->change($other, 'vendor', 'admin', 'QA-foreign');
        $this->actingAs($admin);
        $first = $this->getJson('/api/admin/operator-role-changes?limit=3&assigned_role=vendor&target_user_id='.$target->id)
            ->assertOk()->assertJsonCount(3, 'events')
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('limit', 3);
        $ids = array_column($first->json('events'), 'id');
        $this->assertGreaterThan($ids[1], $ids[0]);
        $this->assertGreaterThan($ids[2], $ids[1]);
        $cursor = $first->json('next_before_id');
        $this->assertSame($ids[2], $cursor);
        $this->change($target, 'vendor', 'vendor', 'NEW-NOT-REAL-CLI');
        $second = $this->getJson('/api/admin/operator-role-changes?limit=3&assigned_role=vendor&target_user_id='.$target->id.'&before_id='.$cursor)
            ->assertOk()->assertJsonCount(3, 'events')
            ->assertJsonPath('has_more', true);
        $secondIds = array_column($second->json('events'), 'id');
        $this->assertLessThan($cursor, $secondIds[0]);
        $this->assertCount(6, array_unique(array_merge($ids, $secondIds)));

        $this->getJson('/api/admin/operator-role-changes?limit=3&assigned_role=vendor&target_user_id='.$target->id.'&before_id='.$second->json('next_before_id'))
            ->assertOk()->assertJsonCount(1, 'events')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_before_id', null);
    }

    public function test_invalid_filters_and_unapproved_mutations_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([
            'limit=0', 'limit=101', 'target_user_id=0',
            'assigned_role=superadmin', 'before_id=-1',
        ] as $filter) {
            $this->getJson('/api/admin/operator-role-changes?'.$filter)
                ->assertUnprocessable();
        }
        $this->postJson('/api/admin/operator-role-changes', [
            'email' => 'victim@example.test', 'role' => 'admin',
        ])->assertStatus(405);
        $this->assertDatabaseCount('operator_role_changes', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
