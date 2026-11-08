<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignedFirmwareTest extends TestCase
{
    use RefreshDatabase;

    private ?string $catalogRoot = null;

    protected function tearDown(): void
    {
        if ($this->catalogRoot) {
            File::deleteDirectory($this->catalogRoot);
        }parent::tearDown();
    }

    private function fixture(string $protocol = '1.6'): array
    {
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->catalogRoot = storage_path('framework/testing/signed-'.Str::random(12));
        File::ensureDirectoryExists($this->catalogRoot, 0700);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'SYNTHETIC NOT FOR HARDWARE'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 2);
        openssl_x509_export($certificate, $pem);
        $bytes = 'SYNTHETIC firmware bytes '.Str::random(40);
        openssl_sign($bytes, $signature, $key, OPENSSL_ALGO_SHA256);
        file_put_contents($this->catalogRoot.'/firmware.bin', $bytes);
        file_put_contents($this->catalogRoot.'/signer.pem', $pem);
        $pin = openssl_x509_fingerprint($pem, 'sha256');
        $entry = ['key' => 'synthetic-v1', 'version' => 'sim-fw-v1', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'sha256' => hash('sha256', $bytes), 'file' => 'firmware.bin', 'certificate' => 'signer.pem', 'signature' => base64_encode($signature)];
        file_put_contents($this->catalogRoot.'/catalog.json', json_encode([$entry], JSON_THROW_ON_ERROR));
        config(['csms.firmware_enabled' => true, 'csms.rider_client_secret' => str_repeat('s', 64), 'csms.rider_source_api_url' => 'http://source.test', 'csms.session_bridge_enabled' => true, 'csms.firmware_catalog_root' => $this->catalogRoot, 'csms.firmware_signer_pins' => [$pin], 'csms.graphql_secret' => str_repeat('s', 64), 'csms.graphql_url' => 'http://source.test/graph', 'csms.operations_base_url' => 'http://source.test', 'csms.firmware_download_base' => 'http://127.0.0.1:8000']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Firmware fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic firmware', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'FIRMWARE', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-firmware-unit', 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $releases = '/api/vendor-ops/companies/'.$company->id.'/firmware-releases';
        $path = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/firmware-operations';

        return compact('owner', 'admin', 'station', 'company', 'charger', 'binding', 'releases', 'path', 'entry', 'bytes', 'pin', 'protocol');
    }

    private function source(array $f, bool $unknown = false, bool $loss = false): void
    {
        Http::fake(function ($r) use ($f, $unknown, $loss) {
            if (str_contains($r->url(), '/capabilities')) {
                parse_str(parse_url($r->url(), PHP_URL_QUERY), $query);

                return Http::response(['ready' => true, 'tenant_id' => 1, 'signed_firmware_protocols' => ['1.6', '2.0.1'], 'proof' => hash_hmac('sha256', "creabiz-rider-v1\n".$query['nonce'], str_repeat('s', 64))]);
            }

            if (str_ends_with($r->url(), '/graph')) {
                $q = $r['query'];
                if (str_contains($q, 'OCPPMessages')) {
                    if (str_contains($q, '"csms"')) {
                        return Http::response(['data' => ['OCPPMessages' => $unknown ? [] : [['id' => 41, 'createdAt' => now()->toISOString()]]]]);
                    }

                    return Http::response(['data' => ['OCPPMessages' => [['payload' => ['status' => 'Installed', 'requestId' => 1]]]]]);
                }
                if (str_contains($q, 'ChargingStations')) {
                    return Http::response(['data' => ['ChargingStations' => [['id' => 9, 'protocol' => $f['protocol'], 'isOnline' => true, 'firmwareVersion' => 'sim-fw-v1', 'chargePointVendor' => 'Synthetic', 'chargePointModel' => 'Fixture']]]]);
                }

                return Http::response(['data' => ['Transactions' => [], 'Connectors' => [['id' => 7]], 'StatusNotifications' => [['connectorDatabaseId' => 7, 'connectorStatus' => 'Available', 'timestamp' => now()->toISOString()]]]]);
            }
            if (str_ends_with($r->url(), '/dev/token')) {
                return Http::response(['accessToken' => 'synthetic-source-token']);
            }

            if ($loss) {
                throw new ConnectionException('Synthetic lost delivery');
            }

            return Http::response($unknown ? [] : [['success' => true]], $unknown ? 503 : 200);
        });
    }

    public function test_revoking_approved_signed_release_encrypts_note_is_idempotent_and_never_contacts_hardware(): void
    {
        $f = $this->fixture();
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1',
            'note' => 'Approved trusted synthetic firmware for revocation regression',
        ])->assertCreated()->json('id');
        $path = $f['releases'].'/'.$release;

        $this->actingAs($f['owner'])->deleteJson($path, ['note' => 'Vendor must not revoke a signed release'])->assertForbidden();
        $this->actingAs($f['admin'])->deleteJson($path, ['note' => 'short'])->assertUnprocessable();
        $this->assertDatabaseHas('charger_firmware_releases', ['id' => $release, 'revoked_at' => null]);

        $note = 'Admin revokes the approved synthetic firmware release';
        $this->deleteJson($path, ['note' => $note])->assertOk()
            ->assertJsonPath('revoked', true)
            ->assertJsonPath('device_commands_sent', false)
            ->assertJsonPath('existing_holds_released', false);

        $row = DB::table('charger_firmware_releases')->find($release);
        $this->assertNotNull($row->revoked_at);
        $this->assertSame($f['admin']->id, (int) $row->revoked_by);
        $this->assertNotSame($note, $row->revocation_note);
        $this->assertSame($note, Crypt::decryptString($row->revocation_note));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'firmware.release_revoked')->where('subject', (string) $release)->count());

        $this->deleteJson($path, ['note' => 'Replay must retain the first audited reason'])->assertOk();
        $this->assertSame($row->revocation_note, DB::table('charger_firmware_releases')->find($release)->revocation_note);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'firmware.release_revoked')->where('subject', (string) $release)->count());
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        Http::assertNothingSent();
    }

    public function test_revoked_signed_firmware_download_capability_fails_closed_without_device_action(): void
    {
        $f = $this->fixture();
        $this->source($f);
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1',
            'note' => 'Approve private synthetic artifact for revoked-download regression',
        ])->assertCreated()->json('id');

        $id = $this->actingAs($f['owner'])->postJson($f['path'], [
            'release_id' => $release,
            'reason' => 'Submit simulated source request for capability revocation',
            'idempotency_key' => 'revoked-download-regression',
        ])->assertCreated()->assertJsonPath('status', 'transport_submitted')->json('id');

        $op = DB::table('charger_firmware_operations')->find($id);
        $token = Crypt::decryptString($op->download_token_ciphertext);
        $url = '/api/internal/firmware/'.$token;
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        // The guarded BinaryFileResponse serves the validated local artifact.
        // Check accessibility without assuming an in-memory response body.

        $before = Http::recorded()->count();
        $this->actingAs($f['admin'])->deleteJson($f['releases'].'/'.$release, [
            'note' => 'Revoke synthetic release and immediately invalidate download',
        ])->assertOk()->assertJsonPath('device_commands_sent', false)
            ->assertJsonPath('existing_holds_released', false);
        $this->get($url)->assertNotFound();
        $this->assertDatabaseHas('charger_firmware_releases', ['id' => $release, 'revoked_by' => $f['admin']->id]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'firmware.release_revoked')->where('subject', (string) $release)->count());
        $this->assertSame($before, Http::recorded()->count(), 'Revocation and denial cannot contact the upstream charger/source');
    }

    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_signed_deployment_is_one_shot_private_and_source_reviewed(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->source($f);
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], ['catalog_key' => 'synthetic-v1', 'note' => 'Approved independently trusted synthetic firmware'])->assertCreated()->json('id');
        $body = ['release_id' => $release, 'reason' => 'Signed simulator deployment only', 'idempotency_key' => 'deploy'];
        $id = $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertCreated()->assertJsonPath('status', 'transport_submitted')->assertJsonPath('physical_execution_verified', false)->json('id');
        $this->postJson($f['path'], $body)->assertOk()->assertJsonPath('id', $id);
        Http::assertSentCount(9);
        Http::assertSent(fn ($r) => str_contains($r->url(), $protocol === '1.6' ? '/signedUpdateFirmware?' : '/updateFirmware?') && $r['retries'] === 1 && $r['firmware']['signature'] === $f['entry']['signature']);
        $op = DB::table('charger_firmware_operations')->find($id);
        $raw = Crypt::decryptString($op->download_token_ciphertext);
        $this->get('/api/internal/firmware/'.str_repeat('0', 64))->assertNotFound();
        $this->get('/api/internal/firmware/'.$raw)->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->postJson($f['path'].'/'.$id.'/review', ['note' => 'Reviewed signed source outcome'])->assertOk()->assertJsonPath('charging_sessions_closed', false);
        $this->assertDatabaseHas('charger_firmware_operations', ['id' => $id, 'status' => 'source_installed']);
        $this->get('/api/internal/firmware/'.$raw)->assertNotFound();
        $this->assertStringNotContainsString($raw, $this->getJson($f['path'])->assertOk()->getContent());
        $this->assertStringNotContainsString('Signed simulator deployment', DB::table('charger_firmware_operations')->find($id)->reason);
    }

    public function test_vendor_cannot_approve_and_untrusted_or_changed_bytes_are_rejected(): void
    {
        $f = $this->fixture();
        $body = ['catalog_key' => 'synthetic-v1', 'note' => 'Trust and signature check required'];
        $this->actingAs($f['owner'])->postJson($f['releases'], $body)->assertForbidden();
        config(['csms.firmware_signer_pins' => [str_repeat('0', 64)]]);
        $this->actingAs($f['admin'])->postJson($f['releases'], $body)->assertUnprocessable();
        config(['csms.firmware_signer_pins' => [$f['pin']]]);
        file_put_contents($this->catalogRoot.'/firmware.bin', 'tampered bytes');
        $this->postJson($f['releases'], $body)->assertUnprocessable();
        $this->assertDatabaseCount('charger_firmware_releases', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_delivery_cannot_be_replayed_or_released_without_owned_source_evidence(): void
    {
        $f = $this->fixture();
        $this->source($f, unknown: true);
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], ['catalog_key' => 'synthetic-v1', 'note' => 'Approved synthetic firmware'])->assertCreated()->json('id');
        $body = ['release_id' => $release, 'reason' => 'Preserve unknown firmware outcome', 'idempotency_key' => 'unknown'];
        $id = $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertCreated()->assertJsonPath('status', 'outcome_unknown')->json('id');
        $this->postJson($f['path'], $body)->assertOk();
        Http::assertSentCount(9);
        $this->postJson($f['path'], array_replace($body, ['idempotency_key' => 'new-intent']))->assertConflict();
        $this->postJson($f['path'].'/'.$id.'/review', ['note' => 'Do not fabricate an installation'])->assertConflict();
        $this->assertNull(DB::table('charger_firmware_operations')->find($id)->reviewed_at);
    }

    public function test_delivery_provenance_survives_a_network_transaction_rollback(): void
    {
        $f = $this->fixture();
        $this->source($f, loss: true);
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], ['catalog_key' => 'synthetic-v1', 'note' => 'Approved signed recovery fixture'])->assertCreated()->json('id');
        $body = ['release_id' => $release, 'reason' => 'Keep durable provenance after delivery loss', 'idempotency_key' => 'loss'];
        $id = $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertCreated()->assertJsonPath('status', 'outcome_unknown')->json('id');
        $op = DB::table('charger_firmware_operations')->find($id);
        $this->assertNotNull($op->source_payload_ciphertext);
        $serialized = Crypt::decryptString($op->source_payload_ciphertext);
        $this->assertSame(hash('sha256', $serialized), $op->source_payload_digest);
        $payload = json_decode($serialized, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($id, $payload['requestId']);
        $this->assertSame($f['entry']['signature'], $payload['firmware']['signature']);
        $this->postJson($f['path'], $body)->assertOk()->assertJsonPath('id', $id);
        $this->postJson($f['path'].'/'.$id.'/cancel', ['note' => 'Do not release an attempted command'])->assertConflict();
    }

    public function test_only_provably_unsent_firmware_can_be_cancelled(): void
    {
        $f = $this->fixture();
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], ['catalog_key' => 'synthetic-v1', 'note' => 'Approved unsigned-delivery-free fixture'])->assertCreated()->json('id');
        $raw = bin2hex(random_bytes(32));
        $id = DB::table('charger_firmware_operations')->insertGetId(['binding_id' => $f['binding'], 'release_id' => $release, 'actor_id' => $f['owner']->id, 'idempotency_key' => 'unsent', 'payload_hash' => str_repeat('a', 64), 'reason' => Crypt::encryptString('Unsent fixture intent'), 'status' => 'requested', 'download_token_digest' => hash('sha256', $raw), 'download_token_ciphertext' => Crypt::encryptString($raw), 'download_expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($f['owner'])->postJson($f['path'].'/'.$id.'/cancel', ['note' => 'Cancel the provably unsent intent'])->assertOk()->assertJsonPath('source_command_sent', false);
        $this->postJson($f['path'].'/'.$id.'/cancel', ['note' => 'Cancel the provably unsent intent'])->assertOk();
        $this->assertSame(0, DB::table('charger_firmware_operations')->where('id', $id)->where('status', 'requested')->update(['status' => 'dispatching']));
        $this->get('/api/internal/firmware/'.$raw)->assertNotFound();
        $this->assertDatabaseHas('charger_firmware_operations', ['id' => $id, 'status' => 'cancelled_unsent']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'firmware.cancelled_before_dispatch')->count());
        Http::assertNothingSent();
    }

    public function test_stale_dispatch_can_only_reconcile_correlated_source_outcome(): void
    {
        $f = $this->fixture();
        $this->source($f);
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], ['catalog_key' => 'synthetic-v1', 'note' => 'Approved source recovery fixture'])->assertCreated()->json('id');
        $id = $this->actingAs($f['owner'])->postJson($f['path'], ['release_id' => $release, 'reason' => 'Recover a delivered but uncommitted result', 'idempotency_key' => 'stale'])->assertCreated()->json('id');
        DB::table('charger_firmware_operations')->where('id', $id)->update(['status' => 'dispatching', 'attempted_at' => now()->subSeconds(61)]);
        $this->postJson($f['path'].'/'.$id.'/review', ['note' => 'Reviewed owned terminal source evidence'])->assertOk();
        $this->assertDatabaseHas('charger_firmware_operations', ['id' => $id, 'status' => 'source_installed']);
    }
}
