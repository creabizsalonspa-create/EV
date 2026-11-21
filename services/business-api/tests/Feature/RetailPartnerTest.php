<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Retail\PartnerRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RetailPartnerTest extends TestCase
{
    use RefreshDatabase;

    private function terms(): array
    {
        return ['reference' => 'PARTNER-001', 'legal_name' => 'Example Retail LLC', 'display_name' => '<img onerror=alert(1)>', 'display_name_ar' => 'متجر تجريبي',
            'country' => 'AE', 'category' => 'retail', 'website' => 'https://example.test', 'terms' => 'Internal reviewed terms; no monetary reward authorization.',
            'effective_from' => now()->subDay()->format('Y-m-d\TH:i:sP'), 'expires_at' => now()->addMonth()->format('Y-m-d\TH:i:sP'),
            'reason' => 'Initial registry review', 'idempotency_key' => (string) Str::uuid()];
    }

    private function decision(int $id, string $action, int $revision = 1, ?string $key = null)
    {
        return $this->postJson("/api/admin/retail-partners/{$id}/{$action}", ['reason' => 'Reviewed internal governance', 'expected_revision' => $revision, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    public function test_lifecycle_separate_review_expiry_termination_and_no_money(): void
    {
        $creator = User::factory()->create(['role' => 'admin']);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $before = DB::table('finance_invoices')->count();
        $id = $this->actingAs($creator)->postJson('/api/admin/retail-partners', $this->terms())->assertCreated()
            ->assertJsonPath('partner.status', 'draft')->assertJsonPath('partner.rewards_enabled', false)->json('partner.id');
        $this->decision($id, 'approve')->assertConflict();
        $this->actingAs($reviewer);
        $this->decision($id, 'approve')->assertOk()->assertJsonPath('partner.status', 'active')->assertJsonPath('partner.currently_effective', true);
        $this->decision($id, 'suspend', 1)->assertConflict();
        $this->decision($id, 'suspend', 2)->assertOk()->assertJsonPath('partner.currently_effective', false);
        $this->travel(40)->days();
        $this->decision($id, 'reinstate', 3)->assertConflict();
        $this->decision($id, 'terminate', 3)->assertOk()->assertJsonPath('partner.status', 'terminated');
        $this->decision($id, 'reinstate', 4)->assertConflict();
        $this->assertSame($before, DB::table('finance_invoices')->count());
        $this->assertSame(4, DB::table('retail_partner_events')->count());
        $this->assertSame(4, DB::table('audit_events')->where('action', 'like', 'retail_partner.%')->count());
    }

    public function test_exact_retry_changed_payload_and_reference_conflicts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $terms = $this->terms();
        $id = $this->postJson('/api/admin/retail-partners', $terms)->assertCreated()->json('partner.id');
        $this->postJson('/api/admin/retail-partners', array_reverse($terms, true))->assertCreated()->assertJsonPath('partner.id', $id);
        $terms['legal_name'] = 'Changed';
        $this->postJson('/api/admin/retail-partners', $terms)->assertConflict();
        $terms['idempotency_key'] = (string) Str::uuid();
        $this->postJson('/api/admin/retail-partners', $terms)->assertConflict();
        $this->assertSame(1, DB::table('retail_partners')->count());
    }

    public function test_revisions_require_another_review_and_keep_history_private(): void
    {
        $a = User::factory()->create(['role' => 'admin']);
        $b = User::factory()->create(['role' => 'admin']);
        $this->actingAs($a);
        $terms = $this->terms();
        $id = $this->postJson('/api/admin/retail-partners', $terms)->json('partner.id');
        $this->actingAs($b);
        $this->decision($id, 'approve')->assertOk();
        $terms['expected_revision'] = 2;
        $terms['idempotency_key'] = (string) Str::uuid();
        $this->postJson("/api/admin/retail-partners/{$id}/revise", $terms)->assertConflict();
        $this->decision($id, 'suspend', 2)->assertOk();
        $terms['expected_revision'] = 3;
        $this->postJson("/api/admin/retail-partners/{$id}/revise", $terms)->assertOk()->assertJsonPath('partner.status', 'draft')->assertJsonPath('partner.version.version', 2);
        $this->decision($id, 'approve', 4)->assertConflict();
        $this->actingAs($a);
        $this->decision($id, 'approve', 4)->assertOk();
        $this->getJson("/api/admin/retail-partners/{$id}")->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(5, 'events.data')->assertJsonMissingPath('events.data.0.request_key')->assertJsonMissingPath('events.data.0.fingerprint');
        $this->assertSame(2, DB::table('retail_partner_versions')->count());
    }

    public function test_customer_vendor_and_guest_cannot_read_or_mutate(): void
    {
        $this->getJson('/api/admin/retail-partners')->assertUnauthorized();
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/admin/retail-partners')->assertForbidden();
            $this->postJson('/api/admin/retail-partners', $this->terms())->assertForbidden();
            $this->get('/admin/retail-partners')->assertForbidden();
        }
    }

    public function test_stale_authority_is_rechecked_inside_transaction(): void
    {
        $snapshot = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $snapshot->id)->update(['auth_epoch' => 2]);
        $this->expectException(HttpException::class);
        app(PartnerRegistry::class)->mutate($snapshot, null, 'create', $this->terms());
    }

    public function test_invalid_dates_url_reference_and_country_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['website' => 'javascript:alert(1)', 'reference' => '../bad', 'country' => 'XX', 'expires_at' => '2020-01-01T00:00:00+00:00'] as $field => $value) {
            $v = $this->terms();
            $v[$field] = $value;
            $this->postJson('/api/admin/retail-partners', $v)->assertUnprocessable();
        }
    }

    public function test_private_bounded_lists_and_historical_terms_are_complete(): void
    {
        $a = User::factory()->create(['role' => 'admin']);
        $this->actingAs($a);
        $firstId = null;
        for ($n = 0; $n < 26; $n++) {
            $v = $this->terms();
            $v['reference'] = 'PARTNER-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $id = $this->postJson('/api/admin/retail-partners', $v)->assertCreated()->json('partner.id');
            $firstId ??= $id;
        }
        $this->getJson('/api/admin/retail-partners?search=')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('last_page', 2);
        $this->getJson('/api/admin/retail-partners?page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/retail-partners?search=PARTNER-025')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/retail-partners?page=100001')->assertUnprocessable();
        $this->getJson('/api/admin/retail-partners/99999')->assertNotFound();
        $this->getJson('/api/admin/retail-partners/'.$firstId)->assertOk()->assertJsonPath('events.data.0.version_snapshot.terms', 'Internal reviewed terms; no monetary reward authorization.');
    }

    public function test_future_terms_approval_does_not_claim_current_effectiveness(): void
    {
        $a = User::factory()->create(['role' => 'admin']);
        $b = User::factory()->create(['role' => 'admin']);
        $v = $this->terms();
        $v['effective_from'] = now()->addWeek()->format('Y-m-d\TH:i:sP');
        $id = $this->actingAs($a)->postJson('/api/admin/retail-partners', $v)->assertCreated()->json('partner.id');
        $this->actingAs($b);
        $this->decision($id, 'approve')->assertOk()->assertJsonPath('partner.status', 'active')->assertJsonPath('partner.currently_effective', false);
    }

    public function test_admin_mfa_and_bearer_boundaries_are_preserved(): void
    {
        config(['admin_security.enforce_in_local_tests' => true]);
        $a = User::factory()->create(['role' => 'admin']);
        $this->actingAs($a)->getJson('/api/admin/retail-partners')->assertForbidden()->assertJsonPath('admin_second_factor_required', true);
        $this->postJson('/api/admin/retail-partners', $this->terms())->assertForbidden();
        $this->assertDatabaseCount('retail_partners', 0);
    }

    public function test_admin_personal_access_tokens_cannot_acquire_registry_authority(): void
    {
        $a = User::factory()->create(['role' => 'admin']);
        $token = $a->createToken('synthetic-registry-denial')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/retail-partners')->assertForbidden();
        $this->postJson('/api/v1/admin/retail-partners', $this->terms())->assertForbidden();
        $this->assertDatabaseCount('retail_partners', 0);
    }

    public function test_history_is_database_immutable(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/api/admin/retail-partners', $this->terms())->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('retail_partner_versions')->update(['terms' => 'tampered']);
    }
}
