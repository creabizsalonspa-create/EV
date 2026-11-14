<?php

namespace Tests\Feature;

use App\Mail\GenericInboxNotice;
use App\Models\InboxNotification;
use App\Models\User;
use App\Services\Notifications\EmailNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NotificationEmailTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): User
    {
        config(['notification_email.enabled' => true, 'app.url' => 'https://platform.example', 'mail.default' => 'array', 'mail.from.address' => 'notices@example.test']);
        Mail::fake();

        return User::factory()->create();
    }

    private function notice(User $user): InboxNotification
    {
        return InboxNotification::create(['user_id' => $user->id, 'kind' => 'other.account_event', 'metadata' => ['private_token' => 'DO-NOT-EMAIL-PRIVATE', 'private_finance' => 'DO-NOT-EMAIL-AMOUNTS']]);
    }

    private function enable(User $user): void
    {
        $this->actingAs($user)->putJson('/api/notifications/email', ['enabled' => true, 'confirmed' => true, 'expected_revision' => '0'])->assertOk()->assertJsonPath('consent_current', true);
    }

    public function test_explicit_verified_opt_in_sends_one_generic_digest_for_new_unread_activity_and_never_marks_delivery_confirmed(): void
    {
        $user = $this->fixture();
        $this->notice($user); // History before opt-in is never mailed.
        $this->actingAs($user)->putJson('/api/notifications/email', ['enabled' => true, 'expected_revision' => '0'])->assertUnprocessable();
        $this->enable($user);
        $service = app(EmailNotices::class);
        $this->assertSame(0, $service->prepare());
        $this->notice($user);
        $this->notice($user);
        $this->assertSame(1, $service->prepare());
        $this->assertSame(0, $service->prepare());
        $row = DB::table('notification_email_outbox')->first();
        $this->assertSame('handed_off', $service->deliver($row->id));
        $this->assertSame('not_attempted', $service->deliver($row->id));
        Mail::assertSent(GenericInboxNotice::class, 1);
        Mail::assertSent(GenericInboxNotice::class, fn ($mail) => $mail->hasTo($user->email) && $mail->receiptId === $row->id);
        $body = (new GenericInboxNotice('en', 'https://platform.example', $row->id))->render();
        $this->assertStringNotContainsString('DO-NOT-EMAIL', $body);
        $this->assertStringContainsString('https://platform.example/', $body);
        $this->getJson('/api/notifications/email/history')->assertOk()->assertJsonPath('delivery_confirmed', false)->assertJsonPath('automatic_retry', false)->assertJsonPath('receipts.data.0.state', 'handed_off')->assertDontSee($user->email)->assertDontSee('recipient_hash');
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_digest_cooldown_and_old_read_or_deleted_activity_never_creates_duplicate_email(): void
    {
        $user = $this->fixture();
        $this->enable($user);
        $first = $this->notice($user);
        $service = app(EmailNotices::class);
        $service->run();
        $this->notice($user);
        $this->assertSame(0, $service->prepare());
        $this->travel(16)->minutes();
        $this->assertSame(1, $service->prepare());
        $row = DB::table('notification_email_outbox')->where('state', 'queued')->first();
        DB::table('inbox_notifications')->where('user_id', $user->id)->update(['read_at' => now()]);
        $this->assertSame('cancelled', $service->deliver($row->id));
        $first->delete();
        Mail::assertSent(GenericInboxNotice::class, 1);
        $this->assertSame(0, $service->prepare());
    }

    public function test_revocation_or_recipient_change_after_prepare_prevents_handoff_and_queued_opt_out_is_immediate(): void
    {
        $user = $this->fixture();
        $this->enable($user);
        $this->notice($user);
        $service = app(EmailNotices::class);
        $service->prepare();
        $row = DB::table('notification_email_outbox')->first();
        DB::table('users')->where('id', $user->id)->increment('auth_epoch');
        $this->assertSame('cancelled', $service->deliver($row->id));
        Mail::assertNothingSent();
        $fresh = $user->fresh();
        $this->actingAs($fresh)->withSession(['account_auth_epoch' => $fresh->auth_epoch])->putJson('/api/notifications/email', ['enabled' => true, 'confirmed' => true, 'expected_revision' => '1'])->assertOk();
        $this->notice($fresh);
        $service->prepare();
        $this->putJson('/api/notifications/email', ['enabled' => false, 'expected_revision' => '0'])->assertOk()->assertJsonPath('enabled', false);
        $this->assertSame(0, DB::table('notification_email_outbox')->where('state', 'queued')->count());
        Mail::assertNothingSent();
    }

    public function test_unknown_transport_outcome_is_never_retried_or_reported_as_delivery(): void
    {
        $user = $this->fixture();
        $this->enable($user);
        $this->notice($user);
        $service = app(EmailNotices::class);
        $service->prepare();
        $row = DB::table('notification_email_outbox')->first();
        Mail::shouldReceive('purge')->once();
        Mail::shouldReceive('mailer')->with('notification-email')->once()->andThrow(new \RuntimeException('PRIVATE SMTP ACK LOST'));
        $this->assertSame('outcome_unknown', $service->deliver($row->id));
        $this->assertSame('not_attempted', $service->deliver($row->id));
        $this->assertSame(0, $service->prepare());
        $this->getJson('/api/notifications/email/history')->assertOk()->assertJsonPath('receipts.data.0.state', 'outcome_unknown')->assertJsonPath('delivery_confirmed', false)->assertDontSee('PRIVATE SMTP');
    }

    public function test_transport_configuration_and_verified_email_are_required_without_accepting_arbitrary_recipients(): void
    {
        $user = $this->fixture();
        $this->actingAs($user)->putJson('/api/notifications/email', ['enabled' => true, 'confirmed' => true, 'expected_revision' => '0', 'email' => 'other@example.test'])->assertUnprocessable();
        config(['notification_email.enabled' => false]);
        $this->putJson('/api/notifications/email', ['enabled' => true, 'confirmed' => true, 'expected_revision' => '0'])->assertConflict();
        config(['notification_email.enabled' => true]);
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->putJson('/api/notifications/email', ['enabled' => true, 'confirmed' => true, 'expected_revision' => '0'])->assertUnprocessable();
        config(['app.url' => 'https://private@platform.example']);
        $this->assertNull(app(EmailNotices::class)->configuration());
        Mail::assertNothingSent();
    }

    public function test_actual_native_token_preferences_and_history_remain_scoped_and_admin_tokens_stay_forbidden(): void
    {
        $user = $this->fixture();
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('email-settings')->plainTextToken)->getJson('/api/v1/notifications/email')->assertOk()->assertJsonPath('revision', '0');
        $this->putJson('/api/v1/notifications/email', ['enabled' => true, 'confirmed' => true, 'expected_revision' => '0'])->assertOk();
        $this->notice($user);
        app(EmailNotices::class)->run();
        $this->getJson('/api/v1/notifications/email/history')->assertOk()->assertJsonPath('receipts.total', 1);
        $other = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('other-account')->plainTextToken)->getJson('/api/v1/notifications/email/history')->assertOk()->assertJsonPath('receipts.total', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken(User::factory()->create(['role' => 'admin'])->createToken('forbidden-admin')->plainTextToken)->getJson('/api/v1/notifications/email')->assertForbidden();
    }

    public function test_recipient_role_transport_and_deleted_notice_changes_cancel_before_any_send(): void
    {
        foreach (['recipient', 'role', 'transport', 'deleted', 'claimed'] as $change) {
            $user = $this->fixture();
            $this->enable($user);
            $notice = $this->notice($user);
            $service = app(EmailNotices::class);
            $service->prepare();
            $row = DB::table('notification_email_outbox')->where('user_id', $user->id)->first();
            if ($change === 'recipient') {
                DB::table('users')->where('id', $user->id)->update(['email' => 'changed-'.$user->id.'@example.test']);
            }
            if ($change === 'role') {
                DB::table('users')->where('id', $user->id)->update(['role' => 'vendor']);
            }
            if ($change === 'transport') {
                config(['mail.from.address' => 'changed@example.test']);
            }
            if ($change === 'deleted') {
                $notice->delete();
            }
            if ($change === 'claimed') {
                DB::table('notification_email_outbox')->where('id', $row->id)->update(['state' => 'claimed', 'claimed_at' => now()]);
            }
            $this->assertSame($change === 'claimed' ? 'not_attempted' : 'cancelled', $service->deliver($row->id), $change);
            $this->assertSame('not_attempted', $service->deliver($row->id));
            Mail::assertNothingSent();
            // Avoid another account's pending state affecting the next fixture.
            DB::table('notification_email_preferences')->where('user_id', $user->id)->update(['enabled' => false]);
        }
    }

    public function test_stale_request_snapshot_cannot_create_new_consent_after_password_rotation(): void
    {
        $user = $this->fixture();
        DB::table('users')->where('id', $user->id)->update(['password' => bcrypt('a-new-private-password')]);
        try {
            app(EmailNotices::class)->preference($user, true, 0);
            $this->fail('Stale account snapshot accepted.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('notification_email_preferences', 0);
        Mail::assertNothingSent();
    }

    public function test_production_configuration_requires_tls_and_public_https_without_sending(): void
    {
        $this->fixture();
        $this->app->instance('env', 'production');
        config(['app.url' => 'https://platform.crea-biz.com', 'mail.default' => 'smtp', 'mail.mailers.smtp' => [
            'transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587, 'verify_peer' => false, 'require_tls' => false, 'auto_tls' => false,
        ]]);
        $service = app(EmailNotices::class);
        $configuration = $service->configuration();
        $this->assertSame('SMTP_HANDOFF_UNVERIFIED', $configuration['evidence']);
        $this->assertTrue($configuration['transport']['verify_peer']);
        $this->assertTrue($configuration['transport']['require_tls']);
        $this->assertTrue($configuration['transport']['auto_tls']);
        foreach (['http://platform.crea-biz.com', 'https://127.0.0.1', 'https://platform.crea-biz.com/account', 'https://platform.crea-biz.com?token=private', 'https://platform.crea-biz.com:8443'] as $origin) {
            config(['app.url' => $origin]);
            $this->assertNull($service->configuration(), $origin);
        }
        Mail::assertNothingSent();
    }
}
