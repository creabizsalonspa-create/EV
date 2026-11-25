<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RecurringConsentAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_account_cannot_read_billing_or_change_collection_consent(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        config(['payments.provider' => 'stripe_test', 'payments.hosted_return_url' => 'https://example.test/return']);
        Route::middleware(['web', 'auth'])->prefix('recurring-authority-test')->group(base_path('routes/recurring_payments.php'));
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(SubscriptionService::class);
        $plan = $service->publish($admin, ['code' => 'recurring-authority', 'name' => 'Recurring authority fixture', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 1000, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => '{}']);
        $id = $service->subscribe($owner, $plan->id, 'enroll')['subscription']->id;
        $base = '/recurring-authority-test/commercial/';
        $policy = $base.'subscriptions/'.$id.'/collection-policy';
        $this->actingAs($owner, 'web');
        $this->putJson($policy, ['enabled' => false, 'expected_version' => 0])->assertOk()->assertJsonPath('version', 1);
        $this->getJson($base.'recurring-subscriptions')->assertOk()->assertJsonPath('data.0.id', $id);
        $before = DB::table('recurring_payment_events')->count();

        $original = (array) DB::table('users')->where('id', $owner->id)
            ->first(['role', 'account_status', 'auth_epoch', 'password']);
        foreach ([
            ['auth_epoch' => (int) $original['auth_epoch'] + 1],
            ['password' => Hash::make('rotated-recurring-password')],
            ['role' => 'vendor'],
            ['account_status' => 'suspended'],
        ] as $change) {
            DB::table('users')->where('id', $owner->id)->update(array_replace($original, $change));
            foreach (['payment-methods', 'payment-enrollments', 'recurring-subscriptions', 'recurring-events'] as $endpoint) {
                $this->getJson($base.$endpoint)->assertConflict();
            }
            $this->putJson($policy, ['enabled' => false, 'expected_version' => 1])->assertConflict();
            $this->postJson($base.'payment-methods/999/default', [])->assertConflict();
            $this->deleteJson($base.'payment-methods/999', [])->assertConflict();
            $this->postJson($base.'payment-enrollments', [
                'consent' => true, 'consent_version' => config('payments.mandate_consent_version'),
                'idempotency_key' => 'revoked-enrollment',
            ])->assertConflict();
            $this->postJson($base.'payment-enrollments/999/inspect')->assertConflict();
            $this->postJson($base.'payment-methods/999/detach')->assertConflict();
            $this->postJson($base.'subscription-checkouts/999/authentication')->assertConflict();
        }

        DB::table('users')->where('id', $owner->id)->update($original);

        $this->assertDatabaseHas('subscription_collection_policies', ['subscription_id' => $id, 'version' => 1, 'enabled' => false]);
        $this->assertDatabaseCount('recurring_payment_events', $before);
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
        $this->assertDatabaseCount('payment_enrollments', 0);
        $this->assertDatabaseCount('payment_customer_accounts', 0);
        $this->actingAs(User::factory()->create(), 'web')->putJson($policy, [])->assertNotFound();
        $this->actingAs($admin, 'web')->putJson($policy, [])->assertNotFound();
        Http::assertNothingSent();
    }
}
