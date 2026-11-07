<?php

namespace Tests\Feature;

use App\Models\InboxNotification;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_traffic_does_not_consume_support_rate_limit(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 35; $i++) {
            $this->getJson('/api/stations')->assertOk();
        }
        $this->getJson('/api/support')->assertOk();
    }

    public function test_support_rate_limit_still_rejects_excess_requests(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/support')->assertOk();
        }
        $this->getJson('/api/support')->assertStatus(429);
    }

    public function test_support_pagination_is_bounded_without_disclosing_foreign_tickets(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $ticket = SupportTicket::create(['user_id' => $owner->id, 'subject' => 'Support page check']);

        $this->actingAs($owner);
        foreach (['0', '-10', 'not-a-page', '1000001', '1.7'] as $invalid) {
            foreach (['/api/support', '/api/v1/support'] as $base) {
                $this->getJson($base.'?page='.$invalid)->assertUnprocessable();
                $this->getJson($base.'/'.$ticket->id.'?page='.$invalid)->assertUnprocessable();
            }
        }
        $this->getJson('/api/support?page=1')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/support/'.$ticket->id.'?page=1')->assertOk()
            ->assertJsonPath('ticket.id', $ticket->id);
        // A foreign ticket still returns 404, even with malformed pagination.
        $this->actingAs($outsider)->getJson('/api/support/'.$ticket->id.'?page=nope')
            ->assertNotFound();
    }

    public function test_ticket_and_internal_notes_are_private_and_notifications_are_scoped(): void
    {
        $u = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($u)->postJson('/api/support', ['subject' => 'Charging issue', 'body' => 'Please investigate'])->assertCreated()->assertJsonPath('status', 'open');
        $t = SupportTicket::first();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson('/api/support/'.$t->id)->assertNotFound();
        $this->getJson('/api/support')->assertJsonCount(0, 'data');
        $this->actingAs($admin)->postJson('/api/support/'.$t->id.'/messages', ['body' => 'Internal investigation', 'internal' => true])->assertCreated();
        $this->assertDatabaseCount('inbox_notifications', 0);
        $this->postJson('/api/support/'.$t->id.'/messages', ['body' => 'We are investigating', 'internal' => false])->assertCreated();
        $this->getJson('/api/support/'.$t->id)->assertJsonCount(3, 'messages.data');
        $this->actingAs($u)->getJson('/api/support/'.$t->id)->assertJsonCount(2, 'messages.data');
        $this->postJson('/api/support/'.$t->id.'/messages', ['body' => 'Sneaky note', 'internal' => true])->assertForbidden();
        $n = InboxNotification::first();
        $this->getJson('/api/notifications')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->postJson('/api/notifications/'.$n->id.'/read')->assertNotFound();
        $this->actingAs($u)->postJson('/api/notifications/'.$n->id.'/read')->assertOk();
        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_resolution_close_and_reopen_have_explicit_transition_rules(): void
    {
        $u = User::factory()->create();
        $a = User::factory()->create(['role' => 'admin']);
        $t = SupportTicket::create(['user_id' => $u->id, 'subject' => 'Issue']);
        $this->actingAs($u)->patchJson('/api/support/'.$t->id.'/status', ['status' => 'resolved', 'reason' => 'Done'])->assertForbidden();
        $this->actingAs($a)->patchJson('/api/support/'.$t->id.'/status', ['status' => 'closed', 'reason' => 'Done'])->assertConflict();
        $this->patchJson('/api/support/'.$t->id.'/status', ['status' => 'resolved', 'reason' => 'Investigated and resolved'])->assertOk();
        $this->actingAs($u)->postJson('/api/support/'.$t->id.'/messages', ['body' => 'Still broken'])->assertCreated();
        $this->assertSame('open', $t->fresh()->status);
        $this->actingAs($a)->patchJson('/api/support/'.$t->id.'/status', ['status' => 'resolved', 'reason' => 'Resolved again'])->assertOk();
        $this->patchJson('/api/support/'.$t->id.'/status', ['status' => 'closed', 'reason' => 'Confirmed'])->assertOk();
        $this->actingAs($u)->postJson('/api/support/'.$t->id.'/messages', ['body' => 'Reply'])->assertConflict();
        $this->actingAs($a)->patchJson('/api/support/'.$t->id.'/status', ['status' => 'open', 'reason' => 'Reopened by support'])->assertOk();
    }
}
