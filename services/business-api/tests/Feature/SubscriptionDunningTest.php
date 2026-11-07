<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\SubscriptionDunning;
use App\Services\Commercial\SubscriptionGateway;
use App\Services\Commercial\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionDunningTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $price = 1000): array
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $plan = app(SubscriptionService::class)->publish(User::factory()->create(['role' => 'admin']), ['code' => 'dunning-'.bin2hex(random_bytes(4)), 'name' => 'Dunning test', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => $price, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => '{}']);
        $summary = app(SubscriptionService::class)->subscribe($user, $plan->id, 'enroll');

        return [$user, $summary['subscription']->id, $summary['invoices'][0]->id, Carbon::parse($summary['invoices'][0]->period_start)];
    }

    public function test_exact_reminder_boundaries_replay_and_no_funds_or_entitlements_mutation(): void
    {
        [$user, $sub, $invoice, $start] = $this->fixture();
        $d = app(SubscriptionDunning::class);
        $this->travelTo($start->copy()->addDay()->subSecond());
        $this->assertFalse($d->invoice($invoice));
        foreach ([1 => 1, 2 => 3, 3 => 7] as $stage => $days) {
            $this->travelTo($start->copy()->addDays($days));
            $this->assertTrue($d->invoice($invoice));
            $this->assertFalse($d->invoice($invoice));
            $this->assertSame($stage, (int) DB::table('subscription_dunning_notices')->max('stage'));
        }
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
        $this->assertDatabaseCount('subscription_gateway_checkouts', 0);
        $this->assertFalse(app(SubscriptionService::class)->summary($sub)['entitlements_active']);
        $this->assertSame('pending', DB::table('commercial_subscriptions')->find($sub)->status);
        Http::assertNothingSent();
    }

    public function test_delayed_scan_sends_only_latest_stage_and_prior_notices_cannot_starve_later_due_invoices(): void
    {
        [$user, $sub, $invoice, $start] = $this->fixture();
        $d = app(SubscriptionDunning::class);
        $this->travelTo($start->copy()->addDays(8));
        $this->assertSame(1, $d->scan(1));
        $this->assertSame(3, (int) DB::table('subscription_dunning_notices')->value('stage'));
        $this->assertSame(0, $d->scan(1));
        // A future-stage invoice with a prior first notice must not occupy the
        // bounded selection and starve another invoice whose first stage is due.
        $this->travelTo($start->copy()->addDays(9));
        $other = User::factory()->create();
        $s = app(SubscriptionService::class)->subscribe($other, DB::table('commercial_plan_versions')->value('id'), 'other');
        $this->travelTo(now()->addDay());
        $this->assertSame(1, $d->scan(1));
        $this->assertSame($s['invoices'][0]->id, DB::table('subscription_dunning_notices')->orderByDesc('id')->value('invoice_id'));
    }

    public function test_waiting_for_next_stage_does_not_starve_other_due_notices(): void
    {
        [, , $first, $start] = $this->fixture();
        $d = app(SubscriptionDunning::class);
        $this->travelTo($start->copy()->addDay());
        $this->assertTrue($d->invoice($first));
        [, , $second] = $this->fixture();
        $this->travelTo($start->copy()->addDays(2));
        $this->assertSame(1, $d->scan(1));
        $this->assertSame($second, DB::table('subscription_dunning_notices')->orderByDesc('id')->value('invoice_id'));
        $this->assertSame(0, $d->scan(1));
    }

    public function test_paid_cancelled_zero_price_and_uncertain_provider_invoices_are_suppressed(): void
    {
        [$user, $sub, $invoice, $start] = $this->fixture();
        $this->travelTo($start->copy()->addDays(2));
        $d = app(SubscriptionDunning::class);
        DB::table('commercial_subscriptions')->where('id', $sub)->update(['status' => 'cancelled']);
        $this->assertFalse($d->invoice($invoice));
        DB::table('commercial_subscriptions')->where('id', $sub)->update(['status' => 'pending']);
        [, , $zero] = $this->fixture(0);
        $this->assertFalse($d->invoice($zero));
        $checkout = app(SubscriptionGateway::class)->checkout($user, $sub, $invoice, 'checkout');
        DB::table('payment_recovery_intents')->insert(['identity' => str_repeat('d', 64), 'scope' => 'subscription', 'checkout_id' => $checkout->id, 'actor_id' => $user->id, 'kind' => 'authorize', 'fingerprint' => str_repeat('f', 64), 'provider' => 'stripe_test', 'currency' => 'AED', 'amount_minor' => 1050, 'status' => 'uncertain', 'retry_until' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($d->invoice($invoice));
        $this->assertSame(0, $d->scan(10));
        DB::table('payment_recovery_intents')->update(['status' => 'completed']);
        app(SubscriptionGateway::class)->operate($user, $checkout->id, 'authorize', 'authorize', 'mock_success');
        app(SubscriptionGateway::class)->operate($user, $checkout->id, 'capture', 'capture', null);
        $this->assertFalse($d->invoice($invoice));
        $this->assertDatabaseCount('subscription_dunning_notices', 0);
    }

    public function test_notices_are_scoped_and_current_payment_state_is_authoritative(): void
    {
        [$user, $sub, $invoice, $start] = $this->fixture();
        $this->travelTo($start->copy()->addDay());
        app(SubscriptionDunning::class)->invoice($invoice);
        $url = '/api/v1/commercial/subscription-notices';
        $this->actingAs($user, 'sanctum')->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.0.attention', 'payment_due')->assertJsonPath('data.0.collection_attempted', false);
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($url)->assertOk()->assertJsonPath('total', 0);
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')->getJson($url)->assertOk()->assertJsonPath('total', 1);
        app(SubscriptionService::class)->mockPay($user, $sub, $invoice, 'succeeded', 'paid');
        $this->actingAs($user, 'sanctum')->getJson($url)->assertOk()->assertJsonPath('data.0.attention', 'settled');
    }

    public function test_command_rejects_invalid_limits_and_history_is_immutable(): void
    {
        [, , $invoice, $start] = $this->fixture();
        $this->artisan('commercial:subscription-dunning', ['--limit' => 0])->assertFailed();
        $this->travelTo($start->copy()->addDays(3));
        $this->artisan('commercial:subscription-dunning', ['--limit' => 1])->assertSuccessful();
        $this->expectException(QueryException::class);
        DB::table('subscription_dunning_notices')->update(['stage' => 1]);
    }
}
