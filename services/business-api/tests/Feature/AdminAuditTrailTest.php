<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function event(User $actor, string $action, string $subject): int
    {
        return DB::table('audit_events')->insertGetId([
            'actor_id' => $actor->id,
            'action' => $action,
            'subject' => $subject,
            'created_at' => now(),
        ]);
    }

    public function test_only_active_admin_can_read_audit_history(): void
    {
        $this->getJson('/api/admin/audit-events')->assertUnauthorized();
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson('/api/admin/audit-events')->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/admin/audit-events')
            ->assertOk()->assertJsonPath('events', [])
            ->assertHeader('Cache-Control', 'no-store, private');
        $admin->forceFill(['account_status' => 'suspended'])->save();
        $this->actingAs($admin->fresh())->getJson('/api/admin/audit-events')->assertForbidden();
    }

    public function test_cursor_pagination_is_bounded_and_keeps_new_events_from_shifting_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        for ($i = 0; $i < 7; $i++) {
            $this->event($admin, 'admin.account.suspended', (string) ($i + 100));
        }
        $this->actingAs($admin);
        $first = $this->getJson('/api/admin/audit-events?limit=3')
            ->assertOk()->assertJsonCount(3, 'events')
            ->assertJsonPath('has_more', true);
        $firstIds = array_column($first->json('events'), 'id');
        $this->assertGreaterThan($firstIds[1], $firstIds[0]);
        $this->assertGreaterThan($firstIds[2], $firstIds[1]);
        $cursor = $first->json('next_before_id');
        $this->assertSame($firstIds[2], $cursor);

        // Insert a newer event between pages; old cursor remains stable.
        $this->event($admin, 'admin.account.active', 'new');
        $second = $this->getJson('/api/admin/audit-events?limit=3&before_id='.$cursor)
            ->assertOk()->assertJsonCount(3, 'events')
            ->assertJsonPath('has_more', true);
        $secondIds = array_column($second->json('events'), 'id');
        $this->assertLessThan($cursor, $secondIds[0]);
        $this->assertCount(6, array_unique(array_merge($firstIds, $secondIds)));
        $this->getJson('/api/admin/audit-events?limit=3&before_id='.$second->json('next_before_id'))
            ->assertOk()->assertJsonCount(1, 'events')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_before_id', null);
    }

    public function test_filters_and_serialization_do_not_disclose_user_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'vendor']);
        $id = $this->event($other, 'vendor_ops.company.reviewed', 'customer:42');
        $this->event($admin, 'admin.account.suspended', 'customer:43');

        $this->actingAs($admin)->getJson('/api/admin/audit-events?action_prefix=vendor_ops.&actor_id='.$other->id)
            ->assertOk()->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $id)
            ->assertJsonPath('events.0.actor_id', $other->id)
            ->assertJsonPath('events.0.actor_name', $other->name)
            ->assertJsonPath('events.0.action', 'vendor_ops.company.reviewed')
            ->assertJsonMissingPath('events.0.actor_email')
            ->assertJsonMissingPath('events.0.password')
            ->assertJsonMissingPath('events.0.remember_token');

        $response = $this->getJson('/api/admin/audit-events')->assertOk();
        $this->assertStringNotContainsString($other->password, $response->getContent());
    }

    public function test_invalid_filter_inputs_and_mutations_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/audit-events?limit=101')->assertUnprocessable();
        $this->getJson('/api/admin/audit-events?limit=0')->assertUnprocessable();
        $this->getJson('/api/admin/audit-events?before_id=-1')->assertUnprocessable();
        $this->getJson('/api/admin/audit-events?actor_id=0')->assertUnprocessable();
        $this->getJson('/api/admin/audit-events?action_prefix=%25')->assertUnprocessable();
        $this->getJson('/api/admin/audit-events?action_prefix=%5F')->assertUnprocessable();
        $this->postJson('/api/admin/audit-events', ['action' => 'admin.fake'])->assertStatus(405);
    }
}
