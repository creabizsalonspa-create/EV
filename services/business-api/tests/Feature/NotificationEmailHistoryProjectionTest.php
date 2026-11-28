<?php

namespace Tests\Feature;

use App\Models\InboxNotification;
use App\Models\User;
use App\Services\Notifications\EmailNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationEmailHistoryProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_corrupt_private_history_never_echoes_untrusted_codes_or_promotes_delivery(): void
    {
        config(['notification_email.enabled' => true, 'app.url' => 'https://platform.example',
            'mail.default' => 'array', 'mail.from.address' => 'notices@example.test']);
        Mail::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/api/notifications/email', [
            'enabled' => true, 'confirmed' => true, 'expected_revision' => '0',
        ])->assertOk();
        InboxNotification::create(['user_id' => $user->id, 'kind' => 'other.account_event']);
        $service = app(EmailNotices::class);
        $this->assertSame(1, $service->prepare());
        $row = DB::table('notification_email_outbox')->where('user_id', $user->id)->first();
        $url = '/api/notifications/email/history';
        $this->getJson($url)->assertOk()->assertJsonPath('receipts.data.0.state', 'queued')
            ->assertJsonPath('receipts.data.0.evidence', 'SIMULATED_EMAIL_HANDOFF');
        DB::table('notification_email_outbox')->where('id', $row->id)->update([
            'state' => 'handed_off', 'evidence' => 'PRIVATE-TRANSPORT-SECRET',
            'failure_code' => 'PRIVATE-EXCEPTION',
        ]);
        $response = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('receipts.total', 1)->assertJsonPath('receipts.data.0.state', 'review_required')
            ->assertJsonPath('receipts.data.0.evidence', null)->assertJsonPath('receipts.data.0.failure_code', null)
            ->assertJsonPath('delivery_confirmed', false)->assertJsonPath('automatic_retry', false);
        $this->assertStringNotContainsString('PRIVATE-', $response->getContent());
        $this->getJson($url.'?page=100001')->assertUnprocessable();
        $this->assertDatabaseHas('notification_email_outbox', ['id' => $row->id, 'state' => 'handed_off',
            'evidence' => 'PRIVATE-TRANSPORT-SECRET', 'failure_code' => 'PRIVATE-EXCEPTION']);
        $this->actingAs(User::factory()->create())->getJson($url)->assertOk()->assertJsonPath('receipts.total', 0);
        Mail::assertNothingSent();
    }
}
