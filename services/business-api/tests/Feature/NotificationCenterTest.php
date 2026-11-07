<?php

namespace Tests\Feature;

use App\Models\InboxNotification;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private function makeNotification(User $user, string $kind = 'support.reply'): InboxNotification
    {
        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'Synthetic notification test',
        ]);

        return InboxNotification::create([
            'user_id' => $user->id,
            'kind' => $kind,
            'support_ticket_id' => $ticket->id,
        ]);
    }

    public function test_notification_defaults_are_private_and_external_delivery_is_not_claimed(): void
    {
        $user = User::factory()->create();
        $this->getJson('/api/notifications/preferences')->assertUnauthorized();
        $this->actingAs($user);
        foreach (['/api/notifications/preferences', '/api/v1/notifications/preferences'] as $url) {
            $this->getJson($url)->assertOk()
                ->assertJsonPath('default_category', 'all')
                ->assertJsonPath('default_unread_only', false)
                ->assertJsonPath('remind_unresolved_simulator_faults', true)
                ->assertJsonPath('in_app_only', true)
                ->assertJsonPath('external_delivery_available', false)
                ->assertJsonPath('critical_events_always_visible', true)
                ->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertDatabaseCount('notification_preferences', 0);
    }

    public function test_preferences_are_isolated_validated_and_idempotent_across_api_versions(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $original = $this->makeNotification($first, 'charging.fault.simulated');
        $input = [
            'default_category' => 'charging',
            'default_unread_only' => true,
            'remind_unresolved_simulator_faults' => false,
        ];
        $this->actingAs($first);
        $this->putJson('/api/notifications/preferences', $input)->assertOk()
            ->assertJsonPath('default_category', 'charging')
            ->assertJsonPath('default_unread_only', true)
            ->assertJsonPath('remind_unresolved_simulator_faults', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->putJson('/api/v1/notifications/preferences', $input)->assertOk();
        $this->assertDatabaseCount('notification_preferences', 1);
        $this->assertSame(1, DB::table('audit_events')
            ->where('actor_id', $first->id)
            ->where('action', 'notifications.preferences_changed')->count());

        // Preferences change only the initial UI choice. Recorded critical
        // events remain present, unread and accessible through either API.
        $this->getJson('/api/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 1);
        $this->assertNull($original->fresh()->read_at);
        $this->getJson('/api/notifications/summary')
            ->assertOk()->assertJsonPath('unread_by_category.charging', 1);

        $this->actingAs($second)->getJson('/api/notifications/preferences')
            ->assertOk()->assertJsonPath('default_category', 'all');
        $this->putJson('/api/v1/notifications/preferences', [
            'default_category' => 'fleet',
            'default_unread_only' => false,
            'remind_unresolved_simulator_faults' => true,
        ])->assertOk();
        $this->actingAs($first)->getJson('/api/notifications/preferences')
            ->assertOk()->assertJsonPath('default_category', 'charging');
        $this->assertDatabaseCount('notification_preferences', 2);
    }

    public function test_preference_validation_never_alters_saved_choices(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $valid = [
            'default_category' => 'fleet',
            'default_unread_only' => false,
            'remind_unresolved_simulator_faults' => true,
        ];
        $this->putJson('/api/notifications/preferences', $valid)->assertOk();
        foreach ([
            ['default_category' => 'billing'],
            ['default_unread_only' => 'sometimes'],
            ['remind_unresolved_simulator_faults' => null],
        ] as $override) {
            $this->putJson('/api/notifications/preferences', array_replace($valid, $override))
                ->assertUnprocessable();
        }
        $this->putJson('/api/notifications/preferences', [
            'default_category' => 'charging',
        ])->assertUnprocessable();
        $this->getJson('/api/notifications/preferences')
            ->assertOk()->assertJsonPath('default_category', 'fleet');
    }

    public function test_heavy_inbox_read_traffic_cannot_exhaust_support_rate_limit(): void
    {
        $this->actingAs(User::factory()->create());
        // More than the 30/min support-action budget but under the dedicated
        // notification-read budget; no entries or mutations are required.
        for ($i = 0; $i < 35; $i++) {
            $this->getJson('/api/notifications/summary')->assertOk();
            $this->getJson('/api/notifications')->assertOk();
        }
        $this->getJson('/api/support')->assertOk();
        $this->postJson('/api/support', [
            'subject' => 'Can still contact support',
            'body' => 'Inbox views must not consume my support rate limit.',
        ])->assertCreated();
    }

    public function test_native_inbox_reads_do_not_consume_general_platform_budget(): void
    {
        $this->actingAs(User::factory()->create());
        // Notification reads use 120/min, while ordinary platform actions
        // retain their independent 60/min budget.
        for ($i = 0; $i < 65; $i++) {
            $this->getJson('/api/v1/notifications/summary')->assertOk();
        }
        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_notification_writes_have_independent_bounded_rate_limit(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/notifications/read-through', [
                'through_id' => 1,
            ])->assertOk()->assertJsonPath('marked_read', 0);
        }
        $this->postJson('/api/notifications/read-through', [
            'through_id' => 1,
        ])->assertStatus(429);
        $this->getJson('/api/notifications/summary')->assertOk();
        $this->getJson('/api/support')->assertOk();
    }

    public function test_private_notification_routes_disable_browser_and_proxy_caching(): void
    {
        $user = User::factory()->create();
        $note = $this->makeNotification($user);
        $this->actingAs($user);

        foreach ([
            '/api/notifications',
            '/api/v1/notifications',
            '/api/notifications/summary',
            '/api/v1/notifications/summary',
        ] as $url) {
            $this->getJson($url)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->postJson('/api/notifications/'.$note->id.'/read')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/notifications/'.$note->id.'/unread')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/notifications/read-through', [
            'through_id' => $note->id,
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_bulk_cutoff_rejects_unrepresentable_large_database_ids(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/notifications/read-through', [
            'through_id' => '9223372036854775808',
        ])->assertUnprocessable();
    }

    public function test_anonymous_access_and_invalid_cutoff_are_rejected(): void
    {
        $this->getJson('/api/v1/notifications/summary')->assertUnauthorized();
        $this->postJson('/api/v1/notifications/read-through', ['through_id' => 1])->assertUnauthorized();

        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/notifications/read-through', [])->assertUnprocessable();
        $this->postJson('/api/v1/notifications/read-through', ['through_id' => 0])->assertUnprocessable();
        $this->postJson('/api/v1/notifications/read-through', ['through_id' => -1])->assertUnprocessable();
        $this->postJson('/api/v1/notifications/read-through', ['through_id' => 'not-an-id'])->assertUnprocessable();
    }

    public function test_summary_is_scoped_to_current_account_and_empty_state_is_safe(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner)->getJson('/api/v1/notifications/summary')
            ->assertOk()->assertExactJson([
                'unread_count' => 0,
                'latest_id' => 0,
                'unread_by_category' => ['support' => 0, 'vehicle' => 0, 'charging' => 0, 'fleet' => 0, 'other' => 0],
                'in_app_only' => true,
            ]);

        $one = $this->makeNotification($owner);
        $two = $this->makeNotification($owner);
        $this->makeNotification($foreign);

        $this->getJson('/api/v1/notifications/summary')->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('latest_id', $two->id);
        $this->postJson('/api/v1/notifications/read-through', ['through_id' => $one->id])
            ->assertOk()->assertJsonPath('marked_read', 1)
            ->assertJsonPath('remaining_unread', 1)
            ->assertJsonPath('through_id', $one->id);
        $this->assertNotNull($one->fresh()->read_at);
        $this->assertNull($two->fresh()->read_at);

        $this->actingAs($foreign)->getJson('/api/v1/notifications/summary')
            ->assertOk()->assertJsonPath('unread_count', 1);
    }

    public function test_category_unread_summary_has_consistent_totals_and_excludes_other_accounts(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $this->makeNotification($owner, 'support.reply');
        $this->makeNotification($owner, 'vehicle.battery.simulated');
        $this->makeNotification($owner, 'charging.fault.simulated');
        $this->makeNotification($owner, 'fleet.invitation');
        $unknown = $this->makeNotification($owner, 'platform.security.notice');
        $this->makeNotification($foreign, 'fleet.invitation');
        $unknown->update(['read_at' => now()]);
        $this->actingAs($owner)->getJson('/api/v1/notifications/summary')
            ->assertOk()
            ->assertJsonPath('unread_count', 4)
            ->assertJsonPath('unread_by_category.support', 1)
            ->assertJsonPath('unread_by_category.vehicle', 1)
            ->assertJsonPath('unread_by_category.charging', 1)
            ->assertJsonPath('unread_by_category.fleet', 1)
            ->assertJsonPath('unread_by_category.other', 0);
        $this->actingAs($foreign)->getJson('/api/notifications/summary')
            ->assertOk()->assertJsonPath('unread_count', 1)
            ->assertJsonPath('unread_by_category.fleet', 1)
            ->assertJsonPath('unread_by_category.charging', 0);
        $unknown->update(['read_at' => null]);
        $this->actingAs($owner)->getJson('/api/notifications/summary')
            ->assertOk()->assertJsonPath('unread_count', 5)
            ->assertJsonPath('unread_by_category.other', 1);
    }

    public function test_summary_high_water_does_not_reset_after_read_and_is_single_account_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->makeNotification($owner);
        $last = $this->makeNotification($owner);
        $this->makeNotification($other);
        $this->actingAs($owner)->postJson('/api/notifications/read-through', [
            'through_id' => $last->id,
        ])->assertOk()->assertJsonPath('remaining_unread', 0);

        $this->getJson('/api/notifications/summary')->assertOk()->assertExactJson([
            'unread_count' => 0,
            'latest_id' => $last->id,
            'unread_by_category' => ['support' => 0, 'vehicle' => 0, 'charging' => 0, 'fleet' => 0, 'other' => 0],
            'in_app_only' => true,
        ]);
        $newer = $this->makeNotification($owner);
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertExactJson([
            'unread_count' => 1,
            'latest_id' => $newer->id,
            'unread_by_category' => ['support' => 1, 'vehicle' => 0, 'charging' => 0, 'fleet' => 0, 'other' => 0],
            'in_app_only' => true,
        ]);
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($newer->fresh()->read_at);
    }

    public function test_bulk_read_is_idempotent_and_never_consumes_newer_messages(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $n1 = $this->makeNotification($owner);
        $n2 = $this->makeNotification($owner);
        $foreign = $this->makeNotification($other);

        $this->actingAs($owner);
        $first = $this->postJson('/api/v1/notifications/read-through', ['through_id' => $n2->id])
            ->assertOk()->assertJsonPath('marked_read', 2)
            ->assertJsonPath('remaining_unread', 0);
        $this->assertSame(0, $this->postJson('/api/v1/notifications/read-through', [
            'through_id' => $n2->id,
        ])->assertOk()->json('marked_read'));

        $later = $this->makeNotification($owner);
        $this->postJson('/api/v1/notifications/read-through', ['through_id' => $n2->id])
            ->assertOk()->assertJsonPath('remaining_unread', 1);
        $this->assertNull($later->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertSame(1, DB::table('audit_events')
            ->where('actor_id', $owner->id)->where('action', 'notifications.read_through')->count());

        // A very high cutoff can never cross account scope.
        $this->postJson('/api/v1/notifications/read-through', [
            'through_id' => $foreign->id + 1000,
        ])->assertOk()->assertJsonPath('marked_read', 1);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_unread_only_filter_is_identical_for_both_apis_and_cannot_leak_foreign_messages(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $read = $this->makeNotification($owner);
        $unread = $this->makeNotification($owner);
        $this->makeNotification($foreign);
        $read->update(['read_at' => now()]);

        $this->actingAs($owner)->getJson('/api/notifications?unread_only=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unread->id);
        $this->getJson('/api/v1/notifications?unread_only=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $unread->id);
        $this->getJson('/api/v1/notifications?unread_only=0')->assertOk()
            ->assertJsonCount(2, 'data');
        $this->getJson('/api/notifications?unread_only=invalid')->assertUnprocessable();
        $this->postJson('/api/v1/notifications/'.$read->id.'/read')->assertOk();
        $this->actingAs($foreign)->getJson('/api/notifications?unread_only=1')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_notification_categories_are_fixed_validated_scoped_and_paginated(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $support = $this->makeNotification($owner, 'support.reply');
        $vehicle = $this->makeNotification($owner, 'vehicle.battery.simulated');
        $fault = $this->makeNotification($owner, 'charging.fault.simulated');
        $this->makeNotification($outsider, 'charging.fault.simulated');

        $this->actingAs($owner)
            ->getJson('/api/v1/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $fault->id);
        $this->getJson('/api/notifications?category=vehicle')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $vehicle->id);
        $this->getJson('/api/notifications?category=support')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $support->id);

        $this->postJson('/api/notifications/read-through', ['through_id' => $support->id])
            ->assertOk()->assertJsonPath('remaining_unread', 2);
        $this->getJson('/api/v1/notifications?category=support&unread_only=1')
            ->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/notifications?category=fleet')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/notifications?category=charger')
            ->assertUnprocessable();
        $this->getJson('/api/notifications?category=charging%25')
            ->assertUnprocessable();

        $this->actingAs($outsider)->getJson('/api/v1/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 1);
    }

    public function test_notification_pagination_rejects_unbounded_or_non_numeric_pages(): void
    {
        $owner = User::factory()->create();
        $this->makeNotification($owner);
        $this->actingAs($owner);
        foreach (['/api/notifications', '/api/v1/notifications'] as $base) {
            foreach (['0', '-4', 'nope', '999999999', '1.5'] as $page) {
                $this->getJson($base.'?page='.$page)->assertUnprocessable();
            }
            $this->getJson($base.'?page=1')->assertOk()->assertJsonPath('total', 1);
        }
    }

    public function test_other_category_exposes_only_unclassified_notifications_and_can_be_saved(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $system = $this->makeNotification($owner, 'platform.security.notice');
        $unknown = $this->makeNotification($owner, 'operations.dispatch.review');
        $this->makeNotification($owner, 'support.reply');
        $this->makeNotification($owner, 'charging.fault.simulated');
        $this->makeNotification($outsider, 'platform.security.notice');

        $this->actingAs($owner);
        foreach (['/api/notifications', '/api/v1/notifications'] as $base) {
            $this->getJson($base.'?category=other')->assertOk()
                ->assertJsonPath('total', 2)
                ->assertJsonPath('data.0.id', $unknown->id)
                ->assertJsonPath('data.1.id', $system->id);
        }
        $this->getJson('/api/notifications/summary')->assertOk()
            ->assertJsonPath('unread_by_category.other', 2);
        // Older deployed clients only understand the original saved defaults.
        // The new other category is a transient filter, never a breaking
        // persisted preference that would prevent legacy inbox loading.
        $this->putJson('/api/notifications/preferences', [
            'default_category' => 'other',
            'default_unread_only' => true,
            'remind_unresolved_simulator_faults' => true,
        ])->assertUnprocessable();
        $system->update(['read_at' => now()]);
        $this->getJson('/api/v1/notifications?category=other&unread_only=1')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $unknown->id);
        $this->getJson('/api/v1/notifications/preferences')->assertOk()
            ->assertJsonPath('default_category', 'all');
        $this->actingAs($outsider)->getJson('/api/notifications?category=other')
            ->assertOk()->assertJsonPath('total', 1);
    }

    public function test_inbox_pages_are_bounded_sorted_and_never_expose_another_account(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        for ($i = 0; $i < 27; $i++) {
            $this->makeNotification($owner, 'support.reply');
        }
        $this->makeNotification($foreign, 'support.reply');

        $this->actingAs($owner)->getJson('/api/v1/notifications?category=support&page=1')
            ->assertOk()->assertJsonPath('total', 27)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)->assertJsonCount(25, 'data');
        $page = $this->getJson('/api/v1/notifications?category=support&page=2')
            ->assertOk()->assertJsonPath('total', 27)
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(2, 'data');
        $this->assertTrue($page->json('data.0.id') > $page->json('data.1.id'));
        $this->actingAs($foreign)->getJson('/api/notifications?page=2')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonCount(0, 'data');
    }

    public function test_unread_reversal_is_account_scoped_idempotent_and_does_not_duplicate_events(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $note = $this->makeNotification($owner);
        $foreign = $this->makeNotification($other);

        $this->actingAs($owner)->postJson('/api/notifications/'.$note->id.'/read')
            ->assertOk()->assertJsonPath('id', $note->id);
        $this->postJson('/api/v1/notifications/'.$note->id.'/unread')
            ->assertOk()->assertJsonPath('id', $note->id)->assertJsonPath('read_at', null);
        $this->postJson('/api/notifications/'.$note->id.'/unread')
            ->assertOk()->assertJsonPath('read_at', null);
        $this->assertDatabaseCount('inbox_notifications', 2);
        $this->getJson('/api/notifications/summary')->assertOk()->assertJsonPath('unread_count', 1);
        $this->postJson('/api/notifications/'.$foreign->id.'/unread')->assertNotFound();
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertSame(1, DB::table('audit_events')->where('actor_id', $owner->id)
            ->where('action', 'notifications.unread_one')
            ->where('subject', (string) $note->id)->count());
    }

    public function test_single_read_acknowledgement_is_audited_once_and_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $note = $this->makeNotification($owner);
        $outsiderNote = $this->makeNotification($foreign);

        $this->actingAs($owner)->postJson('/api/v1/notifications/'.$note->id.'/read')
            ->assertOk()->assertJsonPath('id', $note->id);
        $this->postJson('/api/notifications/'.$note->id.'/read')
            ->assertOk()->assertJsonPath('id', $note->id);

        $this->postJson('/api/v1/notifications/'.$outsiderNote->id.'/read')->assertNotFound();
        $this->assertNull($outsiderNote->fresh()->read_at);
        $this->assertSame(1, DB::table('audit_events')
            ->where('actor_id', $owner->id)
            ->where('action', 'notifications.read_one')
            ->where('subject', (string) $note->id)
            ->count());
    }

    public function test_legacy_notification_routes_remain_available_and_scoped(): void
    {
        $owner = User::factory()->create();
        $n = $this->makeNotification($owner);
        $this->actingAs($owner)->getJson('/api/notifications')
            ->assertOk()->assertJsonPath('data.0.id', $n->id);
        $this->getJson('/api/notifications/summary')
            ->assertOk()->assertJsonPath('unread_count', 1);
        $this->postJson('/api/notifications/read-through', ['through_id' => $n->id])
            ->assertOk()->assertJsonPath('remaining_unread', 0);
        $this->postJson('/api/notifications/'.$n->id.'/read')
            ->assertOk()->assertJsonPath('id', $n->id);
    }
}
