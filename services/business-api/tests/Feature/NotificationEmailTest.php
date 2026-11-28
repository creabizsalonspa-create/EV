<?php

namespace Tests\Feature;

use App\Mail\GenericInboxNotice;
use App\Models\InboxNotification;
use App\Models\User;
use App\Services\Notifications\EmailNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_interrupted_claim_recovery_is_bounded_terminal_and_never_sends(): void
    {
        $this->travelTo(Carbon::parse('2026-11-28T06:00:00Z'));
        $service = app(EmailNotices::class);
        $receipts = [];
        foreach (['oldest', 'boundary', 'missing_claim_clock', 'fresh', 'queued', 'terminal'] as $kind) {
            $user = $this->fixture();
            $this->enable($user);
            $notice = $this->notice($user);
            $this->assertSame(1, $service->prepare());
            $row = DB::table('notification_email_outbox')->where('user_id', $user->id)->first();
            $receipts[$kind] = $row->id;
            if ($kind === 'queued') {
                continue;
            }
            $at = match ($kind) {
                'oldest' => now()->subMinutes(20),
                'boundary', 'terminal' => now()->subMinutes(15),
                'missing_claim_clock' => null,
                default => now()->subMinutes(14),
            };
            DB::table('notification_email_outbox')->where('id', $row->id)->update([
                'state' => $kind === 'terminal' ? 'handed_off' : 'claimed',
                'claimed_at' => $at,
                'created_at' => now()->subMinutes($kind === 'missing_claim_clock' ? 16 : 21),
                'completed_at' => $kind === 'terminal' ? now()->subMinutes(14) : null,
            ]);
            DB::table('notification_email_preferences')->where('user_id', $user->id)->update([
                'after_notification_id' => $notice->id, 'last_attempted_at' => $at,
            ]);
        }
        $before = DB::table('notification_email_preferences')->orderBy('user_id')->get()->toJson();
        $terminal = DB::table('notification_email_outbox')->where('id', $receipts['terminal'])->first();
        config(['notification_email.enabled' => false]);
        $this->assertSame(1, $service->run(1)['recovered_unknown']);
        $this->assertDatabaseHas('notification_email_outbox', ['id' => $receipts['oldest'], 'state' => 'outcome_unknown']);
        $this->assertDatabaseHas('notification_email_outbox', ['id' => $receipts['boundary'], 'state' => 'claimed']);
        $this->assertSame(2, $service->run()['recovered_unknown']);
        $this->assertSame(0, $service->run()['recovered_unknown']);
        foreach (['oldest', 'boundary', 'missing_claim_clock'] as $kind) {
            $this->assertDatabaseHas('notification_email_outbox', [
                'id' => $receipts[$kind], 'state' => 'outcome_unknown',
                'failure_code' => 'interrupted_handoff_unknown',
            ]);
            $this->assertNotNull(DB::table('notification_email_outbox')->where('id', $receipts[$kind])->value('completed_at'));
        }
        $this->assertDatabaseHas('notification_email_outbox', ['id' => $receipts['fresh'], 'state' => 'claimed']);
        $this->assertDatabaseHas('notification_email_outbox', ['id' => $receipts['queued'], 'state' => 'queued']);
        $this->assertEquals($terminal, DB::table('notification_email_outbox')->where('id', $receipts['terminal'])->first());
        $this->assertSame($before, DB::table('notification_email_preferences')->orderBy('user_id')->get()->toJson());
        config(['notification_email.enabled' => true]);
        foreach (['oldest', 'boundary', 'missing_claim_clock'] as $kind) {
            $this->assertSame('not_attempted', $service->deliver($receipts[$kind]));
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('charger_commands', 0);
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

    public function test_invalid_queued_authority_and_read_activity_never_consume_cursor_or_cooldown(): void
    {
        foreach (['recipient_hash', 'configuration_hash', 'consent_revision', 'read_activity'] as $change) {
            $user = $this->fixture();
            $this->enable($user);
            $notice = $this->notice($user);
            $service = app(EmailNotices::class);
            $this->assertSame(1, $service->prepare());
            $row = DB::table('notification_email_outbox')->where('user_id', $user->id)->first();
            $before = DB::table('notification_email_preferences')->where('user_id', $user->id)->first();
            if ($change === 'read_activity') {
                $notice->update(['read_at' => now()]);
            } else {
                DB::table('notification_email_outbox')->where('id', $row->id)->update([
                    $change => $change === 'consent_revision' ? $row->consent_revision + 1 : str_repeat('0', 64),
                ]);
            }
            $this->assertSame('cancelled', $service->deliver($row->id), $change);
            $this->assertSame('not_attempted', $service->deliver($row->id));
            $after = DB::table('notification_email_preferences')->where('user_id', $user->id)->first();
            $this->assertSame($before->after_notification_id, $after->after_notification_id);
            $this->assertSame($before->last_attempted_at, $after->last_attempted_at);
            $this->assertSame($before->revision, $after->revision);
            $this->assertDatabaseHas('notification_email_outbox', ['id' => $row->id, 'claimed_at' => null, 'state' => 'cancelled']);
            Mail::assertNothingSent();

            // The next genuinely new activity is still eligible immediately.
            $this->notice($user);
            $this->assertSame(1, $service->prepare());
            $new = DB::table('notification_email_outbox')->where('user_id', $user->id)->where('state', 'queued')->first();
            $this->assertNotSame($row->id, $new->id);
            $this->assertSame('handed_off', $service->deliver($new->id));
            Mail::assertSent(GenericInboxNotice::class, 1);
            DB::table('notification_email_preferences')->where('user_id', $user->id)->update(['enabled' => false]);
        }
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_queued_range_must_end_at_the_recipient_owned_notice_before_consuming_cursor(): void
    {
        $user = $this->fixture();
        $this->enable($user);
        $owned = $this->notice($user);
        $service = app(EmailNotices::class);
        $this->assertSame(1, $service->prepare());
        $row = DB::table('notification_email_outbox')->first();
        $before = DB::table('notification_email_preferences')->where('user_id', $user->id)->first();
        $other = $this->notice(User::factory()->create());
        $this->assertGreaterThan($owned->id, $other->id);
        DB::table('notification_email_outbox')->where('id', $row->id)
            ->update(['through_notification_id' => $other->id]);

        $this->assertSame('cancelled', $service->deliver($row->id));
        $this->assertDatabaseHas('notification_email_outbox', [
            'id' => $row->id, 'state' => 'cancelled', 'claimed_at' => null,
        ]);
        $this->assertDatabaseHas('notification_email_preferences', [
            'user_id' => $user->id, 'after_notification_id' => $before->after_notification_id,
            'last_attempted_at' => $before->last_attempted_at,
        ]);
        Mail::assertNothingSent();
        $new = $this->notice($user);
        $this->assertSame(1, $service->prepare());
        $next = DB::table('notification_email_outbox')->where('state', 'queued')->first();
        $this->assertSame($new->id, (int) $next->through_notification_id);
        $this->assertSame('handed_off', $service->deliver($next->id));
        Mail::assertSent(GenericInboxNotice::class, 1);
        Mail::assertSent(GenericInboxNotice::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_cancelled_receipt_keys_cannot_starve_other_recipients_or_replay_the_same_tuple(): void
    {
        $this->freezeTime();
        $first = $this->fixture();
        $this->enable($first);
        $blockedNotice = $this->notice($first);
        $service = app(EmailNotices::class);
        $this->assertSame(1, $service->prepare(1));
        $receipt = DB::table('notification_email_outbox')->where('user_id', $first->id)->first();
        $before = DB::table('notification_email_preferences')->where('user_id', $first->id)->first();
        DB::table('notification_email_outbox')->where('id', $receipt->id)
            ->update(['recipient_hash' => str_repeat('0', 64)]);
        $this->assertSame('cancelled', $service->deliver($receipt->id));
        $this->assertSame(0, $service->prepare(1));
        $this->assertSame('not_attempted', $service->deliver($receipt->id));
        Mail::assertNothingSent();

        // Even with a candidate budget of one, the old cancelled key must
        // not monopolize the scan. The second owner has independent consent.
        $second = User::factory()->create();
        $this->enable($second);
        $fresh = $this->notice($second);
        $this->assertSame(1, $service->prepare(1));
        $queued = DB::table('notification_email_outbox')->where('state', 'queued')->first();
        $this->assertSame($second->id, (int) $queued->user_id);
        $this->assertSame($fresh->id, (int) $queued->through_notification_id);
        $this->assertSame('handed_off', $service->deliver($queued->id));
        $this->assertSame(0, $service->prepare(1));
        $this->assertDatabaseHas('notification_email_preferences', [
            'user_id' => $first->id, 'after_notification_id' => $before->after_notification_id,
            'last_attempted_at' => null, 'revision' => $before->revision,
        ]);
        $this->assertDatabaseHas('notification_email_outbox', [
            'id' => $receipt->id, 'through_notification_id' => $blockedNotice->id,
            'state' => 'cancelled', 'claimed_at' => null,
        ]);

        // New activity is still eligible; no replacement/reopening of the
        // cancelled row and no cooldown charged for its invalid claim.
        $next = $this->notice($first);
        $this->assertSame(1, $service->prepare(1));
        $queued = DB::table('notification_email_outbox')->where('state', 'queued')->first();
        $this->assertSame($first->id, (int) $queued->user_id);
        $this->assertSame($next->id, (int) $queued->through_notification_id);
        $this->assertSame('handed_off', $service->deliver($queued->id));
        Mail::assertSent(GenericInboxNotice::class, 2);
        Mail::assertSent(GenericInboxNotice::class, fn ($mail) => $mail->hasTo($first->email));
        Mail::assertSent(GenericInboxNotice::class, fn ($mail) => $mail->hasTo($second->email));
        $this->assertDatabaseCount('notification_email_outbox', 3);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_configuration_change_while_locking_candidate_does_not_disable_consent_or_queue_stale_handoff(): void
    {
        $user = $this->fixture();
        $this->enable($user);
        $this->notice($user);
        $changed = false;
        DB::listen(function ($query) use (&$changed) {
            if (! $changed && str_contains(strtolower($query->sql), 'select') &&
                str_contains($query->sql, '"notification_email_preferences"') &&
                ! str_contains($query->sql, '"inbox_notifications"')) {
                $changed = true;
                config(['mail.from.address' => 'replacement@example.test']);
            }
        });
        $service = app(EmailNotices::class);
        $this->assertSame(0, $service->prepare());
        $this->assertTrue($changed);
        $this->assertDatabaseCount('notification_email_outbox', 0);
        $this->assertDatabaseHas('notification_email_preferences', ['user_id' => $user->id, 'enabled' => true, 'revision' => 1]);
        Mail::assertNothingSent();
        config(['mail.from.address' => 'notices@example.test']);
        $this->assertSame(1, $service->prepare());
        $this->assertDatabaseCount('notification_email_outbox', 1);
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

    public function test_sender_identity_change_invalidates_queued_consent_without_sending(): void
    {
        $user = $this->fixture();
        config(['mail.from.name' => 'Original sender']);
        $this->enable($user);
        $this->notice($user);
        $service = app(EmailNotices::class);
        $this->assertSame(1, $service->prepare());
        $row = DB::table('notification_email_outbox')->where('user_id', $user->id)->first();
        config(['mail.from.name' => 'Different sender']);
        $this->assertFalse($service->status($user)['consent_current']);
        $this->assertSame('cancelled', $service->deliver($row->id));
        Mail::assertNothingSent();
    }

    public function test_effective_smtp_transport_fingerprint_changes_with_authority_without_sending(): void
    {
        $this->fixture();
        $this->app->instance('env', 'production');
        config(['app.url' => 'https://platform.crea-biz.com', 'mail.default' => 'smtp',
            'mail.from.name' => 'Original sender', 'mail.mailers.smtp' => [
                'transport' => 'smtp', 'scheme' => 'smtp', 'host' => 'smtp.example.test',
                'port' => 587, 'username' => 'synthetic-user', 'password' => 'synthetic-password',
            ]]);
        $service = app(EmailNotices::class);
        $initial = $service->configuration()['hash'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $initial);
        $this->assertSame($initial, $service->configuration()['hash']);

        $original = config('mail.mailers.smtp');
        config(['mail.mailers.smtp' => array_reverse($original, true)]);
        $this->assertSame($initial, $service->configuration()['hash']);
        foreach (['username' => 'different-user', 'password' => 'different-password',
            'scheme' => 'smtps', 'local_domain' => 'different.example.test'] as $key => $value) {
            config(['mail.mailers.smtp' => array_replace($original, [$key => $value])]);
            $this->assertNotSame($initial, $service->configuration()['hash'], $key);
        }
        config(['mail.mailers.smtp' => $original, 'app.key' => '']);
        $this->assertNull($service->configuration());
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
