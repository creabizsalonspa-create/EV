<?php

namespace Tests\Feature;

use App\Models\Charging\AdmissionProfile;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\ChargerCertificates;
use App\Services\Charging\ChargerCertificateVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesChargerCertificate;
use Tests\Support\CreatesChargerCertificateChain;
use Tests\TestCase;

class ChargerCertificateChainTest extends TestCase
{
    use CreatesChargerCertificate, CreatesChargerCertificateChain, RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic model chain', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'name' => 'Chain fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'CHAIN', 'manufacturer' => 'Synthetic', 'model' => 'Issuer chain', 'source' => 'simulator', 'ocpp_protocol' => '2.0.1']);
        $base = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/admission';
        $profile = $this->actingAs($owner)->postJson($base, ['identity' => 'ev-test-chain'])->assertCreated()->json();

        return [$owner, $base, $profile];
    }

    private function intent(User $owner, array $profile, string $pem, string $chain): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'expected_actor_id' => $owner->id, 'expected_profile_id' => $profile['id'], 'expected_version' => 0, 'reason' => 'Explicit independently rooted issuer chain enrollment', 'consent' => true, 'public_certificate' => $pem, 'issuer_chain' => $chain];
    }

    public function test_ec_client_chain_is_pinned_encrypted_immutable_and_rechecked_after_issuer_policy_changes(): void
    {
        [$owner, $base, $profile] = $this->fixture();
        [$pem, $chain, $root] = $this->certificateChain();
        $intent = $this->intent($owner, $profile, $pem, $chain);
        $receipt = $this->postJson($base.'/certificates/enroll', $intent)->assertOk()->assertJsonPath('certificate.chain_length', 1)->assertJsonPath('certificate.key_type', 'EC')->json();
        $this->assertSame(openssl_x509_fingerprint($chain, 'sha256'), $receipt['certificate']['issuer_fingerprint']);
        $this->assertSame(openssl_x509_fingerprint($root, 'sha256'), $receipt['certificate']['trust_anchor_fingerprint']);
        $stored = DB::table('charger_certificates')->find($receipt['certificate_id']);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $stored->issuer_chain);
        $this->assertSame(hash('sha256', Crypt::decryptString($stored->issuer_chain)), $stored->chain_sha256);
        $this->postJson($base.'/certificates/enroll', $intent)->assertOk()->assertJsonPath('certificate_id', $receipt['certificate_id']);
        $this->postJson($base.'/credential', ['expected_version' => 1, 'days' => 1])->assertCreated();
        $this->getJson($base.'/certificates')->assertOk()->assertDontSee('BEGIN CERTIFICATE')->assertJsonPath('data.0.chain_length', 1);
        config(['csms.certificate_denied_issuers' => [$stored->issuer_fingerprint]]);
        $this->getJson($base.'/certificates')->assertOk()->assertJsonPath('data.0.state', 'requires_review');
        $this->postJson($base.'/credential', ['expected_version' => 2, 'days' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_two_intermediate_path_is_resolved_without_trusting_submission_order(): void
    {
        [$owner, $base, $profile] = $this->fixture();
        [$pem, $chain, $root] = $this->certificateChain('ev-test-chain', 'two_intermediates');
        $this->postJson($base.'/certificates/enroll', $this->intent($owner, $profile, $pem, $chain."\n".$root))->assertOk()->assertJsonPath('certificate.chain_length', 2)->assertJsonPath('certificate.trust_anchor_fingerprint', openssl_x509_fingerprint($root, 'sha256'));
        $this->postJson($base.'/credential', ['expected_version' => 1, 'days' => 1])->assertCreated();
        $this->assertDatabaseCount('charger_certificates', 1);
    }

    public function test_expired_pinned_root_does_not_block_a_separate_current_trust_path(): void
    {
        [, $oldRoot] = $this->certificatePair('old-leaf', 'clientAuth', 2048, 1, 1);
        [$pem, $chain, $root] = $this->certificateChain();
        file_put_contents(config('csms.certificate_issuer_bundle'), $oldRoot."\n".$root);
        config(['csms.certificate_issuer_pins' => [openssl_x509_fingerprint($oldRoot, 'sha256'), openssl_x509_fingerprint($root, 'sha256')]]);
        $this->travel(2)->days();
        $verified = app(ChargerCertificateVerifier::class)->verify($pem, 'ev-test-chain', $chain);
        $this->assertSame(openssl_x509_fingerprint($root, 'sha256'), $verified['trust_anchor_fingerprint']);
        $this->assertSame(1, $verified['chain_length']);
        $this->travelBack();
    }

    #[TestWith(['missing'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['untrusted_root'])]
    #[TestWith(['non_ca'])]
    #[TestWith(['issuer_purpose'])]
    #[TestWith(['path_length'])]
    #[TestWith(['private_key'])]
    public function test_client_supplied_chain_cannot_extend_trust_or_bypass_x509_constraints(string $kind): void
    {
        [$owner, $base, $profile] = $this->fixture();
        [$pem, $chain, $root] = $this->certificateChain('ev-test-chain', $kind);
        if ($kind === 'missing') {
            $chain = '';
        }
        if ($kind === 'duplicate') {
            $chain .= "\n".$chain;
        }
        if ($kind === 'untrusted_root') {
            $chain .= "\n".$root;
            $this->certificatePair('another-root-leaf');
        }
        if ($kind === 'private_key') {
            $chain = "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----";
        }
        $this->postJson($base.'/certificates/enroll', $this->intent($owner, $profile, $pem, $chain))->assertUnprocessable();
        $this->assertDatabaseCount('charger_certificates', 0);
        $this->assertDatabaseCount('charger_certificate_actions', 0);
        $this->assertDatabaseHas('charger_admission_profiles', ['id' => $profile['id'], 'credential_version' => 0, 'managed_certificate_id' => null]);
    }

    public function test_existing_direct_issuer_records_without_chain_metadata_keep_their_trust_boundary(): void
    {
        [, $base, $profile] = $this->fixture();
        [$pem] = $this->certificatePair('ev-test-chain');
        $metadata = app(ChargerCertificateVerifier::class)->verify($pem, 'ev-test-chain');
        unset($metadata['issuer_chain'], $metadata['chain_length'], $metadata['chain_sha256'], $metadata['trust_anchor_fingerprint']);
        $id = DB::table('charger_certificates')->insertGetId(['profile_id' => $profile['id'], 'public_certificate' => Crypt::encryptString($pem), 'created_at' => now()] + $metadata);
        $p = AdmissionProfile::findOrFail($profile['id']);
        $p->update(['managed_certificate_id' => $id, 'certificate_sha256' => $metadata['fingerprint']]);
        $this->assertSame($id, app(ChargerCertificates::class)->usable($p)->id);
        config(['csms.certificate_denied_issuers' => [$metadata['issuer_fingerprint']]]);
        $this->getJson($base.'/certificates')->assertOk()->assertJsonPath('data.0.state', 'requires_review');
        $this->postJson($base.'/credential', ['expected_version' => 0, 'days' => 1])->assertStatus(503);
    }
}
