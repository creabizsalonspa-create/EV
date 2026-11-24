<?php

namespace Tests\Feature;

use App\Http\Controllers\CommercialFinanceController;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CommercialFinanceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $v = User::factory()->create(['role' => 'vendor']);
        $u = User::factory()->create();
        $s = Station::create(['vendor_id' => $v->id, 'name' => 'Refund fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $r = Reservation::create(['user_id' => $u->id, 'station_id' => $s->id, 'starts_at' => now(), 'idempotency_key' => 'refund-fixture']);
        $session = ChargingSession::create(['reservation_id' => $r->id, 'status' => 'stopped', 'energy_wh' => 1000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 100, 'stopped_at' => now()]);
        $f = app(FinanceService::class);
        $i = $f->issue($session, $u->id);
        $f->pay($i, $u->id, 100, 'paid');

        return [$u, $v, $i];
    }

    public function test_customer_request_vendor_review_credit_note_and_private_report(): void
    {
        [$u,$v,$i] = $this->fixture();
        $this->actingAs($u)->getJson('/api/finance/invoices/'.$i->id.'/source-credits')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('invoice_id', $i->id)
            ->assertJsonPath('total', 0)->assertJsonPath('financial_authority', false)->assertJsonCount(0, 'data');
        $body = ['amount_minor' => 60, 'reason' => 'Service issue', 'idempotency_key' => 'refund-one'];
        $a = $this->actingAs($u)->postJson("/api/finance/invoices/$i->id/refund-requests", $body)->assertCreated()->json();
        $this->postJson("/api/finance/invoices/$i->id/refund-requests", $body)->assertOk()->assertJsonPath('id', $a['id']);
        $this->postJson("/api/finance/invoices/$i->id/refund-requests", array_replace($body, ['amount_minor' => 61]))->assertConflict();
        $this->postJson("/api/finance/invoices/$i->id/refund-requests", array_replace($body, ['idempotency_key' => 'another', 'amount_minor' => 41]))->assertConflict();
        $this->postJson('/api/finance/refund-requests/'.$a['id'].'/review', ['decision' => 'approve', 'note' => 'accepted'])->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson('/api/finance/refund-requests')->assertJsonCount(0, 'data');
        $this->actingAs($v)->postJson('/api/finance/refund-requests/'.$a['id'].'/review', ['decision' => 'approve', 'note' => 'accepted'])->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/api/finance/refund-requests/'.$a['id'].'/review', ['decision' => 'approve', 'note' => 'accepted'])->assertOk();
        $this->assertDatabaseCount('finance_refunds', 1);
        $this->getJson('/api/finance/report')->assertOk()->assertJsonPath('groups.0.refunded_minor', 60)->assertJsonPath('groups.0.net_collected_minor', 40);
        $this->actingAs($u)->getJson('/api/finance/report')->assertJsonMissingPath('groups.0.commission_minor')->assertJsonPath('groups.0.invoice_count', 1);
        $this->actingAs(User::factory()->create())->getJson('/api/finance/report')->assertJsonCount(0, 'groups');
    }

    public function test_private_refund_requests_never_echo_idempotency_secrets_while_preserving_exact_one_refund(): void
    {
        [$customer, $vendor, $invoice] = $this->fixture();
        $key = 'raw-private-customer-replay-nonce-11-23';
        $body = [
            'amount_minor' => 40, 'reason' => 'Original invoice dispute',
            'idempotency_key' => $key,
        ];
        $url = '/api/finance/invoices/'.$invoice->id.'/refund-requests';

        $this->actingAs($customer);
        $created = $this->postJson($url, $body)->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('request_key')
            ->assertJsonMissingPath('fingerprint');
        $id = $created->json('id');
        $this->assertDatabaseHas('finance_refund_requests', [
            'id' => $id, 'user_id' => $customer->id, 'request_key' => $key,
            'status' => 'pending',
        ]);
        $this->assertStringNotContainsString($key, $created->getContent());

        $this->postJson($url, $body)->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonMissingPath('request_key')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/finance/refund-requests')
            ->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.request_key');

        $this->actingAs($vendor);
        $this->getJson('/api/finance/refund-requests')
            ->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.request_key');
        $review = ['decision' => 'approve', 'note' => 'Provider mock only'];
        $this->postJson('/api/finance/refund-requests/'.$id.'/review', $review)
            ->assertOk()->assertJsonPath('status', 'approved')
            ->assertJsonMissingPath('request_key')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/finance/refund-requests/'.$id.'/review', $review)
            ->assertOk()->assertJsonMissingPath('request_key');
        $this->assertDatabaseCount('finance_refunds', 1);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/finance/refund-requests')
            ->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.request_key');
        $this->actingAs($customer)->getJson('/api/finance/refund-requests')
            ->assertOk()->assertJsonMissingPath('data.0.fingerprint');
        $this->assertDatabaseHas('finance_refund_requests', [
            'id' => $id, 'request_key' => $key, 'status' => 'approved',
        ]);
        $this->assertDatabaseCount('finance_refunds', 1);
    }

    public function test_refund_post_only_acknowledgement_binds_original_request_without_echoing_replay_key(): void
    {
        [$customer, $vendor, $invoice] = $this->fixture();
        $url = '/api/finance/invoices/'.$invoice->id.'/refund-requests';
        $key = 'synthetic-private-original-key';
        $body = ['amount_minor' => 20, 'reason' => 'Synthetic private receipt', 'idempotency_key' => $key];
        $created = $this->actingAs($customer)->postJson($url, $body)->assertCreated()
            ->assertJsonMissingPath('request_key')
            ->assertHeader('Cache-Control', 'no-store, private');
        $id = $created->json('id');
        $expected = hash('sha256', 'refund-request-v1:'.$customer->id.':'.$invoice->id.':'.$id.':'.$key);
        $created->assertJsonPath('request_receipt', $expected);
        $this->assertStringNotContainsString($key, $created->getContent());
        $this->postJson($url, $body)->assertOk()->assertJsonPath('id', $id)
            ->assertJsonPath('request_receipt', $expected)
            ->assertJsonMissingPath('request_key');
        $this->postJson($url, array_replace($body, ['amount_minor' => 21]))->assertConflict();

        // A separate request with the same economic terms has another identity.
        $second = $this->postJson($url, array_replace($body, ['idempotency_key' => 'synthetic-second-key']))->assertCreated();
        $this->assertNotSame($id, $second->json('id'));
        $this->assertNotSame($expected, $second->json('request_receipt'));
        $this->getJson('/api/finance/refund-requests')->assertOk()
            ->assertJsonMissingPath('data.0.request_receipt')
            ->assertJsonMissingPath('data.1.request_receipt');
        $this->actingAs($vendor)->getJson('/api/finance/refund-requests')
            ->assertOk()->assertJsonMissingPath('data.0.request_receipt');
        $this->postJson('/api/finance/refund-requests/'.$id.'/review', [
            'decision' => 'reject', 'note' => 'Synthetic reviewer decision',
        ])->assertOk()->assertJsonMissingPath('request_receipt')
            ->assertJsonMissingPath('request_key');
        $this->assertDatabaseCount('finance_refund_requests', 2);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_private_refund_validation_never_precedes_current_account_and_invoice_authorization(): void
    {
        [$customer, $vendor, $invoice] = $this->fixture();
        $url = '/api/finance/invoices/'.$invoice->id.'/refund-requests';
        $this->actingAs($customer)->postJson($url, [
            'amount_minor' => 0,
        ])->assertUnprocessable();

        $created = $this->postJson($url, [
            'amount_minor' => 10, 'reason' => 'Review source',
            'idempotency_key' => 'authorized-only',
        ])->assertCreated();
        $id = $created->json('id');
        $this->actingAs(User::factory()->create())
            ->postJson('/api/finance/refund-requests/'.$id.'/review', [
                'decision' => 'forged', 'note' => '',
            ])->assertNotFound();
        $this->actingAs($vendor)->postJson('/api/finance/refund-requests/'.$id.'/review', [
            'decision' => 'forged', 'note' => '',
        ])->assertUnprocessable();

        // Deliberately retain the old authenticated Laravel model while
        // revoking the actual persisted finance-review account role.
        DB::table('users')->where('id', $vendor->id)
            ->update(['role' => 'customer']);
        $this->postJson('/api/finance/refund-requests/'.$id.'/review', [
            'decision' => 'forged', 'note' => '',
        ])->assertConflict();
        $this->assertDatabaseHas('finance_refund_requests', [
            'id' => $id, 'status' => 'pending',
        ]);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_aggregate_reporting_refuses_browser_inexact_minor_unit_integer_before_json_serialization(): void
    {
        $method = new \ReflectionMethod(
            CommercialFinanceController::class,
            'exactAggregate'
        );
        $this->assertSame(0, $method->invoke(null, '0'));
        $this->assertSame(9_007_199_254_740_991,
            $method->invoke(null, '9007199254740991'));

        foreach ([
            '-1', '9007199254740992', '99999999999999999999999',
            '1.5', 1.5, 'garbled',
        ] as $unsafe) {
            try {
                $method->invoke(null, $unsafe);
                $this->fail('Unsafe aggregate passed integer validation: '.json_encode($unsafe));
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
    }

    public function test_rejection_releases_reserved_amount_and_validation(): void
    {
        [$u,$v,$i] = $this->fixture();
        $body = ['amount_minor' => 100, 'reason' => 'Issue', 'idempotency_key' => 'a'];
        $id = $this->actingAs($u)->postJson("/api/finance/invoices/$i->id/refund-requests", $body)->json('id');
        $this->actingAs($v)->postJson("/api/finance/refund-requests/$id/review", ['decision' => 'reject', 'note' => 'Not eligible'])->assertOk();
        $this->actingAs($u)->postJson("/api/finance/invoices/$i->id/refund-requests", array_replace($body, ['idempotency_key' => 'b']))->assertCreated();
        $this->getJson('/api/finance/report?currency=invalid')->assertUnprocessable();
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_external_provider_invoice_cannot_use_ledger_only_simulation_after_config_switch(): void
    {
        [$u, $v, $i] = $this->fixture();
        DB::table('gateway_checkouts')->insert(['invoice_id' => $i->id, 'user_id' => $u->id, 'provider' => 'stripe_test', 'provider_reference' => 'pi_guard', 'request_key' => 'guard', 'fingerprint' => str_repeat('0', 64), 'currency' => 'AED', 'amount_minor' => 100, 'status' => 'captured', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($u)->postJson("/api/finance/invoices/$i->id/simulate-payment", ['amount_minor' => 1, 'idempotency_key' => 'guard'])->assertStatus(503);
        $id = $this->postJson("/api/finance/invoices/$i->id/refund-requests", ['amount_minor' => 10, 'reason' => 'Refund issue', 'idempotency_key' => 'guard'])->assertCreated()->json('id');
        $this->actingAs($v)->postJson("/api/finance/invoices/$i->id/simulate-refund", ['amount_minor' => 10, 'reason' => 'Issue', 'idempotency_key' => 'guard'])->assertStatus(503);
        $this->postJson("/api/finance/refund-requests/$id/review", ['decision' => 'approve', 'note' => 'Approved'])->assertStatus(503);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_native_and_cookie_clients_share_financial_records_and_report_filters(): void
    {
        [$customer,$vendor,$invoice] = $this->fixture();
        $token = $customer->createToken('finance-contract', ['*'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/finance/report?to='.now()->toDateString())->assertOk()->assertJsonPath('groups.0.net_collected_minor', 100);
        $request = $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/refund-requests', ['amount_minor' => 10, 'reason' => 'Native contract', 'idempotency_key' => 'native'])->assertCreated()->json();
        $this->withHeader('Authorization', '')->actingAs($vendor)->getJson('/api/finance/refund-requests')->assertOk()->assertJsonPath('data.0.id', $request['id']);
        $this->getJson('/api/finance/report?from=2026-02-02&to=2026-01-01')->assertUnprocessable();
    }
}
