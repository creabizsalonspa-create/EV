<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\RecordSynchronizer;
use App\Services\Ocpi\TechnicalAgreement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class OcpiTechnicalAgreementTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        config(['ocpi.connections.agreed' => ['managed_credentials' => true, 'technical_agreement_required' => true,
            'origin' => 'https://peer.example', 'authorization_token' => base64_encode('SYNTHETIC-BOOTSTRAP-PRIVATE'),
            'party_country' => 'NL', 'party_id' => 'ABC', 'party_role' => 'CPO', 'incoming_modules' => ['locations', 'sessions']],
            'ocpi.local_credentials' => ['url' => 'https://platform.example/api/roaming-protocol/versions', 'roles' => [['role' => 'EMSP', 'country_code' => 'AE', 'party_id' => 'EVX', 'business_details' => ['name' => 'Synthetic local identity']]]]]);
        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $p = OcpiProvider::create(['owner_id' => $vendor->id, 'connection_key' => 'agreed', 'name' => 'Synthetic approved peer', 'authorized' => false]);

        return [$p, $admin, $vendor, '/api/ocpi/providers/'.$p->id.'/technical-agreements'];
    }

    private function draft(int $revision = 0): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_revision' => $revision,
            'reference' => 'SYNTHETIC-LEGAL-REVIEW-123', 'evidence_sha256' => hash('sha256', 'synthetic signed evidence, not a real agreement'),
            'terms_en' => 'Synthetic technical catalog redistribution scope. No commercial authority.',
            'terms_ar' => 'نطاق تقني اصطناعي لاختبار الكتالوج فقط دون أي صلاحية مالية.',
            'allowed_modules' => ['locations', 'sessions'], 'valid_from' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'),
            'valid_until' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    private function decision(int $revision = 1, string $action = 'approved'): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_revision' => $revision, 'action' => $action, 'reason' => 'Synthetic human-reviewed technical evidence, not live partner acceptance.'];
    }

    private function approve(OcpiProvider $p, User $admin, string $base): int
    {
        $id = $this->actingAs($admin)->postJson($base, $this->draft())->assertCreated()->json('agreement_id');
        $this->postJson($base.'/'.$id.'/decision', $this->decision())->assertOk();

        return $id;
    }

    public function test_revisioned_review_is_private_encrypted_immutable_and_never_activates_a_provider(): void
    {
        [$p, $admin, $vendor, $base] = $this->fixture();
        $this->getJson($base)->assertUnauthorized();
        $this->actingAs($vendor)->getJson($base)->assertForbidden();
        $draft = $this->draft();
        $id = $this->actingAs($admin)->postJson($base, $draft)->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('external_requests_made', false)->json('agreement_id');
        $this->postJson($base, $draft)->assertCreated()->assertJsonPath('agreement_id', $id);
        $this->assertSame(1, DB::table('ocpi_technical_agreements')->count());
        $raw = DB::table('ocpi_technical_agreements')->find($id);
        $this->assertStringNotContainsString($draft['terms_en'], $raw->payload_ciphertext);
        $this->assertSame($draft['terms_en'], json_decode(Crypt::decryptString($raw->payload_ciphertext), true)['terms_en']);
        $this->getJson($base)->assertOk()->assertJsonPath('active_agreement_id', null)->assertJsonPath('agreements.data.0.state', 'proposed')
            ->assertJsonMissingPath('connection_key')->assertJsonMissingPath('configured_identity.origin');
        $approval = $this->decision();
        $this->postJson($base.'/'.$id.'/decision', $approval)->assertOk()->assertJsonPath('provider_activated', false);
        $this->postJson($base.'/'.$id.'/decision', $approval)->assertOk();
        $this->assertSame(1, DB::table('ocpi_technical_agreement_events')->count());
        $this->assertFalse($p->fresh()->authorized);
        $this->getJson('/api/v1/ocpi/providers/'.$p->id.'/technical-agreements')->assertOk()->assertJsonPath('active_agreement_id', $id)->assertJsonPath('partner_acceptance_verified', false);
        $this->assertSame(['locations', 'sessions'], app(TechnicalAgreement::class)->allowed($p->fresh()));
        Http::assertNothingSent();
    }

    public function test_approval_and_revocation_withdraw_existing_authority_and_bounded_scopes_gate_transport(): void
    {
        [$p, $admin, $vendor, $base] = $this->fixture();
        $p->update(['authorized' => true, 'version' => '2.2.1', 'endpoints' => ['locations' => '/locations', 'sessions' => '/sessions', 'tariffs' => '/tariffs']]);
        $this->actingAs($admin)->patchJson('/api/ocpi/providers/'.$p->id.'/authorization', ['authorized' => true])->assertStatus(422);
        $id = $this->approve($p, $admin, $base);
        $this->assertFalse($p->fresh()->authorized);
        $this->assertNull($p->fresh()->endpoints);
        $this->actingAs($admin)->patchJson('/api/ocpi/providers/'.$p->id.'/authorization', ['authorized' => true])->assertOk();
        $this->assertTrue($p->fresh()->authorized);
        $service = app(TechnicalAgreement::class);
        $service->assertActive($p->fresh(), 'locations');
        try {
            $service->assertActive($p->fresh(), 'tariffs');
            $this->fail('Undeclared module must not be authorized.');
        } catch (OcpiException) {
        }
        $this->postJson($base.'/'.$id.'/decision', $this->decision(1, 'revoked'))->assertOk();
        $this->assertFalse($p->fresh()->authorized);
        $this->assertSame([], $service->allowed($p->fresh()));
        $this->assertSame(2, DB::table('ocpi_technical_agreement_events')->count());
        Http::assertNothingSent();
    }

    public function test_mismatched_requests_stale_revisions_and_changed_peer_cannot_approve_or_replay(): void
    {
        [$p, $admin, $vendor, $base] = $this->fixture();
        $draft = $this->draft();
        $id = $this->actingAs($admin)->postJson($base, $draft)->assertCreated()->json('agreement_id');
        $this->postJson($base, array_replace($draft, ['reference' => 'DIFFERENT-INTENT']))->assertConflict();
        $this->postJson($base, $this->draft())->assertConflict();
        $this->postJson($base, $this->draft(1))->assertCreated();
        $this->postJson($base.'/'.$id.'/decision', $this->decision(2))->assertConflict();
        $new = DB::table('ocpi_technical_agreements')->orderByDesc('id')->first()->id;
        config(['ocpi.connections.agreed.party_id' => 'XYZ']);
        $this->postJson($base.'/'.$new.'/decision', $this->decision(2))->assertConflict();
        $this->assertSame(0, DB::table('ocpi_technical_agreement_events')->count());
        Http::assertNothingSent();
    }

    public function test_date_boundary_identity_drift_and_owner_hold_remove_published_access_without_a_worker(): void
    {
        [$p, $admin, $vendor, $base] = $this->fixture();
        $this->approve($p, $admin, $base);
        $p->update(['authorized' => true, 'version' => '2.2.1', 'endpoints' => ['locations' => '/locations']]);
        $this->assertSame([$p->id], OcpiProvider::discoveryAuthorized()->pluck('id')->all());
        DB::table('users')->where('id', $vendor->id)->update(['account_status' => 'suspended']);
        $this->assertSame('suspended', $vendor->fresh()->account_status);
        $this->assertSame([], OcpiProvider::discoveryAuthorized()->pluck('id')->all());
        DB::table('users')->where('id', $vendor->id)->update(['account_status' => 'active']);
        config(['ocpi.connections.agreed.origin' => 'https://changed.example']);
        $this->assertSame([], OcpiProvider::discoveryAuthorized()->pluck('id')->all());
        config(['ocpi.connections.agreed.origin' => 'https://peer.example']);
        $this->travel(2)->days();
        $this->assertSame([], OcpiProvider::discoveryAuthorized()->pluck('id')->all());
        $this->assertSame([], $p->fresh()->readable_modules);
        Http::assertNothingSent();
    }

    public function test_bad_input_and_fresh_administrator_authority_are_required(): void
    {
        [$p, $admin, $vendor, $base] = $this->fixture();
        $this->actingAs($admin)->postJson($base, array_replace($this->draft(), ['allowed_modules' => ['commands']]))->assertUnprocessable();
        $this->postJson($base, array_replace($this->draft(), ['valid_from' => '2026-02-30T00:00:00Z']))->assertUnprocessable();
        $this->postJson($base, array_replace($this->draft(), ['allowed_modules' => ['locations', 'locations']]))->assertUnprocessable();
        $this->getJson($base.'?page=100001')->assertUnprocessable();
        DB::table('users')->where('id', $admin->id)->update(['role' => 'customer']);
        $this->postJson($base, $this->draft())->assertForbidden();
        $this->assertSame(0, DB::table('ocpi_technical_agreements')->count());
        Http::assertNothingSent();
    }

    public function test_post_approval_rejection_does_not_publish_pending_amendment_or_destroy_old_evidence(): void
    {
        [$p, $admin, $vendor, $base] = $this->fixture();
        $id = $this->approve($p, $admin, $base);
        $draft = $this->draft(1);
        $next = $this->postJson($base, $draft)->assertCreated()->json('agreement_id');
        $this->postJson($base.'/'.$next.'/decision', $this->decision(2, 'rejected'))->assertOk();
        $this->assertSame($id, app(TechnicalAgreement::class)->active($p->fresh())->id);
        $this->assertSame(2, DB::table('ocpi_technical_agreements')->count());
        $this->postJson($base.'/'.$next.'/decision', $this->decision(2))->assertConflict();
    }

    public function test_actual_public_search_and_saved_source_withdraw_at_contract_expiry_while_history_survives(): void
    {
        [$p, $admin, $vendor, $base] = $this->fixture();
        $this->approve($p, $admin, $base);
        $p->update(['authorized' => true, 'version' => '2.2.1', 'endpoints' => ['locations' => 'https://peer.example/locations']]);
        $payload = ['country_code' => 'NL', 'party_id' => 'ABC', 'id' => 'agreed-location', 'publish' => true,
            'name' => 'Synthetic contracted source', 'address' => 'Fixture street', 'city' => 'Amsterdam', 'country' => 'NLD',
            'coordinates' => ['latitude' => '52.37', 'longitude' => '4.89'], 'last_updated' => now()->subMinute()->toISOString(),
            'evses' => [['uid' => 'evse-1', 'status' => 'AVAILABLE', 'connectors' => [['id' => '1', 'standard' => 'IEC_62196_T2_COMBO']]]]];
        app(RecordSynchronizer::class)->ingest($p->fresh(), 'locations', [$payload]);
        $record = OcpiRecord::where('ocpi_provider_id', $p->id)->firstOrFail();
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->getJson('/api/roaming/locations')->assertOk()->assertJsonPath('total', 1);
        $this->putJson('/api/roaming/favorites/'.$record->id)->assertOk();
        $this->getJson('/api/roaming/favorites')->assertOk()->assertJsonPath('data.0.location_available', true);
        $this->travel(2)->days();
        $this->getJson('/api/roaming/locations')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/roaming/favorites')->assertOk()->assertJsonPath('data.0.location_available', false)->assertJsonPath('data.0.location', null);
        $this->assertSame(1, OcpiRecord::where('ocpi_provider_id', $p->id)->count());
        Http::assertNothingSent();
    }

    public function test_locked_review_rechecks_current_mfa_enrollment_and_session_age(): void
    {
        [$p, $admin] = $this->fixture();
        config(['admin_security.enforce_in_local_tests' => true]);
        $encrypted = Crypt::encryptString('SYNTHETIC-TOTP-NOT-A-REAL-FACTOR');
        DB::table('admin_second_factors')->insert(['user_id' => $admin->id, 'encrypted_secret' => $encrypted,
            'last_accepted_step' => 1, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $request = Request::create('/api/ocpi/providers/'.$p->id.'/technical-agreements', 'POST');
        $request->setUserResolver(fn () => $admin);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(['admin_second_factor_user_id' => $admin->id,
            'admin_second_factor_verified_at' => now()->timestamp, 'admin_second_factor_enrollment_proof' => hash('sha256', $encrypted)]);
        app()->instance('request', $request);
        $service = app(TechnicalAgreement::class);
        $id = $service->propose($p, $admin, $this->draft());
        DB::table('admin_second_factors')->where('user_id', $admin->id)->update(['encrypted_secret' => Crypt::encryptString('ROTATED-SYNTHETIC-FACTOR')]);
        try {
            $service->decide($p, $admin, $id, $this->decision());
            $this->fail('A rotated MFA enrollment must invalidate the queued review.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $newProof = hash('sha256', DB::table('admin_second_factors')->where('user_id', $admin->id)->value('encrypted_secret'));
        $request->session()->put(['admin_second_factor_enrollment_proof' => $newProof, 'admin_second_factor_verified_at' => now()->subHours(13)->timestamp]);
        try {
            $service->decide($p, $admin, $id, $this->decision());
            $this->fail('An expired MFA grant must not approve technical access.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, DB::table('ocpi_technical_agreement_events')->count());
        $request->session()->put('admin_second_factor_verified_at', now()->timestamp);
        $this->assertSame($id, $service->decide($p, $admin, $id, $this->decision()));
        $this->assertFalse($p->fresh()->authorized);
        Http::assertNothingSent();
    }

    public function test_scope_and_expiry_gate_ingestion_and_polling_without_removing_retained_history(): void
    {
        [$p, $admin, $vendor, $base] = $this->fixture();
        $this->approve($p, $admin, $base);
        $p->update(['authorized' => true, 'version' => '2.2.1', 'endpoints' => ['locations' => 'https://peer.example/locations', 'tariffs' => 'https://peer.example/tariffs']]);
        $this->actingAs($admin);
        config(['ocpi.scheduled_sync_enabled' => true]);
        $this->putJson('/api/ocpi/providers/'.$p->id.'/sync-plans/tariffs', ['enabled' => true, 'confirmed' => true, 'interval_minutes' => 60, 'expected_revision' => '0'])->assertConflict();
        $this->assertSame(0, DB::table('ocpi_sync_plans')->count());
        $payload = ['country_code' => 'NL', 'party_id' => 'ABC', 'id' => 'retained-location', 'publish' => true,
            'name' => 'Retained synthetic source', 'address' => 'Fixture street', 'city' => 'Amsterdam', 'country' => 'NLD',
            'coordinates' => ['latitude' => '52.37', 'longitude' => '4.89'], 'last_updated' => now()->subMinute()->toISOString(),
            'evses' => [['uid' => 'evse-1', 'status' => 'AVAILABLE', 'connectors' => [['id' => '1', 'standard' => 'IEC_62196_T2_COMBO']]]]];
        $sync = app(RecordSynchronizer::class);
        $sync->ingest($p->fresh(), 'locations', [$payload]);
        $this->travel(2)->days();
        try {
            $sync->ingest($p->fresh(), 'locations', [array_replace($payload, ['id' => 'unapproved-late-location'])]);
            $this->fail('Expired technical scope must prevent a delayed source batch from committing.');
        } catch (OcpiException) {
            $this->assertSame(1, OcpiRecord::where('ocpi_provider_id', $p->id)->count());
        }
        Http::assertNothingSent();
    }

    public function test_database_rejects_a_review_linked_to_another_provider(): void
    {
        [$p, $admin, $vendor, $base] = $this->fixture();
        $id = $this->approve($p, $admin, $base);
        $other = OcpiProvider::create(['owner_id' => $vendor->id, 'name' => 'Other synthetic provider', 'connection_key' => 'other-agreement', 'authorized' => false]);
        try {
            DB::transaction(fn () => DB::table('ocpi_technical_agreement_events')->insert(['provider_id' => $other->id, 'agreement_id' => $id,
                'phase' => 'revocation', 'action' => 'revoked', 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('0', 64),
                'reason_ciphertext' => Crypt::encryptString('Synthetic cross-provider corruption probe'), 'actor_id' => $admin->id, 'created_at' => now()]));
            $this->fail('Cross-provider policy decisions must fail at the database boundary.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('ocpi_technical_agreement_events')->count());
        }
        $this->assertSame($id, app(TechnicalAgreement::class)->active($p->fresh())->id);
    }

    public function test_database_forbids_mutating_or_deleting_versioned_policy_and_decision_history(): void
    {
        [$p, $admin, $vendor, $base] = $this->fixture();
        $this->approve($p, $admin, $base);
        foreach (['ocpi_technical_agreements', 'ocpi_technical_agreement_events'] as $table) {
            foreach (['update', 'delete'] as $action) {
                try {
                    DB::transaction(fn () => $action === 'delete' ? DB::table($table)->delete() : DB::table($table)->update(['request_hash' => str_repeat('0', 64)]));
                    $this->fail('Immutable OCPI history mutation was accepted.');
                } catch (QueryException $error) {
                    $this->assertStringContainsString('OCPI agreement history is immutable', $error->getMessage());
                }
            }
        }
        $this->assertSame(1, DB::table('ocpi_technical_agreements')->count());
        $this->assertSame(1, DB::table('ocpi_technical_agreement_events')->count());
    }
}
