<?php

namespace Tests\Feature;

use App\Http\Controllers\SupportController;
use App\Models\InboxNotification;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_support_ticket_create_replays_once_across_api_versions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $payload = [
            'subject' => 'Cannot charge at the verified station',
            'body' => 'Please review this synthetic station event',
            'idempotency_key' => str_repeat('d', 32),
        ];
        $first = $this->postJson('/api/support', $payload)
            ->assertCreated()->assertJsonMissingPath('idempotency_key');
        $this->postJson('/api/v1/support', $payload)
            ->assertOk()->assertJsonPath('id', $first->json('id'))
            ->assertJsonMissingPath('idempotency_key');
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('ticket_messages', 1);
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'support.created')->where('actor_id', $user->id)->count());

        $this->postJson('/api/support', [...$payload, 'subject' => 'Other issue'])
            ->assertConflict();
        $this->postJson('/api/v1/support', [...$payload, 'body' => 'Other details'])
            ->assertConflict();
        foreach (['short', str_repeat('g', 32), str_repeat('a', 33)] as $bad) {
            $this->postJson('/api/support', [...$payload, 'idempotency_key' => $bad])
                ->assertUnprocessable();
        }
        $this->assertDatabaseCount('support_tickets', 1);

        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/support', $payload)
            ->assertCreated();
        $this->assertDatabaseCount('support_tickets', 2);
        // Older clients without a replay key retain their original behavior.
        $this->postJson('/api/support', [
            'subject' => 'Legacy no replay key', 'body' => 'New ticket',
        ])->assertCreated();
        $this->assertDatabaseCount('support_tickets', 3);
    }

    public function test_requester_view_is_admin_only_ticket_scoped_audited_and_keeps_the_admin_identity(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create();
        $this->actingAs($owner)->postJson('/api/support', [
            'subject' => 'Requester view fixture', 'body' => 'Requester-visible question',
        ])->assertCreated();
        $ticket = SupportTicket::where('user_id', $owner->id)->firstOrFail();
        $url = '/api/support/'.$ticket->id;
        $this->getJson($url.'?view=requester')->assertForbidden();
        $this->actingAs($other)->getJson($url.'?view=requester')->assertNotFound();

        $this->actingAs($admin)->postJson($url.'/messages', [
            'body' => 'Private staff note', 'internal' => true,
        ])->assertCreated();
        $this->getJson($url.'?view=requester')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('view', 'requester')->assertJsonPath('messages.total', 1)
            ->assertJsonPath('messages.first_page_url', fn (string $url) => str_contains($url, 'view=requester'))
            ->assertSee('Requester-visible question')->assertDontSee('Private staff note');
        $this->getJson('/api/me')->assertOk()->assertJsonPath('id', $admin->id)->assertJsonPath('role', 'admin');
        $this->getJson($url)->assertOk()->assertSee('Private staff note')->assertJsonMissingPath('view');
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id, 'action' => 'support.requester_view_previewed', 'subject' => (string) $ticket->id,
        ]);
        $this->assertDatabaseCount('ticket_messages', 2);

        config(['admin_security.enforce_in_local_tests' => true]);
        $this->getJson($url.'?view=requester')->assertForbidden()->assertJsonPath('admin_second_factor_required', true);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'support.requester_view_previewed')->count());
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

    public function test_reply_replay_reuses_message_and_does_not_duplicate_owner_notification(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create(['user_id' => $owner->id, 'subject' => 'Safe reply replay']);
        $key = str_repeat('a', 32);
        $body = [
            'body' => 'Public response after remote source investigation',
            'internal' => false,
            'idempotency_key' => $key,
        ];

        $this->actingAs($admin);
        $first = $this->postJson('/api/support/'.$ticket->id.'/messages', $body)
            ->assertCreated()->assertJsonMissingPath('idempotency_key');
        $second = $this->postJson('/api/support/'.$ticket->id.'/messages', $body)
            ->assertOk()->assertJsonPath('id', $first->json('id'))
            ->assertJsonMissingPath('idempotency_key');
        $this->assertDatabaseCount('ticket_messages', 1);
        $this->assertDatabaseCount('inbox_notifications', 1);
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'support.replied')
            ->where('actor_id', $admin->id)->count());
        $this->postJson('/api/support/'.$ticket->id.'/messages',
            [...$body, 'body' => 'Changed response under the same key'])
            ->assertConflict();
        $this->assertDatabaseCount('ticket_messages', 1);

        // Once resolved then closed, an uncertain network acknowledgement
        // remains replay-safe; a different key cannot reopen a closed ticket.
        $this->patchJson('/api/support/'.$ticket->id.'/status', [
            'status' => 'resolved', 'reason' => 'Confirmed investigation',
        ])->assertOk();
        $this->patchJson('/api/support/'.$ticket->id.'/status', [
            'status' => 'closed', 'reason' => 'Confirmed closure',
        ])->assertOk();
        $this->postJson('/api/support/'.$ticket->id.'/messages', $body)
            ->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->postJson('/api/support/'.$ticket->id.'/messages',
            [...$body, 'idempotency_key' => str_repeat('b', 32)])
            ->assertConflict();

        // Another actor can reuse the same random key on this ticket but
        // cannot read or replay the Admin's private request identity.
        $this->actingAs($owner)->postJson('/api/support/'.$ticket->id.'/messages', $body)
            ->assertConflict();
        $this->assertDatabaseCount('ticket_messages', 3);
    }

    public function test_idempotency_key_format_and_payload_scope_are_validated(): void
    {
        $owner = User::factory()->create();
        $ticket = SupportTicket::create(['user_id' => $owner->id, 'subject' => 'Replay validation']);
        $this->actingAs($owner);
        foreach (['short', str_repeat('a', 31), str_repeat('z', 32),
            str_repeat('a', 33)] as $invalid) {
            $this->postJson('/api/v1/support/'.$ticket->id.'/messages', [
                'body' => 'No unsafe duplicates', 'idempotency_key' => $invalid,
            ])->assertUnprocessable();
        }
        $this->postJson('/api/v1/support/'.$ticket->id.'/messages', [
            'body' => 'One safe public reply',
            'idempotency_key' => str_repeat('c', 32),
        ])->assertCreated();
        $this->postJson('/api/support/'.$ticket->id.'/messages', [
            'body' => 'One safe public reply',
            'idempotency_key' => str_repeat('c', 32),
        ])->assertOk();
        $this->assertDatabaseCount('ticket_messages', 1);
        $this->assertDatabaseCount('inbox_notifications', 0);
    }

    public function test_admin_status_transition_retry_has_one_notice_and_audit_event(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create([
            'user_id' => $owner->id, 'subject' => 'Source monitor review',
        ]);
        $this->actingAs($admin);
        $url = '/api/support/'.$ticket->id.'/status';
        $payload = [
            'status' => 'resolved',
            'reason' => 'The simulated source incident was reviewed',
            'idempotency_key' => str_repeat('e', 32),
        ];
        $this->patchJson($url, $payload)->assertOk()
            ->assertJsonPath('status', 'resolved');
        $this->patchJson($url, $payload)->assertOk()
            ->assertJsonPath('status', 'resolved');
        $this->assertDatabaseCount('ticket_messages', 1);
        $this->assertDatabaseCount('inbox_notifications', 1);
        $this->assertSame(1, DB::table('audit_events')
            ->where('actor_id', $admin->id)
            ->where('action', 'support.status.resolved')->count());
        $this->patchJson($url, [...$payload, 'reason' => 'Conflicting replay'])
            ->assertConflict();
        $this->patchJson($url, [...$payload, 'status' => 'closed'])
            ->assertConflict();
        // A key first used by a status transition must not be replayed as
        // a public support reply (same body is not enough).
        $this->postJson('/api/support/'.$ticket->id.'/messages', [
            'body' => $payload['reason'],
            'idempotency_key' => $payload['idempotency_key'],
        ])->assertConflict();
        $this->assertDatabaseCount('ticket_messages', 1);

        $this->patchJson($url, [
            'status' => 'closed', 'reason' => 'Operator confirmed closure',
            'idempotency_key' => str_repeat('f', 32),
        ])->assertOk();
        // A stale replay cannot falsely report the earlier state.
        $this->patchJson($url, $payload)->assertConflict();
        $this->assertDatabaseCount('ticket_messages', 2);
        $this->assertDatabaseCount('inbox_notifications', 2);
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

    public function test_cached_admin_role_revocation_blocks_cross_owner_support_access_and_mutations(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create([
            'user_id' => $owner->id,
            'subject' => 'Private customer support request',
        ]);

        $this->actingAs($admin);
        $this->getJson('/api/support')->assertOk()->assertJsonPath('total', 1);
        DB::table('users')->where('id', $admin->id)->update(['role' => 'customer']);
        // The authenticated PHP model still says Admin, but database
        // revocation must govern both legacy and v1 support routes.
        $this->assertSame('admin', $admin->role);
        foreach (['/api/support' => 403, '/api/v1/support' => 404] as $base => $denial) {
            $this->getJson($base)->assertOk()->assertJsonPath('total', 0);
            $this->getJson($base.'/'.$ticket->id)->assertNotFound();
            $this->postJson($base.'/'.$ticket->id.'/messages', [
                'body' => 'No private stale-role response',
                'internal' => true,
            ])->assertNotFound();
            // Legacy and v1 routes have distinct denial codes; neither
            // may allow a stale Admin session to transition another ticket.
            $this->patchJson($base.'/'.$ticket->id.'/status', [
                'status' => 'resolved',
                'reason' => 'No stale-role resolution',
            ])->assertStatus($denial);
        }
        $this->assertDatabaseCount('ticket_messages', 0);
        $this->assertDatabaseCount('inbox_notifications', 0);
        $this->assertSame('open', $ticket->fresh()->status);

        DB::table('users')->where('id', $admin->id)->update(['role' => 'admin']);
        $this->postJson('/api/support/'.$ticket->id.'/messages', [
            'body' => 'Privileged internal investigation',
            'internal' => true,
        ])->assertCreated();
        $this->assertDatabaseCount('ticket_messages', 1);
        $this->assertDatabaseCount('inbox_notifications', 0);
    }

    public function test_stale_admin_cannot_read_other_support_or_mutate_private_ticket_after_credentials_change(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create([
            'user_id' => $owner->id, 'subject' => 'Confidential support case',
        ]);
        $this->actingAs($admin, 'web');
        $this->getJson('/api/support')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/support/'.$ticket->id)->assertOk();

        DB::table('users')->where('id', $admin->id)->update(['auth_epoch' => 2]);
        foreach (['/api/support', '/api/v1/support',
            '/api/support/'.$ticket->id, '/api/v1/support/'.$ticket->id] as $path) {
            $this->getJson($path)->assertStatus(409);
        }
        $this->postJson('/api/support/'.$ticket->id.'/messages', [
            'body' => 'Stale privileged note', 'internal' => true,
        ])->assertStatus(409);
        $this->patchJson('/api/support/'.$ticket->id.'/status', [
            'status' => 'resolved', 'reason' => 'Stale privileged decision',
        ])->assertStatus(409);

        DB::table('users')->where('id', $admin->id)->update([
            'auth_epoch' => 0, 'password' => bcrypt('new-admin-secret'),
        ]);
        $this->getJson('/api/support')->assertStatus(409);
        $this->getJson('/api/support/'.$ticket->id)->assertStatus(409);
        $this->postJson('/api/support/'.$ticket->id.'/messages', [
            'body' => 'New password cannot revive cached authority', 'internal' => true,
        ])->assertStatus(409);
        $this->assertDatabaseCount('ticket_messages', 0);
        $this->assertDatabaseCount('inbox_notifications', 0);
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseCount('audit_events', 0);

        // Fresh authenticated Admin authority can still investigate.
        $fresh = User::factory()->create(['role' => 'admin']);
        $this->actingAs($fresh, 'web')->getJson('/api/support/'.$ticket->id)
            ->assertOk()->assertJsonPath('ticket.id', $ticket->id)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_suspended_admin_cannot_read_or_change_private_support_cases(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create(['user_id' => $owner->id, 'subject' => 'Private owner complaint']);
        $this->actingAs($admin, 'web');

        DB::table('users')->where('id', $admin->id)->update(['account_status' => 'suspended']);
        $this->getJson('/api/support')->assertForbidden();
        $this->getJson('/api/support/'.$ticket->id)->assertForbidden();
        $this->postJson('/api/support/'.$ticket->id.'/messages', [
            'body' => 'Suspended internal note', 'internal' => true,
        ])->assertForbidden();
        $this->patchJson('/api/support/'.$ticket->id.'/status', [
            'status' => 'resolved', 'reason' => 'Suspended status change',
        ])->assertForbidden();
        $this->assertDatabaseCount('ticket_messages', 0);
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_stale_route_bound_support_ticket_owner_change_cannot_disclose_private_messages(): void
    {
        $firstOwner = User::factory()->create(['role' => 'customer']);
        $newOwner = User::factory()->create(['role' => 'customer']);
        $ticket = SupportTicket::create([
            'user_id' => $firstOwner->id, 'subject' => 'Reassigned private case',
        ]);
        // Simulate reassignment after route binding but before controller
        // authorization. The stale PHP object must not authorize access.
        DB::table('support_tickets')->where('id', $ticket->id)
            ->update(['user_id' => $newOwner->id]);
        $request = Request::create('/api/support/'.$ticket->id, 'GET');
        $request->setUserResolver(fn () => $firstOwner);
        try {
            app(SupportController::class)->show($request, $ticket);
            $this->fail('Stale route model allowed foreign ticket disclosure.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $fresh = Request::create('/api/support/'.$ticket->id, 'GET');
        $fresh->setUserResolver(fn () => $newOwner);
        $response = app(SupportController::class)->show($fresh, $ticket);
        $this->assertSame($newOwner->id, $response->getData(true)['ticket']['user_id']);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }
}
