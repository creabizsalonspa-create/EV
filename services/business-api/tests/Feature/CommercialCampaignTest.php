<?php

namespace Tests\Feature;

use App\Services\Commercial\Campaigns\CampaignService;
use App\Services\Commercial\SubscriptionCreditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        $this->freezeTime(); // A repeated publisher event must keep its original occurred_at.
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

    public function test_campaign_private_review_filters_narrow_authorized_scope_and_bound_pages(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $one = $this->approved($first);
        $two = $this->create($second);
        $base = '/campaign-test/commercial/campaigns';
        $before = DB::table('commercial_campaign_events')->count();
        $cash = DB::table('commercial_subscription_ledger')->count();

        $this->actingAs($first['admin'])->getJson($base.'?status=draft&vendor_id='.$second['vendor']->id.'&country=AE&per_page=1')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $two['id'])
            ->assertJsonPath('scope.vendor_id', $second['vendor']->id)
            ->assertJsonPath('scope.status', 'draft');
        $this->getJson($base.'?station_id='.$first['station']->id.'&invoice_id='.$first['invoice']->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $one['id']);
        $this->getJson($base.'?status=approved&per_page=1&page=2')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('current_page', 2);
        $this->actingAs($first['vendor'])->getJson($base.'?vendor_id='.$second['vendor']->id)
            ->assertOk()->assertJsonPath('total', 0);
        $this->getJson($base.'?invoice_id='.$second['invoice']->id)->assertOk()->assertJsonPath('total', 0);
        $this->getJson($base.'/'.$two['id'])->assertNotFound();

        foreach (['page=0', 'page=100001', 'per_page=26'] as $invalid) {
            foreach ([$base, $base.'/'.$one['id'], '/campaign-test/commercial/campaign-packages', '/campaign-test/commercial/campaign-stations'] as $path) {
                $this->getJson($path.'?'.$invalid)->assertUnprocessable();
            }
        }
        foreach (['status=paid', 'vendor_id=0001', 'country=ae', 'invoice_id=0'] as $invalid) {
            $this->getJson($base.'?'.$invalid)->assertUnprocessable();
        }
        $this->actingAs($first['customer'])->getJson($base)->assertForbidden();
        $this->assertSame($before, DB::table('commercial_campaign_events')->count());
        $this->assertSame($cash, DB::table('commercial_subscription_ledger')->count());
        $this->assertDatabaseCount('commercial_campaign_deliveries', 0);
    }

    public function test_private_delivery_history_omits_publisher_request_identity_and_retains_exact_counts(): void
    {
        $this->freezeTime();
        $f = $this->fixture();
        $c = $this->approved($f);
        $this->delivery($c['id'], 'publisher-private-delivery')->assertOk();
        $this->delivery($c['id'], 'publisher-private-click', 'click', 'publisher-private-delivery')->assertOk();
        $base = '/campaign-test/commercial/campaigns/'.$c['id'].'?per_page=1';
        $cash = DB::table('commercial_subscription_ledger')->count();
        $this->actingAs($f['vendor'])->getJson($base)->assertOk()
            ->assertJsonPath('campaign.delivered_units', 1)
            ->assertJsonPath('campaign.verified_server_clicks', 1)
            ->assertJsonPath('deliveries.total', 2)
            ->assertJsonPath('deliveries.data.0.kind', 'click')
            ->assertJsonPath('deliveries.data.0.campaign_id', $c['id'])
            ->assertJsonMissingPath('deliveries.data.0.event_id')
            ->assertJsonMissingPath('deliveries.data.0.fingerprint');
        $this->getJson($base.'&page=2')->assertOk()
            ->assertJsonPath('deliveries.data.0.kind', 'delivery')
            ->assertJsonMissingPath('deliveries.data.0.event_id')
            ->assertJsonMissingPath('deliveries.data.0.fingerprint');
        $this->assertDatabaseCount('commercial_campaign_deliveries', 2);
        $this->assertSame($cash, DB::table('commercial_subscription_ledger')->count());
    }

    public function test_customer_station_placement_is_scoped_and_does_not_consume_delivery(): void
    {
        $fixture = $this->fixture();
        $fixture['values']['placement'] = 'station_detail';
        $campaign = $this->approved($fixture);
        $other = $this->fixture();
        $url = '/api/commercial/campaign-placements?country=AE&placement=station_detail&station_id=';
        $this->actingAs($fixture['customer']);
        $this->getJson($url.$other['station']->id)->assertOk()->assertJsonCount(0, 'placements');
        $this->getJson($url.$fixture['station']->id.'&page=0')->assertUnprocessable();
        $this->getJson($url.$fixture['station']->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('placements.0.campaign_id', $campaign['id'])
            ->assertJsonPath('placements.0.availability_verified', false)
            ->assertJsonPath('client_telemetry_billable', false);
        $this->assertDatabaseCount('commercial_campaign_deliveries', 0);
    }

    public function test_discovery_banner_results_fit_the_requested_catalog_page(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $campaign = $this->approved($first);
        $this->approved($second);
        $base = '/api/commercial/campaign-placements?placement=discovery_banner';
        $this->actingAs($first['customer']);
        $this->getJson($base)->assertUnprocessable();
        $firstId = $first['station']->id;
        $this->getJson($base.'&station_ids[]='.$firstId.'&station_id='.$second['station']->id)->assertUnprocessable();
        $this->getJson($base.'&station_ids[]='.$firstId.'&station_ids[]='.$firstId)->assertUnprocessable();
        $this->getJson($base.'&station_ids[]='.$firstId)->assertOk()->assertJsonCount(1, 'placements')
            ->assertJsonPath('placements.0.campaign_id', $campaign['id'])
            ->assertJsonPath('placements.0.station_id', $firstId);
        $this->assertDatabaseCount('commercial_campaign_deliveries', 0);
    }

    public function test_sponsored_pages_count_only_current_paid_entitled_placements_not_stale_approved_rows(): void
    {
        $this->freezeTime();
        $first = $this->fixture();
        $stale = $this->approved($first);
        $second = $this->fixture();
        $validFirst = $this->approved($second);
        $third = $this->fixture();
        $validSecond = $this->approved($third);
        $beforeDeliveries = DB::table('commercial_campaign_deliveries')->count();
        // Approval alone is not delivery eligibility: the first
        // subscription now has a refund credit against its paid invoice.
        app(SubscriptionCreditService::class)->refund(
            $first['admin'], $first['subscription']->id, $first['invoice']->id,
            1, 'Synthetic credited package must no longer deliver', 'pagination-credit'
        );
        $this->assertDatabaseHas('commercial_campaigns', [
            'id' => $stale['id'], 'status' => 'approved',
        ]);
        $beforeLedgers = DB::table('commercial_subscription_ledger')->count();
        $beforeJournals = DB::table('finance_journals')->count();

        $url = '/campaign-test/commercial/campaign-placements?country=AE&placement=discovery_banner&per_page=1';
        $this->actingAs($first['customer'])->getJson($url.'&page=1')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('placements.0.campaign_id', $validFirst['id'])
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('client_telemetry_billable', false)
            ->assertJsonMissing(['campaign_id' => $stale['id']]);

        $this->getJson($url.'&page=2')->assertOk()
            ->assertJsonPath('placements.0.campaign_id', $validSecond['id'])
            ->assertJsonPath('current_page', 2)->assertJsonPath('last_page', 2);
        $this->getJson($url.'&page=3')->assertOk()
            ->assertJsonCount(0, 'placements')
            ->assertJsonPath('current_page', 3)->assertJsonPath('last_page', 2);

        $this->getJson($url.'&page=0')->assertUnprocessable();
        $this->getJson(str_replace('&per_page=1', '&per_page=0', $url))->assertUnprocessable();
        $this->getJson(str_replace('&per_page=1', '&per_page=26', $url))->assertUnprocessable();
        $this->assertDatabaseCount('commercial_campaign_deliveries', $beforeDeliveries);
        // The query itself cannot reserve or spend package delivery units.
        $this->assertSame($beforeLedgers, DB::table('commercial_subscription_ledger')->count());
        $this->assertSame($beforeJournals, DB::table('finance_journals')->count());
    }

    public function test_sponsored_catalog_filters_before_final_eligible_pagination(): void
    {
        $one = $this->fixture();
        $campaignOne = $this->approved($one);
        $two = $this->fixture();
        $campaignTwo = $this->approved($two);
        $this->actingAs($one['customer']);
        $url = '/api/commercial/campaign-placements?placement=discovery_banner'
            .'&station_ids[]='.$two['station']->id.'&per_page=1';
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'placements')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('placements.0.campaign_id', $campaignTwo['id'])
            ->assertJsonMissing(['campaign_id' => $campaignOne['id']]);
        $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(0, 'placements')
            ->assertJsonPath('last_page', 1);
        $this->assertDatabaseCount('commercial_campaign_deliveries', 0);
    }

    public function test_spent_server_delivery_budget_is_not_a_live_sponsored_placement_page(): void
    {
        $this->freezeTime();
        $first = $this->fixture();
        $first['values']['budget_units'] = 1;
        $spent = $this->approved($first);
        $second = $this->fixture();
        $active = $this->approved($second);
        $this->delivery($spent['id'], 'server-proven-unit')->assertOk();
        $before = DB::table('commercial_campaign_deliveries')->count();
        $beforeLedger = DB::table('commercial_subscription_ledger')->count();

        $url = '/api/commercial/campaign-placements?country=AE'
            .'&placement=discovery_banner&per_page=1';
        $this->actingAs($first['customer'])->getJson($url)->assertOk()
            ->assertJsonCount(1, 'placements')
            ->assertJsonPath('placements.0.campaign_id', $active['id'])
            ->assertJsonPath('last_page', 1)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('placements.0.compatibility_verified', false)
            ->assertJsonPath('placements.0.capability_override', false);
        $this->getJson($url.'&page=2')->assertOk()
            ->assertJsonCount(0, 'placements')
            ->assertJsonPath('total', 1);
        $this->assertSame($before, DB::table('commercial_campaign_deliveries')->count());
        $this->assertSame($beforeLedger, DB::table('commercial_subscription_ledger')->count());
    }

    public function test_default_sponsored_page_is_full_when_first_paid_package_budget_is_exhausted(): void
    {
        $this->freezeTime();
        $f = $this->fixture(['campaign_slots' => 30, 'campaign_units' => 50]);
        $service = app(CampaignService::class);
        $ids = [];
        for ($i = 0; $i < 26; $i++) {
            $values = $f['values'];
            $values['budget_units'] = 1;
            $values['idempotency_key'] = 'full-paid-page-'.$i;
            $campaign = $service->create($f['vendor'], $values);
            $ids[] = $campaign->id;
            $service->action($campaign->id, $f['vendor'], 'submit',
                'Vendor requests approval of full page '.$i);
            $service->action($campaign->id, $f['admin'], 'approve',
                'Administrator approved package source '.$i);
        }
        $this->delivery($ids[0], 'spent-first-page')->assertOk();
        $receipts = DB::table('commercial_campaign_deliveries')->count();
        $journals = DB::table('finance_journals')->count();
        $this->actingAs($f['customer'])
            ->getJson('/api/commercial/campaign-placements?country=AE&placement=discovery_banner')
            ->assertOk()->assertJsonCount(25, 'placements')
            ->assertJsonPath('placements.0.campaign_id', $ids[1])
            ->assertJsonPath('placements.24.campaign_id', $ids[25])
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonPath('total', 25)
            ->assertJsonPath('per_page', 25);
        $this->assertSame($receipts, DB::table('commercial_campaign_deliveries')->count());
        $this->assertSame($journals, DB::table('finance_journals')->count());
    }

    public function test_disabled_sponsorship_returns_bounded_empty_page_without_consuming_package_or_queries(): void
    {
        $f = $this->fixture();
        $approved = $this->approved($f);
        $this->assertDatabaseHas('commercial_campaigns', [
            'id' => $approved['id'], 'status' => 'approved',
        ]);
        $beforeUnits = DB::table('commercial_campaign_deliveries')->count();
        $beforeJournals = DB::table('finance_journals')->count();
        config(['campaigns.enabled' => false]);
        $this->actingAs($f['customer'])
            ->getJson('/api/commercial/campaign-placements?country=AE'
                .'&placement=discovery_banner&page=2&per_page=1')
            ->assertOk()->assertJsonCount(0, 'placements')
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonPath('total', 0)
            ->assertJsonPath('client_telemetry_billable', false);
        $this->assertSame($beforeUnits, DB::table('commercial_campaign_deliveries')->count());
        $this->assertSame($beforeJournals, DB::table('finance_journals')->count());
    }

    public function test_suspended_or_demoted_paid_vendor_cannot_buy_visibility_or_consume_signed_delivery(): void
    {
        $this->freezeTime();
        $blocked = $this->fixture();
        $suspendedCampaign = $this->approved($blocked);
        $valid = $this->fixture();
        $activeCampaign = $this->approved($valid);
        $this->assertSame('approved', DB::table('commercial_campaigns')
            ->where('id', $suspendedCampaign['id'])->value('status'));
        $cash = DB::table('commercial_subscription_ledger')->count();
        $journals = DB::table('finance_journals')->count();
        $receipts = DB::table('commercial_campaign_deliveries')->count();
        $url = '/api/commercial/campaign-placements?country=AE&placement=discovery_banner&per_page=1';

        // A previously approved and fully paid campaign loses placement
        // authority as soon as its owning business account is suspended.
        DB::table('users')->where('id', $blocked['vendor']->id)
            ->update(['account_status' => 'suspended']);
        $this->actingAs($valid['customer'])->getJson($url)
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonPath('placements.0.campaign_id', $activeCampaign['id']);
        $this->getJson($url.'&page=2')->assertOk()
            ->assertJsonCount(0, 'placements')->assertJsonPath('total', 1);
        $this->delivery($suspendedCampaign['id'], 'suspended-vendor')->assertConflict();
        $this->assertSame($receipts, DB::table('commercial_campaign_deliveries')->count());

        // A change in business role must also revoke publishing. Neither
        // a source campaign status nor paid entitlement is rewritten.
        DB::table('users')->where('id', $blocked['vendor']->id)
            ->update(['account_status' => 'active', 'role' => 'customer']);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1);
        $this->delivery($suspendedCampaign['id'], 'demoted-vendor')->assertConflict();

        // Reinstatement restores the existing paid entitlement without
        // minting a new unit, charge, campaign or unreviewed approval.
        DB::table('users')->where('id', $blocked['vendor']->id)
            ->update(['role' => 'vendor']);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('last_page', 2)
            ->assertJsonPath('placements.0.campaign_id', $suspendedCampaign['id']);
        $this->getJson($url.'&page=2')->assertOk()
            ->assertJsonPath('placements.0.campaign_id', $activeCampaign['id']);
        $this->delivery($suspendedCampaign['id'], 'restored-vendor')->assertOk()
            ->assertJsonPath('duplicate', false);
        $this->delivery($suspendedCampaign['id'], 'restored-vendor')->assertOk()
            ->assertJsonPath('duplicate', true);
        $this->assertDatabaseHas('commercial_campaigns', [
            'id' => $suspendedCampaign['id'], 'status' => 'approved',
        ]);
        $this->assertSame($receipts + 1, DB::table('commercial_campaign_deliveries')->count());
        $this->assertSame($cash, DB::table('commercial_subscription_ledger')->count());
        $this->assertSame($journals, DB::table('finance_journals')->count());
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

    public function test_revoked_vendor_and_admin_must_not_read_private_campaign_or_paid_package_history(): void
    {
        $f = $this->fixture();
        $campaign = $this->create($f);
        $url = '/campaign-test/commercial';
        $before = DB::table('commercial_campaign_events')->count();
        foreach ([$f['vendor'], $f['admin']] as $reader) {
            $record = DB::table('users')->where('id', $reader->id)
                ->first(['role', 'account_status', 'auth_epoch', 'password']);
            $original = [
                'role' => $record->role,
                'account_status' => $record->account_status,
                'auth_epoch' => $record->auth_epoch,
                'password' => $record->password,
            ];
            $this->actingAs($reader);
            $this->getJson($url.'/campaigns')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->getJson($url.'/campaigns/'.$campaign['id'])->assertOk();
            $this->getJson($url.'/campaign-packages')->assertOk();

            foreach ([
                ['role' => 'customer'],
                ['account_status' => 'suspended'],
                ['auth_epoch' => (int) $record->auth_epoch + 1],
                ['password' => Hash::make('rotated-private-campaign-session')],
            ] as $change) {
                DB::table('users')->where('id', $reader->id)->update($change);
                $this->getJson($url.'/campaigns')->assertConflict();
                $this->getJson($url.'/campaigns/'.$campaign['id'])->assertConflict();
                $this->getJson($url.'/campaign-packages')->assertConflict();
                if ($record->role === 'vendor') {
                    $this->getJson($url.'/campaign-stations')->assertConflict();
                }
                $this->postJson($url.'/campaigns/'.$campaign['id'].'/cancel', [
                    'reason' => 'Former controller can never change campaign',
                ])->assertNotFound();
                DB::table('users')->where('id', $reader->id)->update($original);
            }
        }

        $this->assertSame($before, DB::table('commercial_campaign_events')->count());
        $this->assertDatabaseHas('commercial_campaigns', [
            'id' => $campaign['id'], 'status' => 'draft',
        ]);
        $this->assertDatabaseCount('commercial_campaign_deliveries', 0);
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

    public function test_cancelled_creation_intent_is_terminal_and_does_not_consume_package_or_cash(): void
    {
        $f = $this->fixture();
        $cash = DB::table('commercial_subscription_ledger')->count();
        $path = '/campaign-test/commercial/campaign-intents/cancel';
        $body = ['idempotency_key' => $f['values']['idempotency_key']];
        $this->actingAs($f['vendor'])->postJson($path, $body)->assertOk()->assertJsonPath('cancelled', true)->assertJsonPath('campaign', null);
        $this->postJson($path, $body)->assertOk()->assertJsonPath('cancelled', true);
        $this->assertDatabaseCount('commercial_campaign_cancelled_intents', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commercial_campaign.intent_cancelled')->count());
        $this->postJson('/campaign-test/commercial/campaigns', $f['values'])->assertConflict();
        $this->assertDatabaseCount('commercial_campaigns', 0);
        $f['values']['idempotency_key'] = 'new-explicit-creation';
        $this->create($f);
        $this->assertDatabaseCount('commercial_campaigns', 1);
        $this->assertDatabaseCount('commercial_subscription_ledger', $cash);
        $this->assertDatabaseCount('finance_payments', 0);
        try {
            DB::transaction(fn () => DB::table('commercial_campaign_cancelled_intents')->delete());
            $this->fail('A cancelled creation intent was unsealed.');
        } catch (QueryException) {
            $this->assertDatabaseCount('commercial_campaign_cancelled_intents', 1);
        }
    }

    public function test_resolving_an_accepted_intent_recovers_its_receipt_without_cancelling_the_campaign(): void
    {
        $f = $this->fixture();
        $campaign = $this->create($f);
        $path = '/campaign-test/commercial/campaign-intents/cancel';
        $body = ['idempotency_key' => $f['values']['idempotency_key']];
        $this->postJson($path, $body)->assertOk()->assertJsonPath('cancelled', false)->assertJsonPath('campaign.id', $campaign['id'])->assertJsonPath('campaign.status', 'draft');
        $this->actingAs($f['other'])->postJson($path, $body)->assertOk()->assertJsonPath('cancelled', true)->assertJsonPath('campaign', null);
        $this->actingAs($f['customer'])->postJson($path, $body)->assertForbidden();
        $this->actingAs($f['admin'])->postJson($path, $body)->assertForbidden();
        $this->create($f);
        $this->assertDatabaseCount('commercial_campaigns', 1);
        $this->assertDatabaseHas('commercial_campaigns', ['id' => $campaign['id'], 'status' => 'draft']);
    }

    public function test_campaign_control_revision_rejects_stale_aba_decisions(): void
    {
        $f = $this->fixture();
        $campaign = $this->create($f);
        $path = '/campaign-test/commercial/campaigns/'.$campaign['id'];
        $submitted = $this->postJson($path.'/submit', ['reason' => 'Review this explicit creative', 'expected_control_revision' => $campaign['control_revision']])->assertOk()->json('campaign');
        $approved = $this->actingAs($f['admin'])->postJson($path.'/approve', ['reason' => 'Reviewed creative approved', 'expected_control_revision' => $submitted['control_revision']])->assertOk()->json('campaign');
        $paused = $this->actingAs($f['vendor'])->postJson($path.'/pause', ['reason' => 'Vendor pauses delivery', 'expected_control_revision' => $approved['control_revision']])->assertOk()->json('campaign');
        $this->postJson($path.'/resume', ['reason' => 'Vendor resumes delivery', 'expected_control_revision' => $paused['control_revision']])->assertOk()->assertJsonPath('campaign.status', 'approved');
        $this->postJson($path.'/pause', ['reason' => 'Vendor pauses delivery', 'expected_control_revision' => $approved['control_revision']])->assertConflict();
        $this->assertDatabaseHas('commercial_campaigns', ['id' => $campaign['id'], 'status' => 'approved']);
        $this->assertSame(5, DB::table('commercial_campaign_events')->where('campaign_id', $campaign['id'])->count());
    }

    public function test_old_authenticated_actor_cannot_cancel_or_create_after_global_revocation(): void
    {
        $f = $this->fixture();
        DB::table('users')->where('id', $f['vendor']->id)->increment('auth_epoch');
        foreach (['create', 'cancel'] as $operation) {
            try {
                $service = app(CampaignService::class);
                if ($operation === 'create') {
                    $service->create($f['vendor'], $f['values']);
                } else {
                    $service->cancelIntent($f['vendor'], $f['values']['idempotency_key']);
                }
                $this->fail('Revoked authority was accepted.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('commercial_campaigns', 0);
        $this->assertDatabaseCount('commercial_campaign_cancelled_intents', 0);
    }

    public function test_native_vendor_contract_works_without_relaxing_admin_bearer_restrictions(): void
    {
        $f = $this->fixture();
        $this->app['auth']->forgetGuards();
        $this->withToken($f['vendor']->createToken('native-campaign')->plainTextToken)
            ->postJson('/api/v1/commercial/campaigns', $f['values'])->assertCreated()->assertJsonPath('campaign.vendor_id', $f['vendor']->id);
        $this->getJson('/api/v1/commercial/campaigns')->assertOk()->assertJsonPath('adapter_enabled', true)->assertJsonPath('total', 1);
        $this->postJson('/api/v1/commercial/campaign-intents/cancel', ['idempotency_key' => $f['values']['idempotency_key']])->assertOk()->assertJsonPath('cancelled', false);
        $this->app['auth']->forgetGuards();
        $this->withToken($f['admin']->createToken('forbidden-admin-native')->plainTextToken)->getJson('/api/v1/commercial/campaigns')->assertForbidden();
    }
}
