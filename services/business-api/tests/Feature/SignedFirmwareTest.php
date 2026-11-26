<?php

namespace Tests\Feature;

use App\Http\Controllers\ChargerFirmwareController;
use App\Http\Controllers\ChargerResetController;
use App\Models\ChargerCommand;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\FirmwareCompatibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SignedFirmwareTest extends TestCase
{
    use RefreshDatabase;

    private ?string $catalogRoot = null;

    public function test_captured_admin_request_cannot_revoke_firmware_or_scope_resets_after_login_revocation(): void
    {
        $f = $this->fixture();
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], ['catalog_key' => 'synthetic-v1', 'note' => 'Approved pinned synthetic bytes'])->assertCreated()->json('id');
        DB::table('users')->where('id', $f['admin']->id)->increment('auth_epoch');
        $request = Request::create('/private-maintenance', 'POST', ['note' => 'A stale captured approval note']);
        $request->setUserResolver(fn () => $f['admin']);
        foreach (['firmware', 'reset'] as $operation) {
            try {
                if ($operation === 'firmware') {
                    app(ChargerFirmwareController::class)->revoke($request, $f['company'], $release);
                } else {
                    app(ChargerResetController::class)->scope($request, $f['company'], $f['charger'], true);
                }
                $this->fail('Revoked captured login retained maintenance authority.');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $this->assertNull(DB::table('charger_firmware_releases')->where('id', $release)->value('revoked_at'));
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        $this->assertDatabaseCount('charger_reset_operations', 0);
        Http::assertNothingSent();
    }

    public function test_download_route_rejects_malformed_capabilities_before_entering_the_disabled_adapter(): void
    {
        config(['csms.firmware_enabled' => false]);
        foreach ([str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64), str_repeat('g', 64), '123456', 'not-a-capability'] as $invalid) {
            $this->get('/api/internal/firmware/'.$invalid)->assertNotFound();
        }
        // A correctly shaped capability still reaches the guarded controller;
        // the existing two-protocol deployment test exercises a genuine token.
        $this->get('/api/internal/firmware/'.str_repeat('a', 64))->assertServiceUnavailable();
    }

    protected function tearDown(): void
    {
        if ($this->catalogRoot) {
            File::deleteDirectory($this->catalogRoot);
        }parent::tearDown();
    }

    #[DataProvider('protocols')]
    public function test_signed_compatibility_policy_binds_approval_and_current_source_before_transport(string $protocol): void
    {
        $f = $this->fixture($protocol, ['protocols' => [$protocol], 'from_versions' => ['sim-fw-v1']]);
        $this->source($f);
        $id = $this->actingAs($f['admin'])->postJson($f['releases'], ['catalog_key' => 'synthetic-v1', 'note' => 'Approve independently signed compatibility'])->assertCreated()->json('id');
        $this->getJson($f['releases'])->assertOk()->assertJsonPath('data.0.compatibility_policy.protocols.0', $protocol)->assertJsonPath('data.0.compatibility_policy.from_versions.0', 'sim-fw-v1')->assertJsonMissingPath('data.0.compatibility_signature');
        $this->actingAs($f['owner'])->postJson($f['path'], ['release_id' => $id, 'reason' => 'Apply the signed source compatibility path', 'idempotency_key' => 'signed-policy'])->assertCreated()->assertJsonPath('status', 'transport_submitted')->assertJsonPath('physical_execution_verified', false);
        $this->assertDatabaseCount('charger_firmware_operations', 1);
    }

    #[DataProvider('protocols')]
    public function test_unsigned_metadata_change_cannot_change_a_signed_compatibility_path(string $protocol): void
    {
        $f = $this->fixture($protocol, ['protocols' => [$protocol], 'from_versions' => ['sim-fw-v1']]);
        $changed = $f['entry'];
        $changed['compatibility']['from_versions'] = ['a-different-source-version'];
        file_put_contents($this->catalogRoot.'/catalog.json', json_encode([$changed], JSON_THROW_ON_ERROR));
        $this->actingAs($f['admin'])->postJson($f['releases'], ['catalog_key' => 'synthetic-v1', 'note' => 'Metadata signature must bind compatibility'])->assertUnprocessable();
        $this->assertDatabaseCount('charger_firmware_releases', 0);
        Http::assertNothingSent();
    }

    public static function incompatiblePolicies(): array
    {
        return [['1.6', 'version'], ['2.0.1', 'version'], ['1.6', 'protocol'], ['2.0.1', 'protocol'], ['1.6', 'missing'], ['2.0.1', 'missing']];
    }

    #[DataProvider('incompatiblePolicies')]
    public function test_incompatible_or_unknown_source_never_claims_a_firmware_command(string $protocol, string $failure): void
    {
        $allowed = $failure === 'protocol' ? ($protocol === '1.6' ? '2.0.1' : '1.6') : $protocol;
        $f = $this->fixture($protocol, ['protocols' => [$allowed], 'from_versions' => ['sim-fw-v1']]);
        $this->source($f, firmwareVersion: $failure === 'version' ? 'sim-fw-unknown' : ($failure === 'missing' ? null : 'sim-fw-v1'));
        $id = $this->actingAs($f['admin'])->postJson($f['releases'], ['catalog_key' => 'synthetic-v1', 'note' => 'Approve bounded signed compatibility'])->assertCreated()->json('id');
        $this->actingAs($f['owner'])->postJson($f['path'], ['release_id' => $id, 'reason' => 'Reject incompatible source before command claim', 'idempotency_key' => 'incompatible'])->assertConflict();
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/dev/token') || str_contains($r->url(), '/signedUpdateFirmware?') || str_contains($r->url(), '/updateFirmware?'));
    }

    public static function malformedFirmwareSourceIdentity(): array
    {
        $out = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['missing_collection', 'object_collection', 'null_row',
                'nonstring_firmware', 'nonstring_protocol', 'wrong_manufacturer'] as $kind) {
                $out[$protocol.'_'.$kind] = [$protocol, $kind];
            }
        }

        return $out;
    }

    #[DataProvider('malformedFirmwareSourceIdentity')]
    public function test_source_firmware_identity_requires_typed_station_and_exact_model_before_dispatch(string $protocol, string $kind): void
    {
        $f = $this->fixture($protocol, [
            'protocols' => [$protocol], 'from_versions' => ['sim-fw-v1'],
        ]);
        $this->source($f, malformedSource: $kind);
        $id = $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1',
            'note' => 'Use only signed source-compatible fixture releases',
        ])->assertCreated()->json('id');
        $this->actingAs($f['owner'])->postJson($f['path'], [
            'release_id' => $id,
            'reason' => 'Fail closed on invalid firmware identity evidence',
            'idempotency_key' => 'invalid-identity-'.$kind.'-'.$protocol,
        ])->assertConflict();
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/dev/token')
            || str_contains($r->url(), '/signedUpdateFirmware?')
            || str_contains($r->url(), '/updateFirmware?'));
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_changed_approved_compatibility_rows_fail_verification_before_source_access(): void
    {
        $f = $this->fixture('1.6', ['protocols' => ['1.6'], 'from_versions' => ['sim-fw-v1']]);
        $id = $this->actingAs($f['admin'])->postJson($f['releases'], ['catalog_key' => 'synthetic-v1', 'note' => 'Approve immutable compatibility evidence'])->assertCreated()->json('id');
        DB::table('charger_firmware_releases')->where('id', $id)->update(['compatibility_from_versions' => json_encode(['changed-without-signature'])]);
        $this->actingAs($f['owner'])->postJson($f['path'], ['release_id' => $id, 'reason' => 'Preserve approved policy and artifact integrity', 'idempotency_key' => 'changed-policy'])->assertConflict();
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('protocols')]
    public function test_firmware_drift_after_claim_blocks_wire_and_keeps_the_operation_quarantined(string $protocol): void
    {
        $f = $this->fixture($protocol, ['protocols' => [$protocol], 'from_versions' => ['sim-fw-v1']]);
        $this->source($f, drift: true);
        $id = $this->actingAs($f['admin'])->postJson($f['releases'], ['catalog_key' => 'synthetic-v1', 'note' => 'Approve explicit signed compatibility'])->assertCreated()->json('id');
        $body = ['release_id' => $id, 'reason' => 'Keep uncertain operation held after source drift', 'idempotency_key' => 'source-drift'];
        $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertCreated()->assertJsonPath('status', 'not_dispatched');
        $this->assertDatabaseCount('charger_firmware_operations', 1);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/dev/token') || str_contains($r->url(), '/signedUpdateFirmware?') || str_contains($r->url(), '/updateFirmware?'));
        $before = Http::recorded()->count();
        $this->postJson($f['path'], $body)->assertOk()->assertJsonPath('status', 'not_dispatched');
        $this->assertSame($before, Http::recorded()->count(), 'Exact retry must not re-dispatch after source drift.');
        $operation = DB::table('charger_firmware_operations')->first();
        $this->assertNull($operation->reviewed_at, 'The local hold must remain until explicit cancellation.');
        $this->assertNotNull($operation->download_token_digest);
        $this->get('/api/internal/firmware/'.Crypt::decryptString($operation->download_token_ciphertext))->assertNotFound();
        $this->assertNotNull($operation->pre_dispatch_blocked_at);
        $this->getJson($f['path'])->assertOk()->assertJsonPath('data.0.can_cancel', true);
        $this->postJson($f['path'].'/'.$operation->id.'/cancel', ['note' => 'Reviewed application proof that no firmware command was sent'])->assertOk();
        $this->assertDatabaseHas('charger_firmware_operations', ['id' => $operation->id, 'status' => 'cancelled_unsent']);
        $this->assertNotNull(DB::table('charger_firmware_operations')->where('id', $operation->id)->value('reviewed_at'));
        $this->assertSame($before, Http::recorded()->count(), 'Cancellation must not contact the charger.');
    }

    private function fixture(string $protocol = '1.6', ?array $compatibility = null): array
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
        if ($compatibility !== null) {
            $entry['compatibility'] = $compatibility;
            openssl_sign(app(FirmwareCompatibility::class)->message($entry, $compatibility), $manifestSignature, $key, OPENSSL_ALGO_SHA256);
            $entry['compatibility_signature'] = base64_encode($manifestSignature);
        }
        file_put_contents($this->catalogRoot.'/catalog.json', json_encode([$entry], JSON_THROW_ON_ERROR));
        config(['csms.firmware_enabled' => true, 'csms.rider_client_secret' => str_repeat('s', 64), 'csms.rider_source_api_url' => 'https://source.test', 'csms.session_bridge_enabled' => true, 'csms.firmware_catalog_root' => $this->catalogRoot, 'csms.firmware_signer_pins' => [$pin], 'csms.graphql_secret' => str_repeat('s', 64), 'csms.graphql_url' => 'https://source.test/graph', 'csms.operations_base_url' => 'https://source.test', 'csms.firmware_download_base' => 'http://127.0.0.1:8000']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Firmware fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic firmware', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'FIRMWARE', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-firmware-unit-'.$charger->id, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $releases = '/api/vendor-ops/companies/'.$company->id.'/firmware-releases';
        $path = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/firmware-operations';

        return compact('owner', 'admin', 'station', 'company', 'charger', 'binding', 'releases', 'path', 'entry', 'bytes', 'pin', 'protocol');
    }

    private function source(array $f, bool $unknown = false, bool $loss = false, ?string $statusTime = null, ?string $firmwareVersion = 'sim-fw-v1', bool $drift = false, ?string $malformedSource = null, ?string $malformedReview = null, bool $faultedIdle = false, ?callable $duringToken = null, ?string $capabilityFailure = null): void
    {
        $compatibilityReads = 0;
        Http::fake(function ($r) use ($f, $unknown, $loss, $statusTime, $firmwareVersion, $drift, $malformedSource, $malformedReview, $faultedIdle, $duringToken, $capabilityFailure, &$compatibilityReads) {
            if (str_contains($r->url(), '/capabilities')) {
                parse_str(parse_url($r->url(), PHP_URL_QUERY), $query);

                match ($capabilityFailure) {
                    'endpoint' => config(['csms.rider_source_api_url' => 'https://alternate.example.test']),
                    'key' => config(['csms.rider_client_secret' => str_repeat('t', 64)]),
                    'graphql' => config(['csms.graphql_url' => 'https://alternate.example.test/graph']),
                    'graphql_key' => config(['csms.graphql_secret' => str_repeat('t', 64)]),
                    'disabled' => config(['csms.firmware_enabled' => false]),
                    default => null,
                };
                $protocols = match ($capabilityFailure) {
                    'object' => ['supported' => $f['protocol']],
                    'duplicate' => [$f['protocol'], $f['protocol']],
                    'unknown' => [$f['protocol'], 'unrecognized'],
                    default => ['1.6', '2.0.1'],
                };

                return Http::response(['ready' => true, 'tenant_id' => 1, 'signed_firmware_protocols' => $protocols, 'proof' => hash_hmac('sha256', "creabiz-rider-v1\n".$query['nonce'], str_repeat('s', 64))]);
            }

            if (str_ends_with($r->url(), '/graph')) {
                $q = $r['query'];
                if (str_contains($q, 'OCPPMessages')) {
                    if (str_contains($q, '"csms"')) {
                        $request = ['id' => 41, 'createdAt' => now()->toISOString()];
                        if ($malformedReview === 'bad_request_list') {
                            return Http::response(['data' => ['OCPPMessages' => ['request' => $request]]]);
                        }
                        if ($malformedReview === 'string_request_id') {
                            $request['id'] = '41';
                        }

                        return Http::response(['data' => ['OCPPMessages' => $unknown ? [] : [$request]]]);
                    }
                    // PostgreSQL sequences are not rolled back between
                    // RefreshDatabase transactions. Correlate the fake status
                    // to the real attempted operation instead of assuming
                    // every test gets id=1 as on a freshly reset SQLite DB.
                    $requestId = $r['variables']['match']['requestId'];
                    $status = ['id' => 42, 'createdAt' => now()->toISOString(),
                        'payload' => ['status' => 'Installed', 'requestId' => $requestId]];
                    if ($malformedReview === 'bad_status_list') {
                        return Http::response(['data' => ['OCPPMessages' => ['status' => $status]]]);
                    }
                    if ($malformedReview === 'wrong_status_request') {
                        $status['payload']['requestId'] = $requestId + 1;
                    }
                    if ($malformedReview === 'string_status_request') {
                        $status['payload']['requestId'] = (string) $requestId;
                    }
                    if ($malformedReview === 'missing_status_payload') {
                        unset($status['payload']);
                    }

                    return Http::response(['data' => ['OCPPMessages' => [$status]]]);
                }
                if (str_contains($q, 'ChargingStations')) {
                    $isCompatibility = str_contains($q, 'chargePointVendor');
                    $reportedVersion = $drift && $isCompatibility && ++$compatibilityReads > 1 ? 'changed-source-version' : $firmwareVersion;
                    $row = ['id' => 9, 'protocol' => $f['protocol'], 'isOnline' => true,
                        'firmwareVersion' => $reportedVersion, 'chargePointVendor' => 'Synthetic',
                        'chargePointModel' => 'Fixture',
                        'latestOcppMessageTimestamp' => $malformedSource === 'stale_heartbeat'
                            ? now()->subMinutes(10)->toISOString()
                            : ($malformedSource === 'relative_heartbeat' ? 'now' : now()->toISOString())];
                    if (! $isCompatibility && $malformedReview === 'bad_review_station_list') {
                        return Http::response(['data' => ['ChargingStations' => ['station' => $row]]]);
                    }
                    if (! $isCompatibility && $malformedReview === 'string_review_station_id') {
                        $row['id'] = '9';
                    }
                    if ($isCompatibility && $malformedSource !== null) {
                        if ($malformedSource === 'missing_collection') {
                            return Http::response(['data' => []]);
                        }
                        if ($malformedSource === 'object_collection') {
                            return Http::response(['data' => ['ChargingStations' => ['station' => $row]]]);
                        }
                        if ($malformedSource === 'null_row') {
                            return Http::response(['data' => ['ChargingStations' => [null]]]);
                        }
                        if ($malformedSource === 'nonstring_firmware') {
                            $row['firmwareVersion'] = ['untrusted'];
                        }
                        if ($malformedSource === 'nonstring_protocol') {
                            $row['protocol'] = true;
                        }
                        if ($malformedSource === 'wrong_manufacturer') {
                            $row['chargePointVendor'] = 'Other';
                        }
                    }

                    return Http::response(['data' => ['ChargingStations' => [$row]]]);
                }

                return Http::response(['data' => ['Transactions' => [], 'Connectors' => [['id' => 7]], 'StatusNotifications' => [['connectorDatabaseId' => 7, 'connectorStatus' => 'Available', 'errorCode' => $faultedIdle ? 'GroundFailure' : ($f['protocol'] === '1.6' ? 'NoError' : null), 'timestamp' => $statusTime ?? now()->toISOString()]]]]);
            }
            if (str_ends_with($r->url(), '/dev/token')) {
                if ($duringToken) {
                    $duringToken();
                }

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
    public function test_relative_idle_source_time_cannot_dispatch_or_complete_firmware(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->source($f, statusTime: 'now');
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], ['catalog_key' => 'synthetic-v1', 'note' => 'Approved pinned synthetic bytes'])->assertCreated()->json('id');
        $body = ['release_id' => $release, 'reason' => 'Strict firmware source time validation', 'idempotency_key' => 'strict-time'];
        $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertServiceUnavailable();
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/signedUpdateFirmware?') || str_contains($r->url(), '/updateFirmware?'));
        Http::swap(new Factory);
        $this->source($f);
        $id = $this->postJson($f['path'], $body)->assertCreated()->json('id');
        Http::swap(new Factory);
        $this->source($f, statusTime: 'now');
        $this->postJson($f['path'].'/'.$id.'/review', ['note' => 'Malformed source time cannot confirm installation'])->assertServiceUnavailable();
        $this->assertDatabaseHas('charger_firmware_operations', ['id' => $id, 'reviewed_at' => null, 'status' => 'transport_submitted']);
    }

    #[DataProvider('protocols')]
    public function test_faulted_idle_source_cannot_dispatch_or_complete_firmware(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->source($f, faultedIdle: true);
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1',
            'note' => 'Approved pinned synthetic bytes',
        ])->assertCreated()->json('id');
        $body = ['release_id' => $release, 'reason' => 'Fault-aware firmware source validation', 'idempotency_key' => 'faulted-idle'];
        $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertConflict();
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/signedUpdateFirmware?') || str_contains($r->url(), '/updateFirmware?'));
        Http::swap(new Factory);
        $this->source($f);
        $id = $this->postJson($f['path'], $body)->assertCreated()->json('id');
        Http::swap(new Factory);
        $this->source($f, faultedIdle: true);
        $this->postJson($f['path'].'/'.$id.'/review', ['note' => 'Faulted source cannot confirm installation'])->assertConflict();
        $this->assertDatabaseHas('charger_firmware_operations', ['id' => $id, 'reviewed_at' => null, 'status' => 'transport_submitted']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/signedUpdateFirmware?') || str_contains($r->url(), '/updateFirmware?'));
    }

    #[DataProvider('protocols')]
    public function test_operations_origin_change_after_token_cannot_dispatch_firmware(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $original = config('csms.operations_base_url');
        $this->source($f, duringToken: fn () => config(['csms.operations_base_url' => 'https://other.example.test']));
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1', 'note' => 'Approved pinned synthetic bytes',
        ])->assertCreated()->json('id');
        $body = ['release_id' => $release, 'reason' => 'Inspect token endpoint generation', 'idempotency_key' => 'token-drift'];
        $id = $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertCreated()->assertJsonPath('status', 'not_dispatched')->json('id');
        // Initial idle (2), compatibility (1), capability (1);
        // pre-dispatch idle (2), compatibility (1), token (1).
        Http::assertSentCount(8);
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/dev/token')));
        $this->assertCount(6, Http::recorded(fn ($r) => str_ends_with($r->url(), '/graph')));
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), '/capabilities')));
        config(['csms.operations_base_url' => $original]);
        $this->postJson($f['path'], $body)->assertOk()->assertJsonPath('id', $id);
        $this->assertNotNull(DB::table('charger_firmware_operations')->find($id)->pre_dispatch_blocked_at);
        Http::assertSentCount(8);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/ocpp/') || str_contains($r->url(), 'other.example.test'));
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
        $this->getJson($f['path'])->assertOk()->assertJsonPath('data.0.can_cancel', false);
        $this->postJson($f['path'].'/'.$id.'/cancel', ['note' => 'Do not accept client supplied dispatch proof', 'status' => 'not_dispatched', 'pre_dispatch_blocked_at' => now()->toISOString()])->assertConflict();
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

    #[DataProvider('protocols')]
    public function test_signed_firmware_cannot_be_claimed_with_unresolved_legacy_station_commands(string $protocol): void
    {
        $f = $this->fixture($protocol);
        $this->source($f);
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1',
            'note' => 'Approve independently verified synthetic firmware',
        ])->assertCreated()->json('id');
        $stationId = DB::table('vendor_sites')->where('id', $f['charger']->site_id)->value('station_id');
        $command = ChargerCommand::create([
            'actor_id' => $f['owner']->id, 'station_id' => $stationId,
            'action' => 'start', 'payload' => [],
            'payload_hash' => str_repeat('a', 64), 'idempotency_key' => 'legacy-firmware-hold',
        ]);
        $body = [
            'release_id' => $release, 'reason' => 'Keep firmware idle admission safe',
            'idempotency_key' => 'hold-before-firmware',
        ];
        foreach (['pending', 'dispatching', 'submitted', 'outcome_unknown'] as $status) {
            $command->update(['status' => $status]);
            $this->actingAs($f['owner'])->postJson($f['path'], $body)->assertConflict();
            $this->assertDatabaseCount('charger_firmware_operations', 0);
            $this->assertDatabaseCount('csms_session_links', 0);
            $this->assertSame($status, $command->fresh()->status);
            Http::assertNothingSent();
        }
        $command->update(['status' => 'rejected']);
        $this->postJson($f['path'], $body)->assertCreated()
            ->assertJsonPath('status', 'transport_submitted')
            ->assertJsonPath('physical_execution_verified', false);
        $this->assertDatabaseCount('charger_firmware_operations', 1);
        $this->assertSame('rejected', $command->fresh()->status);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function malformedFirmwareReviewEvidence(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['bad_review_station_list', 'string_review_station_id',
                'bad_request_list', 'string_request_id', 'bad_status_list',
                'wrong_status_request', 'string_status_request', 'missing_status_payload'] as $kind) {
                $cases[$protocol.'_'.$kind] = [$protocol, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('malformedFirmwareReviewEvidence')]
    public function test_malformed_source_firmware_review_cannot_fabricate_installation(string $protocol, string $kind): void
    {
        $f = $this->fixture($protocol);
        $this->source($f);
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1',
            'note' => 'Approve verified signed software fixture for review provenance',
        ])->assertCreated()->json('id');
        $id = $this->actingAs($f['owner'])->postJson($f['path'], [
            'release_id' => $release, 'reason' => 'Check source review evidence type and identity',
            'idempotency_key' => 'review-source-types',
        ])->assertCreated()->assertJsonPath('status', 'transport_submitted')->json('id');
        Http::swap(new Factory);
        $this->source($f, malformedReview: $kind);
        $this->postJson($f['path'].'/'.$id.'/review', [
            'note' => 'This malformed source response must not certify installation',
        ])->assertConflict();
        $this->assertDatabaseHas('charger_firmware_operations', [
            'id' => $id, 'status' => 'transport_submitted', 'reviewed_at' => null,
        ]);
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'firmware.source_reviewed')->where('subject', (string) $id)->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/signedUpdateFirmware?')
            || str_contains($r->url(), '/updateFirmware?'));
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
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
        $this->getJson($f['path'])->assertOk()->assertJsonPath('data.0.can_cancel', false);
        $this->postJson($f['path'].'/'.$id.'/cancel', ['note' => 'Reject fabricated client dispatch proof', 'status' => 'not_dispatched', 'pre_dispatch_blocked_at' => now()->toISOString()])->assertConflict();
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

    public function test_read_only_signed_firmware_matrix_matches_verified_source_without_authorizing_dispatch(): void
    {
        foreach (['1.6', '2.0.1'] as $protocol) {
            // Each protocol needs an independent authenticated source fake.
            // Laravel's earlier HTTP stub would otherwise shadow the next
            // protocol's charger identity and produce a false 409.
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $f = $this->fixture($protocol, [
                'protocols' => ['1.6', '2.0.1'],
                'from_versions' => ['sim-fw-v1'],
            ]);
            $this->source($f);
            $release = $this->actingAs($f['admin'])->postJson($f['releases'], [
                'catalog_key' => 'synthetic-v1',
                'note' => 'Approve signed source compatibility matrix fixture',
            ])->assertCreated()->json('id');
            $path = str_replace('/firmware-operations', '/firmware-compatibility', $f['path']);
            $this->actingAs($f['owner'])->getJson($path)->assertOk()
                ->assertJsonPath('protocol', $protocol)
                ->assertJsonPath('observed_source_firmware', 'sim-fw-v1')
                ->assertJsonPath('source_freshness_seconds', 300)
                ->assertJsonPath('source_snapshot_verified', true)
                ->assertJsonPath('physical_hardware_accepted', false)
                ->assertJsonPath('firmware_dispatch_authorized', false)
                ->assertJsonPath('releases.0.release_id', $release)
                ->assertJsonPath('releases.0.signed_compatibility_policy', true)
                ->assertJsonPath('releases.0.signer_currently_trusted', true)
                ->assertJsonPath('releases.0.matches_observed_source', true)
                ->assertJsonPath('releases.0.revoked', false);
            $this->assertSame(2, Http::recorded()->count(), 'Two read-only authenticated station identity checks are required.');
            Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
                || str_contains($request->url(), '/signedUpdateFirmware?')
                || str_contains($request->url(), '/updateFirmware?'));
            $this->actingAs(User::factory()->create(['role' => 'vendor']))
                ->getJson($path)->assertNotFound();
            $this->assertSame(2, Http::recorded()->count(), 'Foreign operators cannot read the source.');
        }
    }

    #[DataProvider('protocols')]
    public function test_oversized_owned_firmware_catalog_is_not_silently_truncated(string $protocol): void
    {
        $f = $this->fixture($protocol, ['protocols' => [$protocol], 'from_versions' => ['sim-fw-v1']]);
        $this->source($f);
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1',
            'note' => 'Approve signed compatibility before bounded catalog inspection',
        ])->assertCreated()->json('id');
        $original = (array) DB::table('charger_firmware_releases')->find($release);
        unset($original['id']);
        $overflow = [];
        // Deliberately synthetic database-only rows. The bounded catalog
        // check must reject the entire projection before evaluating their
        // intentionally nonmatching signed metadata or suggesting a release.
        for ($i = 1; $i <= 100; $i++) {
            $overflow[] = array_replace($original, [
                'catalog_key' => 'overflow-'.$i,
                'version' => 'overflow-'.$i,
                'sha256' => hash('sha256', 'overflow-'.$i),
            ]);
        }
        DB::table('charger_firmware_releases')->insert($overflow);
        $path = str_replace('/firmware-operations', '/firmware-compatibility', $f['path']);
        $response = $this->actingAs($f['owner'])->getJson($path)->assertConflict();
        $response->assertJsonMissingPath('releases')->assertJsonMissingPath('firmware_dispatch_authorized');
        $this->assertSame(2, Http::recorded()->count());
        DB::table('charger_firmware_releases')->where('company_id', $original['company_id'])
            ->where('id', '!=', $release)->delete();
        $this->getJson($path)->assertOk()
            ->assertJsonCount(1, 'releases')
            ->assertJsonPath('releases.0.release_id', $release)
            ->assertJsonPath('releases.0.matches_observed_source', true)
            ->assertJsonPath('firmware_dispatch_authorized', false);
        $this->assertSame(4, Http::recorded()->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
            || str_contains($request->url(), '/ocpp/'));
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function firmwareCapabilityFailures(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['endpoint', 'key', 'graphql', 'graphql_key', 'disabled', 'object', 'duplicate', 'unknown'] as $kind) {
                $cases[] = [$protocol, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('firmwareCapabilityFailures')]
    public function test_firmware_capability_requires_original_authority_and_typed_protocols(string $protocol, string $kind): void
    {
        $f = $this->fixture($protocol, ['protocols' => [$protocol], 'from_versions' => ['sim-fw-v1']]);
        $this->source($f, capabilityFailure: $kind);
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1',
            'note' => 'Approve signed release before capability inspection',
        ])->assertCreated()->json('id');
        $this->actingAs($f['owner'])->postJson($f['path'], [
            'release_id' => $release,
            'reason' => 'Inspect original signed source capability',
            'idempotency_key' => 'capability-failure',
        ])->assertServiceUnavailable();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/capabilities'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
            || str_contains($request->url(), '/ocpp/') || str_contains($request->url(), 'alternate.example.test'));
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function matrixAuthorityChanges(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['endpoint', 'secret', 'disabled'] as $kind) {
                foreach ([1, 2] as $read) {
                    $cases[] = [$protocol, $kind, $read];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('matrixAuthorityChanges')]
    public function test_matrix_requires_same_source_authority_through_both_reads(string $protocol, string $kind, int $changeAt): void
    {
        $f = $this->fixture($protocol, ['protocols' => [$protocol], 'from_versions' => ['sim-fw-v1']]);
        config(['csms.graphql_url' => 'https://source.test/graph']);
        $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1', 'note' => 'Approve signed fixture before source inspection',
        ])->assertCreated();
        $reads = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($protocol, $kind, $changeAt, &$reads) {
            $this->assertSame('https://source.test/graph', $request->url());
            $this->assertSame(str_repeat('s', 64), $request->header('x-hasura-admin-secret')[0] ?? null);
            if (++$reads === $changeAt) {
                match ($kind) {
                    'endpoint' => config(['csms.graphql_url' => 'https://alternate.example.test/graph']),
                    'secret' => config(['csms.graphql_secret' => str_repeat('t', 64)]),
                    'disabled' => config(['csms.firmware_enabled' => false]),
                };
            }

            return Http::response(['data' => ['ChargingStations' => [[
                'id' => 9, 'isOnline' => true, 'protocol' => $protocol,
                'chargePointVendor' => 'Synthetic', 'chargePointModel' => 'Fixture',
                'firmwareVersion' => 'sim-fw-v1', 'latestOcppMessageTimestamp' => now()->toISOString(),
            ]]]]);
        });
        $path = str_replace('/firmware-operations', '/firmware-compatibility', $f['path']);
        $this->actingAs($f['owner'])->getJson($path)->assertServiceUnavailable()
            ->assertJsonMissingPath('releases')->assertJsonMissingPath('source_snapshot_verified');
        Http::assertSentCount($changeAt);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'alternate.example.test')
            || str_contains($request->url(), '/dev/token') || str_contains($request->url(), '/ocpp/'));
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        $this->assertDatabaseCount('charger_reset_operations', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_firmware_matrix_rejects_source_firmware_version_drift_between_authenticated_reads(): void
    {
        $f = $this->fixture('2.0.1', [
            'protocols' => ['2.0.1'],
            'from_versions' => ['sim-fw-v1'],
        ]);
        $this->source($f, drift: true);
        $path = str_replace('/firmware-operations', '/firmware-compatibility', $f['path']);
        $this->actingAs($f['owner'])->getJson($path)->assertConflict()
            ->assertDontSee('changed-source-version');
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
            || str_contains($request->url(), '/signedUpdateFirmware?')
            || str_contains($request->url(), '/updateFirmware?'));
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        $this->assertDatabaseCount('charger_reset_operations', 0);
    }

    public function test_read_only_firmware_matrix_rejects_midflight_owned_charger_model_change(): void
    {
        $f = $this->fixture('2.0.1', [
            'protocols' => ['2.0.1'],
            'from_versions' => ['sim-fw-v1'],
        ]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($f) {
            $this->assertSame('http://source.test/graph', $request->url());
            $this->assertSame(str_repeat('s', 64), $request->header('x-hasura-admin-secret')[0] ?? null);
            DB::table('vendor_chargers')->where('id', $f['charger']->id)
                ->update(['model' => 'Reassigned while source was being read']);

            return Http::response(['data' => ['ChargingStations' => [[
                'id' => 9, 'protocol' => '2.0.1', 'isOnline' => true,
                'chargePointVendor' => 'Synthetic', 'chargePointModel' => 'Fixture',
                'firmwareVersion' => 'sim-fw-v1',
                'latestOcppMessageTimestamp' => now()->toISOString(),
            ]]]]);
        });
        $path = str_replace('/firmware-operations', '/firmware-compatibility', $f['path']);
        $this->actingAs($f['owner'])->getJson($path)->assertConflict()
            ->assertDontSee('Reassigned while source was being read');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        $this->assertDatabaseCount('charger_reset_operations', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_firmware_matrix_denies_wrong_source_and_does_not_recommend_changed_or_revoked_release(): void
    {
        $f = $this->fixture('1.6', [
            'protocols' => ['1.6'],
            'from_versions' => ['sim-fw-v1'],
        ]);
        $this->source($f);
        $release = $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1',
            'note' => 'Approve source-specific signed compatibility policy',
        ])->assertCreated()->json('id');
        $path = str_replace('/firmware-operations', '/firmware-compatibility', $f['path']);
        $this->actingAs($f['owner']);

        Http::swap(new Factory);
        $this->source($f, firmwareVersion: 'unapproved-version');
        $this->getJson($path)->assertOk()
            ->assertJsonPath('releases.0.signed_compatibility_policy', true)
            ->assertJsonPath('releases.0.matches_observed_source', false)
            ->assertJsonPath('firmware_dispatch_authorized', false);

        Http::swap(new Factory);
        $this->source($f, malformedSource: 'wrong_manufacturer');
        $this->getJson($path)->assertConflict();

        Http::swap(new Factory);
        $this->source($f, malformedSource: 'stale_heartbeat');
        $this->getJson($path)->assertConflict();

        Http::swap(new Factory);
        $this->source($f, malformedSource: 'relative_heartbeat');
        $this->getJson($path)->assertServiceUnavailable();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
            || str_contains($request->url(), '/signedUpdateFirmware?')
            || str_contains($request->url(), '/updateFirmware?'));

        $this->actingAs($f['admin'])->deleteJson($f['releases'].'/'.$release, [
            'note' => 'Revoke signed synthetic compatibility after operator review',
        ])->assertOk();
        Http::swap(new Factory);
        $this->source($f);
        $this->actingAs($f['owner'])->getJson($path)->assertOk()
            ->assertJsonPath('releases.0.revoked', true)
            ->assertJsonPath('releases.0.matches_observed_source', false);
    }

    public function test_revoked_signer_pin_does_not_recommend_an_earlier_approved_release(): void
    {
        $f = $this->fixture('2.0.1', [
            'protocols' => ['2.0.1'], 'from_versions' => ['sim-fw-v1'],
        ]);
        $this->source($f);
        $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1',
            'note' => 'Approve signed firmware before trust pin rotation',
        ])->assertCreated();
        $path = str_replace('/firmware-operations', '/firmware-compatibility', $f['path']);
        $this->actingAs($f['owner'])->getJson($path)->assertOk()
            ->assertJsonPath('releases.0.matches_observed_source', true)
            ->assertJsonPath('releases.0.signer_currently_trusted', true);
        config(['csms.firmware_signer_pins' => []]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('releases.0.signed_compatibility_policy', true)
            ->assertJsonPath('releases.0.signer_currently_trusted', false)
            ->assertJsonPath('releases.0.matches_observed_source', false)
            ->assertJsonPath('firmware_dispatch_authorized', false);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
            || str_contains($request->url(), '/signedUpdateFirmware?')
            || str_contains($request->url(), '/updateFirmware?'));
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_unsigned_legacy_firmware_has_no_inferred_source_compatibility(): void
    {
        $f = $this->fixture();
        $this->source($f);
        $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1',
            'note' => 'Approve synthetic release without declared compatibility',
        ])->assertCreated();
        $path = str_replace('/firmware-operations', '/firmware-compatibility', $f['path']);
        $this->actingAs($f['owner'])->getJson($path)->assertOk()
            ->assertJsonPath('releases.0.signed_compatibility_policy', false)
            ->assertJsonPath('releases.0.matches_observed_source', false)
            ->assertJsonPath('physical_hardware_accepted', false);
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_source_compatibility_read_rechecks_suspended_owner_before_private_response(): void
    {
        $f = $this->fixture('1.6', [
            'protocols' => ['1.6'],
            'from_versions' => ['sim-fw-v1'],
        ]);
        $this->actingAs($f['admin'])->postJson($f['releases'], [
            'catalog_key' => 'synthetic-v1',
            'note' => 'Approve a synthetic signed release before account suspension',
        ])->assertCreated();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($f) {
            $this->assertSame('http://source.test/graph', $request->url());
            // Simulate account security changing while the authenticated
            // external CSMS read is in flight, after the first scope check.
            DB::table('users')->where('id', $f['owner']->id)->update(['account_status' => 'suspended']);

            return Http::response(['data' => ['ChargingStations' => [[
                'id' => 9,
                'isOnline' => true,
                'protocol' => '1.6',
                'chargePointVendor' => 'Synthetic',
                'chargePointModel' => 'Fixture',
                'firmwareVersion' => 'sim-fw-v1',
                'latestOcppMessageTimestamp' => now()->toISOString(),
            ]]]]);
        });
        $path = str_replace('/firmware-operations', '/firmware-compatibility', $f['path']);
        $this->actingAs($f['owner'])->getJson($path)->assertForbidden();
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dev/token')
            || str_contains($request->url(), '/signedUpdateFirmware?')
            || str_contains($request->url(), '/updateFirmware?'));
        $this->assertDatabaseCount('charger_firmware_operations', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
