<?php

namespace Tests\Feature;

use App\Services\Commercial\SubscriptionCreditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CommercialCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth'])->prefix('campaign-test')->group(base_path('routes/campaigns.php'));
        Route::group([], base_path('routes/campaign_delivery.php'));
    }

    private function fixture(array $entitlements = ['campaign_slots' => 2, 'campaign_units' => 5], bool $paid = true): array
    {
        return (require base_path('tests/Fixtures/commercial-campaigns.php'))($entitlements, $paid);
    }

    private function create(array $f): array
    {
        return $this->actingAs($f['vendor'])->postJson('/campaign-test/commercial/campaigns', $f['values'])->assertCreated()->json('campaign');
    }

    private function approved(array $f): array
    {
        $c = $this->create($f);
        $this->postJson('/campaign-test/commercial/campaigns/'.$c['id'].'/submit', ['reason' => 'Vendor requests commercial review'])->assertOk();
        $this->actingAs($f['admin'])->postJson('/campaign-test/commercial/campaigns/'.$c['id'].'/approve', ['reason' => 'Approved reviewed synthetic creative'])->assertOk();

        return $c;
    }

    private function delivery(int $campaign, string $event, string $kind = 'delivery', ?string $parent = null, bool $valid = true)
    {
        $body = ['campaign_id' => $campaign, 'event_id' => $event, 'kind' => $kind, 'parent_event_id' => $parent, 'country' => 'AE', 'placement' => 'discovery_banner', 'occurred_at' => now()->utc()->toIso8601String()];
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $stamp = (string) time();

        return $this->call('POST', '/internal/commercial/campaign-deliveries', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CAMPAIGN_TIMESTAMP' => $stamp, 'HTTP_X_CAMPAIGN_SIGNATURE' => hash_hmac('sha256', $stamp.'.'.$raw, $valid ? config('campaigns.delivery_secret') : 'invalid')], $raw);
    }

    public function test_paid_package_campaign_review_delivery_and_governance_preserve_cash_journals(): void
    {
        $f = $this->fixture();
        $cash = DB::table('commercial_subscription_ledger')->count();
        $c = $this->approved($f);
        $this->actingAs($f['customer'])->getJson('/campaign-test/commercial/campaign-placements?country=AE&placement=discovery_banner')->assertOk()->assertJsonPath('placements.0.sponsored', true)->assertJsonPath('placements.0.compatibility_verified', false);
        $this->delivery($c['id'], 'verified-delivery')->assertOk()->assertJsonPath('duplicate', false);
        $this->delivery($c['id'], 'verified-delivery')->assertOk()->assertJsonPath('duplicate', true);
        $this->delivery($c['id'], 'verified-click', 'click', 'verified-delivery')->assertOk();
        $this->actingAs($f['vendor'])->getJson('/campaign-test/commercial/campaigns/'.$c['id'])->assertOk()->assertJsonPath('campaign.delivered_units', 1)->assertJsonPath('campaign.verified_server_clicks', 1)->assertJsonPath('campaign.human_impressions_verified', false)->assertJsonMissingPath('campaign.request_key');
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $this->assertDatabaseCount('commercial_subscription_ledger', $cash);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_unpaid_or_non_entitled_package_and_foreign_station_cannot_create_campaign(): void
    {
        $f = $this->fixture(paid: false);
        $this->actingAs($f['vendor'])->postJson('/campaign-test/commercial/campaigns', $f['values'])->assertConflict();
        $this->assertDatabaseCount('commercial_campaigns', 0);
        $other = $this->fixture(['analytics' => true]);
        $this->actingAs($other['vendor'])->postJson('/campaign-test/commercial/campaigns', $other['values'])->assertConflict();
        $paid = $this->fixture();
        $paid['values']['station_id'] = $f['station']->id;
        $this->actingAs($paid['vendor'])->postJson('/campaign-test/commercial/campaigns', $paid['values'])->assertNotFound();
    }

    public function test_budget_reservations_idempotency_and_cancellation_do_not_reopen_used_units(): void
    {
        $f = $this->fixture();
        $c = $this->approved($f);
        $this->actingAs($f['vendor'])->postJson('/campaign-test/commercial/campaigns', $f['values'])->assertCreated()->assertJsonPath('campaign.id', $c['id']);
        $changed = $f['values'];
        $changed['title'] = 'Changed';
        $this->postJson('/campaign-test/commercial/campaigns', $changed)->assertConflict();
        $second = $f['values'];
        $second['idempotency_key'] = 'second';
        $this->postJson('/campaign-test/commercial/campaigns', $second)->assertConflict();
        $this->delivery($c['id'], 'consumed')->assertOk();
        $this->actingAs($f['vendor'])->postJson('/campaign-test/commercial/campaigns/'.$c['id'].'/cancel', ['reason' => 'Stop and retain used package units'])->assertOk();
        $second['budget_units'] = 5;
        $this->postJson('/campaign-test/commercial/campaigns', $second)->assertConflict();
        $second['budget_units'] = 4;
        $this->postJson('/campaign-test/commercial/campaigns', $second)->assertCreated();
    }

    public function test_signed_delivery_is_required_budget_bounded_and_click_parent_is_verified(): void
    {
        $f = $this->fixture();
        $f['values']['budget_units'] = 1;
        $c = $this->approved($f);
        $this->delivery($c['id'], 'forged', valid: false)->assertUnauthorized();
        $this->delivery($c['id'], 'before-delivery', 'click', 'missing')->assertConflict();
        $this->delivery($c['id'], 'one')->assertOk();
        $this->delivery($c['id'], 'over-budget')->assertConflict();
        $this->delivery($c['id'], 'one-click', 'click', 'one')->assertOk();
        $this->delivery($c['id'], 'second-click', 'click', 'one')->assertConflict();
        $this->assertDatabaseCount('commercial_campaign_deliveries', 2);
    }

    public function test_admin_can_suspend_vendor_cannot_resume_and_refunded_package_stops_delivery(): void
    {
        $f = $this->fixture();
        $c = $this->approved($f);
        $path = '/campaign-test/commercial/campaigns/'.$c['id'];
        $this->postJson($path.'/suspend', ['reason' => 'Moderation requires suspension'])->assertOk();
        $this->actingAs($f['vendor'])->postJson($path.'/resume', ['reason' => 'Try bypassing review'])->assertConflict();
        $this->delivery($c['id'], 'suspended')->assertConflict();
        $this->actingAs($f['admin'])->postJson($path.'/reinstate', ['reason' => 'Creative reviewed and reinstated'])->assertOk();
        app(SubscriptionCreditService::class)->refund($f['admin'], $f['subscription']->id, $f['invoice']->id, 1, 'Synthetic partial package credit', 'campaign-credit');
        $this->delivery($c['id'], 'after-credit')->assertConflict();
        $this->actingAs($f['customer'])->getJson('/campaign-test/commercial/campaign-placements?country=AE&placement=discovery_banner')->assertOk()->assertJsonCount(0, 'placements');
    }

    public function test_tenant_roles_expiry_country_dates_and_immutable_terms_fail_closed(): void
    {
        $f = $this->fixture();
        $c = $this->create($f);
        $path = '/campaign-test/commercial/campaigns/'.$c['id'];
        $this->actingAs($f['other'])->getJson($path)->assertNotFound();
        $this->actingAs($f['customer'])->getJson('/campaign-test/commercial/campaigns')->assertForbidden();
        $this->actingAs($f['vendor'])->postJson($path.'/approve', ['reason' => 'Cannot approve own sponsorship'])->assertConflict();
        $this->postJson($path.'/submit', ['reason' => 'Review requested'])->assertOk();
        $this->postJson($path.'/approve', ['reason' => 'Cannot approve own sponsorship'])->assertForbidden();
        foreach ([fn () => DB::table('commercial_campaigns')->where('id', $c['id'])->update(['budget_units' => 99]), fn () => DB::table('commercial_campaign_events')->delete()] as $mutate) {
            try {
                DB::transaction($mutate);
                $this->fail('Expected immutable campaign economics');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
            }
        }
        $this->travel(2)->days();
        $this->actingAs($f['admin'])->postJson($path.'/approve', ['reason' => 'Expired campaign cannot serve'])->assertConflict();
    }

    public function test_authentication_enablement_dates_and_payload_changes_fail_without_new_delivery(): void
    {
        $f = $this->fixture();
        $this->postJson('/campaign-test/commercial/campaigns', $f['values'])->assertUnauthorized();
        config(['campaigns.enabled' => false]);
        $this->actingAs($f['vendor'])->postJson('/campaign-test/commercial/campaigns', $f['values'])->assertStatus(503);
        config(['campaigns.enabled' => true]);
        $v = $f['values'];
        $v['ends_at'] = now()->addYears(2)->toDateTimeString();
        $this->postJson('/campaign-test/commercial/campaigns', $v)->assertConflict();
        $c = $this->approved($f);
        $this->delivery($c['id'], 'bound-event')->assertOk();
        $this->delivery($c['id'], 'bound-event', 'click', 'bound-event')->assertConflict();
        $this->assertDatabaseCount('commercial_campaign_deliveries', 1);
        $this->actingAs($f['vendor'])->getJson('/campaign-test/commercial/campaign-stations')->assertOk()->assertJsonPath('data.0.id', $f['station']->id);
        $this->actingAs($f['other'])->getJson('/campaign-test/commercial/campaign-stations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_invalid_or_expired_signature_never_reserves_package_delivery(): void
    {
        $f = $this->fixture();
        $c = $this->approved($f);
        $raw = json_encode(['campaign_id' => $c['id'], 'event_id' => 'stale', 'kind' => 'delivery', 'country' => 'AE', 'placement' => 'discovery_banner', 'occurred_at' => now()->toIso8601String()]);
        $stamp = (string) (time() - 301);
        $this->call('POST', '/internal/commercial/campaign-deliveries', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CAMPAIGN_TIMESTAMP' => $stamp, 'HTTP_X_CAMPAIGN_SIGNATURE' => hash_hmac('sha256', $stamp.'.'.$raw, config('campaigns.delivery_secret'))], $raw)->assertUnauthorized();
        config(['campaigns.delivery_secret' => 'too-short']);
        $this->delivery($c['id'], 'invalid-config')->assertStatus(503);
        $this->assertDatabaseCount('commercial_campaign_deliveries', 0);
    }

    public function test_expired_paid_cycle_and_station_transfer_stop_previously_approved_campaign(): void
    {
        $f = $this->fixture();
        $c = $this->approved($f);
        DB::table('stations')->where('id', $f['station']->id)->update(['vendor_id' => $f['other']->id]);
        $this->delivery($c['id'], 'after-transfer')->assertConflict();
        DB::table('stations')->where('id', $f['station']->id)->update(['vendor_id' => $f['vendor']->id]);
        DB::table('commercial_subscriptions')->where('id', $f['subscription']->id)->update(['status' => 'cancelled']);
        $this->delivery($c['id'], 'after-cancellation')->assertConflict();
        $this->assertDatabaseCount('commercial_campaign_deliveries', 0);
    }

    public function test_uncertain_and_pending_refund_sources_hold_delivery_but_known_failure_does_not(): void
    {
        $f = $this->fixture();
        $c = $this->approved($f);
        $checkout = DB::table('subscription_gateway_checkouts')->insertGetId(['subscription_id' => $f['subscription']->id, 'invoice_id' => $f['invoice']->id, 'user_id' => $f['vendor']->id, 'provider' => 'stripe_test', 'provider_reference' => 'pi_Campaign', 'request_key' => hash('sha256', 'campaign-capture'), 'fingerprint' => hash('sha256', 'campaign-capture'), 'currency' => 'AED', 'amount_minor' => 1000, 'status' => 'captured', 'created_at' => now(), 'updated_at' => now()]);
        $identity = hash('sha256', 'campaign-refund-recovery');
        $intent = DB::table('payment_recovery_intents')->insertGetId(['identity' => $identity, 'scope' => 'subscription', 'checkout_id' => $checkout, 'actor_id' => $f['admin']->id, 'kind' => 'refund', 'fingerprint' => hash('sha256', 'campaign-refund'), 'provider' => 'stripe_test', 'currency' => 'AED', 'amount_minor' => 1, 'status' => 'executing', 'retry_until' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $receipt = DB::table('commercial_subscription_receipts')->where('invoice_id', $f['invoice']->id)->first();
        $request = ['subscription_id' => $f['subscription']->id, 'invoice_id' => $f['invoice']->id, 'receipt_id' => $receipt->id, 'checkout_id' => $checkout, 'actor_id' => $f['admin']->id, 'request_key' => hash('sha256', 'campaign-refund'), 'recovery_identity' => $identity, 'fingerprint' => hash('sha256', 'campaign-refund'), 'amount_minor' => 1, 'currency' => 'AED', 'reason' => 'Synthetic uncertain refund hold', 'created_at' => now()];
        DB::table('subscription_credit_requests')->insert($request);
        foreach (['executing', 'uncertain', 'needs_review'] as $status) {
            DB::table('payment_recovery_intents')->where('id', $intent)->update(['status' => $status]);
            $this->delivery($c['id'], 'held-'.$status)->assertConflict();
        }
        $this->assertDatabaseCount('commercial_campaign_deliveries', 0);
        DB::table('payment_recovery_intents')->where('id', $intent)->update(['status' => 'failed']);
        $this->delivery($c['id'], 'after-known-failure')->assertOk();
        $request['request_key'] = hash('sha256', 'campaign-refund-not-started');
        $request['recovery_identity'] = hash('sha256', 'campaign-refund-not-started');
        DB::table('subscription_credit_requests')->insert($request);
        $this->delivery($c['id'], 'before-intent-created')->assertConflict();
        $this->assertDatabaseCount('commercial_campaign_deliveries', 1);
        $this->assertDatabaseCount('subscription_credits', 0);
    }

    public function test_current_locked_roles_override_stale_request_account_roles(): void
    {
        $f = $this->fixture();
        $c = $this->create($f);
        $path = '/campaign-test/commercial/campaigns/'.$c['id'];
        $this->postJson($path.'/submit', ['reason' => 'Submit before role changes'])->assertOk();
        DB::table('users')->where('id', $f['admin']->id)->update(['role' => 'customer']);
        $this->actingAs($f['admin'])->postJson($path.'/approve', ['reason' => 'Stale administrator must not approve'])->assertNotFound();
        DB::table('users')->where('id', $f['vendor']->id)->update(['role' => 'customer']);
        $v = $f['values'];
        $v['idempotency_key'] = 'after-demotion';
        $this->actingAs($f['vendor'])->postJson('/campaign-test/commercial/campaigns', $v)->assertForbidden();
        $this->assertDatabaseCount('commercial_campaigns', 1);
        $this->assertDatabaseHas('commercial_campaigns', ['id' => $c['id'], 'status' => 'submitted']);
    }
}
