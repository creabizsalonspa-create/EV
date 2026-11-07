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
        $this->getJson('/api/v1/notifications?category=charger')
            ->assertUnprocessable();
        $this->getJson('/api/notifications?category=charging%25')
            ->assertUnprocessable();

        $this->actingAs($outsider)->getJson('/api/v1/notifications?category=charging')
            ->assertOk()->assertJsonPath('total', 1);
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
