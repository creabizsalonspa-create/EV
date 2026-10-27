<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function link(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id, 'hash' => $hash ?? sha1($user->email),
        ]);
    }

    public function test_registration_does_not_accept_verification_claims_and_send_requires_session(): void
    {
        Notification::fake();
        $this->postJson('/api/email/verification-notification')->assertUnauthorized();
        $this->postJson('/api/register', ['name' => 'New driver', 'email' => 'new@example.test', 'password' => 'long-safe-test-password', 'email_verified_at' => now()->toISOString()])
            ->assertCreated()->assertJsonPath('email_verified_at', null);
        Notification::assertNothingSent();
        $user = User::firstOrFail();
        $this->postJson('/api/email/verification-notification', ['email' => 'other@example.test'])
            ->assertAccepted()->assertJsonMissingPath('url')->assertJsonMissingPath('token');
        Notification::assertSentTo($user, VerifyEmail::class, function ($notification, $channels) use ($user) {
            $this->assertSame(['mail'], $channels);
            $url = $notification->toMail($user)->actionUrl;
            $this->get($url)->assertOk()->assertSee('Email verified');

            return true;
        });
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_signed_verification_is_idempotent_audited_and_preserves_role(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create(['role' => 'vendor']);
        $url = $this->link($user);
        $this->actingAs($user)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get($url)->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame('vendor', $user->fresh()->role);
        Event::assertDispatchedTimes(Verified::class, 1);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $user->id, 'action' => 'email.verified']);
        Notification::fake();
        $this->actingAs($user->fresh())->postJson('/api/email/verification-notification')->assertOk();
        Notification::assertNothingSent();
    }

    public function test_guests_and_other_accounts_cannot_consume_a_verification_link(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->link($user);
        $this->get($url)->assertRedirect('/?verification=signin');
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_tampered_unsigned_and_wrong_email_links_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        $url = $this->link($user);
        $this->get($url.'&extra=tampered')->assertForbidden();
        $this->get(strtok($url, '?'))->assertForbidden();
        $this->get($this->link($user, sha1('wrong@example.test')))->assertForbidden();
        $user->forceFill(['email' => 'changed@example.test'])->save();
        $this->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_expired_link_does_not_verify_and_resend_produces_a_working_link(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $url = $this->link($user);
        $this->travel(61)->minutes();
        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->postJson('/api/email/verification-notification')->assertAccepted();
        Notification::assertSentTo($user, VerifyEmail::class, function ($n) use ($user) {
            $this->get($n->toMail($user)->actionUrl)->assertOk();

            return true;
        });
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_resend_budget_is_per_actor_and_independent_of_catalog(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/stations')->assertOk();
        }
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/email/verification-notification')->assertAccepted();
        }
        $this->postJson('/api/email/verification-notification')->assertStatus(429)->assertHeader('Retry-After');
        Notification::assertSentToTimes($user, VerifyEmail::class, 3);
        $this->actingAs(User::factory()->unverified()->create())->postJson('/api/email/verification-notification')->assertAccepted();
    }
}
