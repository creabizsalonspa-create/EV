<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\CsmsGraphqlTransport;
use App\Services\Charging\FaultMonitor;
use App\Services\Charging\FirmwareCompatibility;
use App\Services\Charging\MaintenanceActor;
use App\Services\Charging\OperationsCredentials;
use App\Services\Charging\RemoteSessionAuthorization;
use App\Services\Charging\SignedFirmwareCatalog;
use App\Services\Charging\SourceStatusTime;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Signed artifacts only. Transport and source reports never prove physical acceptance. */
class ChargerFirmwareController extends Controller
{
    private function enabled(): void
    {
        abort_unless(config('csms.firmware_enabled') && config('csms.session_bridge_enabled') && app()->environment(['local', 'testing']), 503, 'Local signed firmware adapter is disabled.');
    }

    /** Capture one local firmware authority across all reads and the dispatch boundary. */
    private function sourceAuthority(?array $expected = null): array
    {
        $this->enabled();
        $authority = [
            app(CsmsGraphqlTransport::class)->requireApproved(),
            (string) config('csms.graphql_secret'),
            (string) config('csms.operations_base_url'),
            (string) config('csms.rider_source_api_url'),
            (string) config('csms.rider_client_secret'),
            (string) config('csms.firmware_download_base'),
        ];
        abort_unless(strlen($authority[1]) >= 32 && ($expected === null || $authority === $expected), 503,
            'Firmware source authority changed during inspection.');

        return $authority;
    }

    public function releases(Request $r, VendorCompany $company, SignedFirmwareCatalog $catalog)
    {
        app(MaintenanceActor::class)->check($r->user());
        app(MaintenanceActor::class)->checkBearer($r->user());
        app(VendorAccess::class)->require($r->user(), $company, 'inventory.read');
        $filters = $r->validate(['manufacturer' => 'sometimes|string|max:100', 'model' => 'sometimes|string|max:100', 'active' => 'sometimes|in:true,false']);
        $page = DB::table('charger_firmware_releases')->where('company_id', $company->id)->when(isset($filters['manufacturer']), fn ($q) => $q->where('manufacturer', $filters['manufacturer']))->when(isset($filters['model']), fn ($q) => $q->where('model', $filters['model']))->when(($filters['active'] ?? null) === 'true', fn ($q) => $q->whereNull('revoked_at'))->select('id', 'catalog_key', 'version', 'manufacturer', 'model', 'sha256', 'size', 'signer_fingerprint', 'revoked_at', 'created_at', 'compatibility_protocols', 'compatibility_from_versions', 'compatibility_manifest_sha256')->orderByDesc('id')->paginate(25)->through(function ($row) {
            $row->compatibility_policy = $row->compatibility_manifest_sha256 === null ? null : ['protocols' => json_decode($row->compatibility_protocols, true), 'from_versions' => json_decode($row->compatibility_from_versions, true), 'manifest_sha256' => $row->compatibility_manifest_sha256];
            unset($row->compatibility_protocols, $row->compatibility_from_versions, $row->compatibility_manifest_sha256);

            return $row;
        })->toArray();
        $candidates = [];
        if ($r->user()->role === 'admin') {
            foreach ($catalog->entries() as $entry) {
                if (is_array($entry)) {
                    $candidates[] = array_intersect_key($entry, array_flip(['key', 'version', 'manufacturer', 'model'])) + ['compatibility_declared' => array_key_exists('compatibility', $entry)];
                }
            }
        }

        return response()->json($page + ['can_publish' => $r->user()->role === 'admin', 'configured_candidates' => $candidates, 'adapter_enabled' => (bool) config('csms.firmware_enabled')])->header('Cache-Control', 'private, no-store');
    }

    public function approve(Request $r, VendorCompany $company, SignedFirmwareCatalog $catalog)
    {
        $this->enabled();
        app(MaintenanceActor::class)->check($r->user());
        app(MaintenanceActor::class)->checkBearer($r->user());
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['catalog_key' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/D'], 'note' => 'required|string|min:10|max:2000']);
        $release = $catalog->verify($v['catalog_key']);

        return DB::transaction(function () use ($r, $company, $release, $v) {
            VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            app(MaintenanceActor::class)->check($r->user());
            app(MaintenanceActor::class)->checkBearer($r->user());
            $old = DB::table('charger_firmware_releases')->where('company_id', $company->id)->where('catalog_key', $v['catalog_key'])->first();
            if ($old) {
                abort_unless($old->sha256 === $release['sha256'] && $old->signer_fingerprint === $release['signer_fingerprint'] && $old->version === $release['version'] && $old->manufacturer === $release['manufacturer'] && $old->model === $release['model'] && $old->compatibility_manifest_sha256 === $release['compatibility_manifest_sha256'] && $old->revoked_at === null, 409, 'Configured release identity changed or was revoked.');

                return response()->json(['id' => $old->id, 'signature_verified' => true]);
            }
            $id = DB::table('charger_firmware_releases')->insertGetId($release + ['company_id' => $company->id, 'approved_by' => $r->user()->id, 'approval_note' => Crypt::encryptString($v['note']), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'firmware.release_approved', 'subject' => (string) $id, 'created_at' => now()]);

            return response()->json(['id' => $id, 'signature_verified' => true], 201);
        });
    }

    public function revoke(Request $r, VendorCompany $company, int $release)
    {
        $this->enabled();
        app(MaintenanceActor::class)->check($r->user());
        app(MaintenanceActor::class)->checkBearer($r->user());
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['note' => 'required|string|min:10|max:2000']);

        return DB::transaction(function () use ($r, $company, $release, $v) {
            VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            app(MaintenanceActor::class)->check($r->user());
            app(MaintenanceActor::class)->checkBearer($r->user());
            $row = DB::table('charger_firmware_releases')->where('company_id', $company->id)->where('id', $release)->lockForUpdate()->first();
            abort_unless($row, 404);
            if (! $row->revoked_at) {
                DB::table('charger_firmware_releases')->where('id', $row->id)->update(['revoked_at' => now(), 'revoked_by' => $r->user()->id, 'revocation_note' => Crypt::encryptString($v['note']), 'updated_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'firmware.release_revoked', 'subject' => (string) $row->id, 'created_at' => now()]);
            }

            return response()->json(['revoked' => true, 'device_commands_sent' => false, 'existing_holds_released' => false]);
        });
    }

    public function operations(Request $r, VendorCompany $company, VendorCharger $charger, ChargerResetController $guard)
    {
        $b = $guard->scope($r, $company, $charger);
        $page = DB::table('charger_firmware_operations as op')->join('charger_firmware_releases as release', 'release.id', '=', 'op.release_id')->where('op.binding_id', $b->id)->select('op.id', 'op.release_id', 'release.version', 'release.sha256', 'release.compatibility_manifest_sha256', 'op.status', 'op.created_at', 'op.reviewed_at', 'op.attempted_at', 'op.pre_dispatch_blocked_at', 'op.pre_dispatch_blocked_code', 'op.download_expires_at')->orderByDesc('op.id')->paginate(25)->through(function ($row) {
            $row->can_cancel = $row->reviewed_at === null && (($row->status === 'requested' && $row->attempted_at === null) || ($row->status === 'not_dispatched' && $row->pre_dispatch_blocked_at !== null));

            return $row;
        })->toArray();

        return response()->json($page + ['can_manage' => $r->user()->role === 'admin' || $company->owner_id === $r->user()->id, 'adapter_enabled' => (bool) config('csms.firmware_enabled'), 'physical_execution_verified' => false])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Source-bound, read-only signed release matrix for one owned charger.
     * It is never permission to dispatch firmware or proof of physical testing.
     */
    public function compatibility(Request $r, VendorCompany $company, VendorCharger $charger, ChargerResetController $guard, RemoteSessionAuthorization $source, FirmwareCompatibility $compatibility)
    {
        $this->enabled();
        $sourceAuthority = [(string) config('csms.graphql_url'), (string) config('csms.graphql_secret')];
        $requireAuthority = function () use ($sourceAuthority): void {
            $this->enabled();
            abort_unless(config('csms.graphql_url') === $sourceAuthority[0]
                && hash_equals($sourceAuthority[1], (string) config('csms.graphql_secret')), 503,
                'Firmware source authority changed during inspection.');
        };
        $binding = $guard->scope($r, $company, $charger);
        $identityQuery = 'query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id isOnline protocol chargePointVendor chargePointModel firmwareVersion latestOcppMessageTimestamp}}';
        $requireAuthority();
        $data = $source->graph($identityQuery, ['name' => $binding->identifier]);
        $requireAuthority();
        $stations = $data['ChargingStations'] ?? null;
        abort_unless(is_array($stations) && array_is_list($stations) && count($stations) === 1 &&
            is_array($stations[0]) && is_int($stations[0]['id'] ?? null) && $stations[0]['id'] > 0 &&
            ($stations[0]['isOnline'] ?? null) === true &&
            is_string($stations[0]['protocol'] ?? null) &&
            is_string($stations[0]['chargePointVendor'] ?? null) &&
            is_string($stations[0]['chargePointModel'] ?? null) &&
            is_string($stations[0]['firmwareVersion'] ?? null) &&
            strlen($stations[0]['firmwareVersion']) > 0 &&
            strlen($stations[0]['firmwareVersion']) <= 80 &&
            is_string($stations[0]['latestOcppMessageTimestamp'] ?? null), 409, 'A current typed source firmware identity is required.');
        $observed = $stations[0];
        // GraphQL's online flag can survive a silent disconnect; firmware
        // eligibility must be based on a strictly parsed recent heartbeat.
        $lastMessage = SourceStatusTime::parse($observed['latestOcppMessageTimestamp']);
        abort_unless($lastMessage->gte(now()->subMinutes(5)) && $lastMessage->lte(now()), 409,
            'Source firmware identity is stale; refresh before planning maintenance.');
        abort_unless(in_array($observed['protocol'], [$binding->protocol, 'ocpp'.$binding->protocol], true) &&
            $observed['chargePointVendor'] === $charger->manufacturer &&
            $observed['chargePointModel'] === $charger->model, 409, 'Source charger identity differs from the owned inventory.');
        // A captured charger model/company is not a current ownership
        // statement. A source read can overlap local model reassignment,
        // owner changes or staff revocation; rehydrate both sides before
        // showing a signed firmware recommendation.
        $currentCompany = VendorCompany::findOrFail($company->id);
        $currentCharger = VendorCharger::findOrFail($charger->id);
        $fresh = $guard->scope($r, $currentCompany, $currentCharger);
        abort_unless($currentCompany->owner_id === $company->owner_id &&
            $currentCharger->company_id === $charger->company_id &&
            $currentCharger->site_id === $charger->site_id &&
            $currentCharger->manufacturer === $charger->manufacturer &&
            $currentCharger->model === $charger->model &&
            $currentCharger->ocpp_protocol === $charger->ocpp_protocol &&
            (int) $fresh->id === (int) $binding->id &&
            $fresh->identifier === $binding->identifier && $fresh->protocol === $binding->protocol,
            409, 'Charger owner, model or source binding changed during inspection.');

        // A source can disconnect, change firmware, or move between the
        // first read and the private compatibility response. A second,
        // independent authenticated observation must preserve its exact
        // identity and monotonically recent heartbeat; a previous online
        // flag is not proof of continuing firmware eligibility.
        $requireAuthority();
        $reviewRows = $source->graph($identityQuery, [
            'name' => $binding->identifier,
        ])['ChargingStations'] ?? null;
        $requireAuthority();
        abort_unless(is_array($reviewRows) && array_is_list($reviewRows) &&
            count($reviewRows) === 1 && is_array($reviewRows[0]) &&
            is_int($reviewRows[0]['id'] ?? null) &&
            $reviewRows[0]['id'] === $observed['id'] &&
            ($reviewRows[0]['isOnline'] ?? null) === true &&
            ($reviewRows[0]['protocol'] ?? null) === $observed['protocol'] &&
            ($reviewRows[0]['chargePointVendor'] ?? null) === $observed['chargePointVendor'] &&
            ($reviewRows[0]['chargePointModel'] ?? null) === $observed['chargePointModel'] &&
            ($reviewRows[0]['firmwareVersion'] ?? null) === $observed['firmwareVersion'] &&
            is_string($reviewRows[0]['latestOcppMessageTimestamp'] ?? null), 409,
            'Authenticated source firmware identity changed during inspection.');
        $followupTime = SourceStatusTime::parse($reviewRows[0]['latestOcppMessageTimestamp']);
        abort_unless($followupTime->gte($lastMessage) &&
            $followupTime->gte(now()->subMinutes(5)) && $followupTime->lte(now()), 409,
            'Firmware source heartbeat changed during inspection.');
        $finalCompany = VendorCompany::findOrFail($company->id);
        $finalCharger = VendorCharger::findOrFail($charger->id);
        $finalBinding = $guard->scope($r, $finalCompany, $finalCharger);
        abort_unless($finalCompany->owner_id === $company->owner_id &&
            $finalCharger->manufacturer === $charger->manufacturer &&
            $finalCharger->model === $charger->model &&
            $finalCharger->site_id === $charger->site_id &&
            (int) $finalBinding->id === (int) $binding->id &&
            $finalBinding->identifier === $binding->identifier, 409,
            'Owned firmware source changed after source recheck.');

        $matrix = [];
        $releases = DB::table('charger_firmware_releases')
            ->where('company_id', $company->id)
            ->where('manufacturer', $charger->manufacturer)
            ->where('model', $charger->model)
            ->orderByDesc('id')->limit(101)->get();
        // Never label a truncated catalog as a complete compatibility matrix.
        abort_if($releases->count() > 100, 409, 'Firmware matrix exceeds the bounded inspection limit; narrow the approved catalog before reviewing.');
        foreach ($releases as $release) {
            // Validate each stored manifest signature against its approved
            // identity. Badly modified evidence blocks the entire projection.
            $policy = $compatibility->approved($release);
            $active = $release->revoked_at === null;
            // A previously approved signed policy is not a current
            // recommendation when its signer was unpinned or its certificate
            // expired after approval. Firmware dispatch separately checks
            // private artifact bytes and transport authority.
            $certificate = openssl_x509_parse($release->signing_certificate);
            $fingerprint = openssl_x509_fingerprint($release->signing_certificate, 'sha256');
            $pins = array_map('strtolower', config('csms.firmware_signer_pins', []));
            $signerTrusted = is_array($certificate) && is_string($fingerprint) &&
                hash_equals((string) $release->signer_fingerprint, $fingerprint) &&
                in_array(strtolower($fingerprint), $pins, true) &&
                ($certificate['validFrom_time_t'] ?? PHP_INT_MAX) <= time() &&
                ($certificate['validTo_time_t'] ?? 0) > time();
            $matching = $active && $signerTrusted && $policy !== null &&
                in_array($binding->protocol, $policy['protocols'], true) &&
                in_array($observed['firmwareVersion'], $policy['from_versions'], true);
            $matrix[] = [
                'release_id' => $release->id,
                'version' => $release->version,
                'sha256' => $release->sha256,
                'revoked' => $release->revoked_at !== null,
                'signed_compatibility_policy' => $policy !== null,
                'signer_currently_trusted' => $signerTrusted,
                'protocols' => $policy['protocols'] ?? [],
                'from_versions' => $policy['from_versions'] ?? [],
                'matches_observed_source' => $matching,
            ];
        }

        $requireAuthority();

        return response()->json([
            'charger_id' => $charger->id,
            'protocol' => $binding->protocol,
            'manufacturer' => $charger->manufacturer,
            'model' => $charger->model,
            'observed_source_firmware' => $observed['firmwareVersion'],
            'source_last_message_at' => $followupTime->toISOString(),
            'source_freshness_seconds' => 300,
            'source_snapshot_verified' => true,
            'physical_hardware_accepted' => false,
            'firmware_dispatch_authorized' => false,
            'releases' => $matrix,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function sourceCapability(object $binding): void
    {
        $this->enabled();
        $endpoint = (string) config('csms.rider_source_api_url');
        $secret = (string) config('csms.rider_client_secret');
        $graphql = (string) config('csms.graphql_url');
        $sourceKey = (string) config('csms.graphql_secret');
        abort_unless(strlen($secret) >= 32 && strlen($sourceKey) >= 32, 503, 'Supported signed firmware source is not configured.');
        $nonce = (string) Str::uuid();
        $reply = Http::timeout(8)->withoutRedirecting()->get(rtrim($endpoint, '/').'/creabiz/rider-auth/capabilities', ['nonce' => $nonce]);
        $this->enabled();
        abort_unless(config('csms.rider_source_api_url') === $endpoint
            && hash_equals($secret, (string) config('csms.rider_client_secret'))
            && config('csms.graphql_url') === $graphql
            && hash_equals($sourceKey, (string) config('csms.graphql_secret')), 503,
            'Firmware capability authority changed during inspection.');
        $proof = $reply->json('firmware_proof');
        $protocols = $reply->json('signed_firmware_protocols');
        abort_unless($reply->successful() && $reply->json('ready') === true && $reply->json('tenant_id') === 1
            && is_array($protocols) && array_is_list($protocols) && count($protocols) <= 2
            && count(array_filter($protocols, fn ($protocol) => in_array($protocol, ['1.6', '2.0.1'], true))) === count($protocols)
            && count(array_unique($protocols)) === count($protocols)
            && in_array($binding->protocol, $protocols, true) && is_string($proof)
            && hash_equals(hash_hmac('sha256', "creabiz-firmware-capability-v2\n".$nonce."\ntrue\n1\n".implode(',', $protocols), $secret), $proof), 503,
            'Supported signed firmware source is not verified.');
    }

    private function compatibleSource(object $binding, VendorCharger $charger, object $release): array
    {
        $data = app(RemoteSessionAuthorization::class)->graph('query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id chargePointVendor chargePointModel firmwareVersion protocol}}', ['name' => $binding->identifier]);
        $rows = $data['ChargingStations'] ?? null;
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) === 1 &&
            is_array($rows[0]) && is_int($rows[0]['id'] ?? null) && $rows[0]['id'] > 0 &&
            is_string($rows[0]['chargePointVendor'] ?? null) &&
            is_string($rows[0]['chargePointModel'] ?? null) &&
            is_string($rows[0]['firmwareVersion'] ?? null) &&
            is_string($rows[0]['protocol'] ?? null), 409, 'Complete typed source firmware identity is required.');
        abort_unless($rows[0]['chargePointVendor'] === $charger->manufacturer &&
            $rows[0]['chargePointModel'] === $charger->model &&
            in_array($rows[0]['protocol'], [$binding->protocol, 'ocpp'.$binding->protocol], true),
            409, 'Source model, protocol or manufacturer changed.');
        app(FirmwareCompatibility::class)->source($release, $binding->protocol, $rows[0]);

        return [$rows[0]['id'], $rows[0]['firmwareVersion']];
    }

    private function bytes(object $release): string
    {
        $path = Storage::disk('local')->path('firmware/releases/'.$release->sha256.'.bin');
        abort_unless(is_file($path) && filesize($path) === $release->size && hash_equals($release->sha256, hash_file('sha256', $path)), 409, 'Approved firmware bytes changed.');

        $parsed = openssl_x509_parse($release->signing_certificate);
        $pins = array_map('strtolower', config('csms.firmware_signer_pins', []));
        $signature = base64_decode($release->signature, true);
        abort_unless(is_array($parsed) && in_array($release->signer_fingerprint, $pins, true) && openssl_x509_fingerprint($release->signing_certificate, 'sha256') === $release->signer_fingerprint && ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) <= time() && ($parsed['validTo_time_t'] ?? 0) > time() && $signature !== false && openssl_verify(file_get_contents($path), $signature, $release->signing_certificate, OPENSSL_ALGO_SHA256) === 1, 409, 'Approved signer or firmware signature is no longer valid.');

        app(FirmwareCompatibility::class)->approved($release);

        return $path;
    }

    private function payload(object $release, int $id, string $token): array
    {
        $base = rtrim(config('csms.firmware_download_base'), '/');
        $url = parse_url($base);
        abort_unless(is_array($url) && in_array($url['scheme'] ?? null, ['http', 'https'], true) && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment']) && ($url['scheme'] === 'https' || in_array($url['host'] ?? null, ['127.0.0.1', 'localhost'], true)), 503, 'A trusted HTTPS or loopback artifact base is required.');
        $location = $base.'/api/internal/firmware/'.$token;
        abort_unless(strlen($location) <= 512, 503);

        return ['requestId' => $id, 'retries' => 1, 'retryInterval' => 0, 'firmware' => ['location' => $location, 'retrieveDateTime' => now()->toISOString(), 'signingCertificate' => $release->signing_certificate, 'signature' => $release->signature]];
    }

    private function requireCurrentDeployment(Request $r, VendorCompany $company, VendorCharger $charger, object $binding, object $release, ChargerResetController $guard): void
    {
        $guard->requireCurrentScope($r, $company, $charger, $binding);
        $fresh = VendorCharger::whereKey($charger->id)->lockForUpdate()->firstOrFail();
        abort_unless($fresh->manufacturer === $release->manufacturer && $fresh->model === $release->model, 409, 'Approved charger model changed.');
        $current = DB::table('charger_firmware_releases')->where('id', $release->id)->where('company_id', $company->id)->whereNull('revoked_at')->lockForUpdate()->first();
        abort_unless($current && $current == $release, 409, 'Approved firmware release changed.');
        $this->bytes($current);
        $guard->requireLocalIdle($binding);
    }

    public function deploy(Request $r, VendorCompany $company, VendorCharger $charger, ChargerResetController $guard)
    {
        $this->enabled();
        $b = $guard->scope($r, $company, $charger, true);
        $v = $r->validate(['release_id' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:2000', 'idempotency_key' => 'required|string|max:100']);
        $hash = hash('sha256', json_encode([$b->id, $v['release_id'], $v['reason']], JSON_THROW_ON_ERROR));
        $created = DB::transaction(function () use ($r, $company, $charger, $guard, $b, $v, $hash) {
            Station::whereKey($b->station_id)->lockForUpdate()->firstOrFail();
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $guard->scope($r, $company, $charger, true);
            app(VendorAccess::class)->operational($company);
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $old = DB::table('charger_firmware_operations')->where('actor_id', $r->user()->id)->where('idempotency_key', $v['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_hash, $hash), 409, 'Request identity payload mismatch.');

                return ['row' => $old, 'created' => false];
            }
            $release = DB::table('charger_firmware_releases')->where('company_id', $company->id)->where('id', $v['release_id'])->whereNull('revoked_at')->first();
            abort_unless($release && $release->manufacturer === $charger->manufacturer && $release->model === $charger->model, 409, 'An approved compatible release is required.');
            $this->bytes($release);
            abort_if(DB::table('charger_firmware_operations')->where('binding_id', $b->id)->whereNull('reviewed_at')->exists() || DB::table('charger_reset_operations')->where('binding_id', $b->id)->whereNull('reviewed_at')->exists(), 409, 'Review the existing maintenance operation first.');
            $authority = $this->sourceAuthority();
            $sourceStation = $guard->idle($b);
            $this->sourceAuthority($authority);
            $sourceIdentity = $this->compatibleSource($b, $charger, $release);
            abort_unless($sourceIdentity[0] === $sourceStation, 409, 'Firmware source station changed during admission.');
            $this->sourceAuthority($authority);
            $this->sourceCapability($b);
            $this->sourceAuthority($authority);
            $this->requireCurrentDeployment($r, $company, $charger, $b, $release, $guard);
            $raw = bin2hex(random_bytes(32));
            $id = DB::table('charger_firmware_operations')->insertGetId(['binding_id' => $b->id, 'source_generation' => FaultMonitor::generation($b), 'release_id' => $release->id, 'actor_id' => $r->user()->id, 'idempotency_key' => $v['idempotency_key'], 'payload_hash' => $hash, 'reason' => Crypt::encryptString($v['reason']), 'status' => 'requested', 'download_token_digest' => hash('sha256', $raw), 'download_token_ciphertext' => Crypt::encryptString($raw), 'download_expires_at' => now()->addHours(2), 'created_at' => now(), 'updated_at' => now()]);
            abort_unless($id <= 2147483647, 409, 'Firmware request range exhausted.');
            // Commit exact source provenance with the request before any delivery.
            // A process crash after send must not roll this capsule back.
            $serialized = json_encode($this->payload($release, (int) $id, $raw), JSON_THROW_ON_ERROR);
            DB::table('charger_firmware_operations')->where('id', $id)->update(['source_payload_digest' => hash('sha256', $serialized), 'source_payload_ciphertext' => Crypt::encryptString($serialized)]);

            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'firmware.deployment_requested', 'subject' => (string) $id, 'created_at' => now()]);

            return ['row' => DB::table('charger_firmware_operations')->find($id), 'created' => true, 'authority' => $authority, 'source_identity' => $sourceIdentity];
        });
        $row = $created['row'];
        if ($created['created'] && DB::table('charger_firmware_operations')->where('id', $row->id)->where('status', 'requested')->update(['status' => 'dispatching', 'attempted_at' => now(), 'updated_at' => now()])) {
            $state = 'outcome_unknown';
            $wireAttempted = false;
            $blockedAt = null;
            try {
                $state = DB::transaction(function () use ($r, $company, $charger, $guard, $b, $row, $created, &$wireAttempted) {
                    Station::whereKey($b->station_id)->lockForUpdate()->firstOrFail();
                    $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
                    $guard->scope($r, $company, $charger, true);
                    app(VendorAccess::class)->operational($company);
                    $this->enabled();
                    $current = DB::table('charger_firmware_operations')->where('id', $row->id)->lockForUpdate()->firstOrFail();
                    abort_unless($current->status === 'dispatching' && $current->reviewed_at === null, 409);
                    $guard->requireCurrentScope($r, $company, $charger, $b);
                    $guard->requireOperationGeneration($current, $b);
                    $release = DB::table('charger_firmware_releases')->where('id', $row->release_id)->whereNull('revoked_at')->lockForUpdate()->firstOrFail();
                    $this->bytes($release);
                    $authority = $this->sourceAuthority($created['authority']);
                    $sourceStation = $guard->idle($b);
                    $this->sourceAuthority($authority);
                    abort_unless($sourceStation === $created['source_identity'][0]
                        && $this->compatibleSource($b, $charger, $release) === $created['source_identity'], 409,
                        'Firmware source station or version changed after admission.');
                    $this->sourceAuthority($authority);
                    $this->sourceCapability($b);
                    $this->sourceAuthority($authority);
                    $serialized = Crypt::decryptString($current->source_payload_ciphertext);
                    abort_unless(hash_equals($current->source_payload_digest, hash('sha256', $serialized)), 409, 'Firmware source provenance changed.');
                    $payload = json_decode($serialized, true, 32, JSON_THROW_ON_ERROR);
                    abort_unless(($payload['requestId'] ?? null) === (int) $current->id && ($payload['firmware']['signature'] ?? null) === $release->signature && ($payload['firmware']['signingCertificate'] ?? null) === $release->signing_certificate, 409, 'Approved release lineage changed.');
                    $operationsBase = $authority[2];
                    $credential = app(OperationsCredentials::class)->tokenForDispatch($operationsBase);
                    $this->sourceAuthority($authority);
                    $this->requireCurrentDeployment($r, $company, $charger, $b, $release, $guard);
                    // Token acquisition may overlap a source reconnect, firmware
                    // change, new charging activity or capability withdrawal.
                    $this->sourceCapability($b);
                    $this->sourceAuthority($authority);
                    abort_unless($this->compatibleSource($b, $charger, $release) === $created['source_identity'], 409,
                        'Firmware source station or version changed after authentication.');
                    $this->sourceAuthority($authority);
                    abort_unless($guard->idle($b) === $created['source_identity'][0], 409,
                        'Firmware source station changed during final idle review.');
                    $this->sourceAuthority($authority);
                    $this->requireCurrentDeployment($r, $company, $charger, $b, $release, $guard);
                    $guard->requireOperationGeneration($current, $b);
                    $token = $credential();
                    $action = $b->protocol === '1.6' ? 'signedUpdateFirmware' : 'updateFirmware';
                    $wireAttempted = true; // From here even an exception is an uncertain command outcome.
                    $reply = Http::timeout(10)->withoutRedirecting()->withToken($token)->post($operationsBase.'/ocpp/'.$b->protocol.'/configuration/'.$action.'?'.http_build_query(['identifier' => $b->identifier, 'tenantId' => 1]), $payload);

                    if (! $reply->successful() || $reply->json() !== [['success' => true]]) {
                        $message = $reply->json('message');
                        $field = is_string($message) ? explode(' ', $message, 2)[0] : '';
                        Log::warning('firmware.transport_requires_review', ['operation_id' => $row->id, 'http_status' => $reply->status(), 'schema_field' => preg_match('/\Abody(?:\/[-A-Za-z0-9_]+)*\z/D', $field) ? $field : null, 'response_keys' => is_array($reply->json()) ? array_keys($reply->json()) : []]);
                    }

                    return $reply->successful() && $reply->json() === [['success' => true]] ? 'transport_submitted' : 'outcome_unknown';
                });
            } catch (Throwable $error) {
                Log::warning('firmware.dispatch_requires_review', ['operation_id' => $row->id, 'exception_class' => $error::class]);
                if (! $wireAttempted) {
                    $state = 'not_dispatched';
                    $blockedAt = now();
                }
            }
            $updates = ['status' => $state, 'updated_at' => now()];
            if ($blockedAt !== null) {
                $updates += ['pre_dispatch_blocked_at' => $blockedAt, 'pre_dispatch_blocked_code' => 'pre_dispatch_checks_failed', 'download_token_digest' => hash('sha256', random_bytes(32)), 'download_expires_at' => now()];
            }
            DB::table('charger_firmware_operations')->where('id', $row->id)->where('status', 'dispatching')->whereNull('reviewed_at')->update($updates);
        }

        return response()->json(['id' => $row->id, 'status' => DB::table('charger_firmware_operations')->where('id', $row->id)->value('status'), 'physical_execution_verified' => false, 'capacity_released' => false], $created['created'] ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $r, VendorCompany $company, VendorCharger $charger, int $operation, ChargerResetController $guard)
    {
        $this->enabled();
        $b = $guard->scope($r, $company, $charger, true);
        $v = $r->validate(['note' => 'required|string|min:10|max:2000']);

        return DB::transaction(function () use ($r, $company, $charger, $operation, $guard, $b, $v) {
            Station::whereKey($b->station_id)->lockForUpdate()->firstOrFail();
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $guard->scope($r, $company, $charger, true);
            $op = DB::table('charger_firmware_operations')->where('id', $operation)->where('binding_id', $b->id)->lockForUpdate()->first();
            abort_unless($op, 404);
            if ($op->status !== 'cancelled_unsent') {
                abort_unless($op->reviewed_at === null && (($op->status === 'requested' && $op->attempted_at === null) || ($op->status === 'not_dispatched' && $op->pre_dispatch_blocked_at !== null)), 409, 'An uncertain or submitted firmware command cannot be cancelled or released.');
                DB::table('charger_firmware_operations')->where('id', $op->id)->update(['status' => 'cancelled_unsent', 'reviewed_at' => now(), 'reviewed_by' => $r->user()->id, 'review_note' => Crypt::encryptString($v['note']), 'updated_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'firmware.cancelled_before_dispatch', 'subject' => (string) $op->id, 'created_at' => now()]);
            }

            return response()->json(['cancelled' => true, 'source_command_sent' => false, 'charging_sessions_closed' => false])->header('Cache-Control', 'private, no-store');
        });
    }

    public function download(Request $r, string $token)
    {
        $this->enabled();
        $op = DB::table('charger_firmware_operations')->where('download_token_digest', hash('sha256', $token))->whereNull('reviewed_at')->where('download_expires_at', '>', now())->whereIn('status', ['dispatching', 'transport_submitted', 'outcome_unknown'])->first();
        abort_unless($op, 404);
        $binding = DB::table('csms_bindings')->where('id', $op->binding_id)->first();
        abort_unless($binding && is_string($op->source_generation ?? null)
            && hash_equals($op->source_generation, FaultMonitor::generation($binding)), 404);
        $release = DB::table('charger_firmware_releases')->where('id', $op->release_id)->whereNull('revoked_at')->first();
        abort_unless($release, 404);
        $path = $this->bytes($release);

        return response()->download($path, $release->sha256.'.bin', ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function review(Request $r, VendorCompany $company, VendorCharger $charger, int $operation, ChargerResetController $guard, RemoteSessionAuthorization $source)
    {
        $this->enabled();
        $b = $guard->scope($r, $company, $charger, true);
        $v = $r->validate(['note' => 'required|string|min:10|max:2000']);

        return DB::transaction(function () use ($r, $company, $charger, $operation, $guard, $source, $b, $v) {
            Station::whereKey($b->station_id)->lockForUpdate()->firstOrFail();
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $guard->scope($r, $company, $charger, true);
            app(VendorAccess::class)->operational($company);
            $op = DB::table('charger_firmware_operations')->where('id', $operation)->where('binding_id', $b->id)->lockForUpdate()->first();
            abort_unless($op, 404);
            if (! $op->reviewed_at) {
                $guard->requireCurrentScope($r, $company, $charger, $b);
                $guard->requireOperationGeneration($op, $b);
                abort_unless(in_array($op->status, ['transport_submitted', 'outcome_unknown', 'dispatching'], true) && $op->attempted_at && ($op->status !== 'dispatching' || now()->gte(CarbonImmutable::parse($op->attempted_at)->addMinute())), 409, 'Firmware source outcome remains uncertain.');
                $authority = $this->sourceAuthority();
                $guard->idle($b, $op->attempted_at);
                $this->sourceAuthority($authority);
                $release = DB::table('charger_firmware_releases')->where('id', $op->release_id)->firstOrFail();
                $stationData = $source->graph('query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id firmwareVersion}}', ['name' => $b->identifier]);
                $this->sourceAuthority($authority);
                $station = $stationData['ChargingStations'] ?? null;
                abort_unless(is_array($station) && array_is_list($station) && count($station) === 1
                    && is_array($station[0]) && is_int($station[0]['id'] ?? null) && $station[0]['id'] > 0
                    && is_string($station[0]['firmwareVersion'] ?? null), 409, 'Typed source firmware station proof is required.');
                abort_unless($op->source_payload_ciphertext && $op->source_payload_digest, 409, 'Owned source request provenance is required.');
                $serialized = Crypt::decryptString($op->source_payload_ciphertext);
                abort_unless(hash_equals($op->source_payload_digest, hash('sha256', $serialized)), 409);
                $payload = json_decode($serialized, true, 32, JSON_THROW_ON_ERROR);
                $action = $b->protocol === '1.6' ? 'SignedUpdateFirmware' : 'UpdateFirmware';
                $requestData = $source->graph('query($station:Int!,$action:String!,$after:timestamptz!,$match:jsonb!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"csms"},type:{_eq:2},action:{_eq:$action},createdAt:{_gte:$after},payload:{_contains:$match}},limit:2){id createdAt}}', ['station' => $station[0]['id'], 'action' => $action, 'after' => CarbonImmutable::parse($op->attempted_at)->toISOString(), 'match' => ['requestId' => (int) $op->id, 'firmware' => ['location' => $payload['firmware']['location'], 'signature' => $payload['firmware']['signature']]]]);
                $this->sourceAuthority($authority);
                $requests = $requestData['OCPPMessages'] ?? null;
                abort_unless(is_array($requests) && array_is_list($requests) && count($requests) === 1
                    && is_array($requests[0]) && is_int($requests[0]['id'] ?? null)
                    && $requests[0]['id'] > 0 && is_string($requests[0]['createdAt'] ?? null),
                    409, 'One typed owned firmware source request is required.');
                $requestAt = SourceStatusTime::parse($requests[0]['createdAt'], 300);
                abort_unless($requestAt->gte(CarbonImmutable::parse($op->attempted_at)), 409, 'Source firmware request predates its operation.');
                $statusAction = $b->protocol === '1.6' ? 'SignedFirmwareStatusNotification' : 'FirmwareStatusNotification';
                $messageData = $source->graph('query($station:Int!,$action:String!,$after:timestamptz!,$match:jsonb!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"cs"},type:{_eq:2},createdAt:{_gte:$after},action:{_eq:$action},payload:{_contains:$match}},order_by:{id:asc},limit:51){id createdAt action payload}}', ['station' => $station[0]['id'], 'action' => $statusAction, 'after' => $requests[0]['createdAt'], 'match' => ['requestId' => (int) $op->id]]);
                $this->sourceAuthority($authority);
                $messages = $messageData['OCPPMessages'] ?? null;
                abort_unless(is_array($messages) && array_is_list($messages) && count($messages) >= 1 && count($messages) <= 50, 409,
                    'A complete bounded correlated firmware status history is required.');
                $lastId = $requests[0]['id'];
                $lastAt = $requestAt;
                $terminal = null;
                $terminalStates = ['Installed', 'DownloadFailed', 'InstallationFailed', 'InvalidSignature', 'InstallVerificationFailed', 'InvalidCertificate'];
                foreach ($messages as $message) {
                    abort_unless(is_array($message) && is_int($message['id'] ?? null)
                        && $message['id'] > $lastId && is_string($message['createdAt'] ?? null)
                        && ($message['action'] ?? null) === $statusAction
                        && is_array($message['payload'] ?? null)
                        && is_int($message['payload']['requestId'] ?? null)
                        && $message['payload']['requestId'] === (int) $op->id
                        && is_string($message['payload']['status'] ?? null), 409,
                        'Typed protocol-specific firmware status provenance is required.');
                    $at = SourceStatusTime::parse($message['createdAt'], 300);
                    abort_unless($at->gte($lastAt), 409, 'Firmware source status chronology requires reconciliation.');
                    $lastId = $message['id'];
                    $lastAt = $at;
                    $status = $message['payload']['status'];
                    if ($terminal !== null) {
                        abort_unless($status === $terminal, 409, 'Conflicting firmware status after terminal evidence requires review.');
                    } elseif (in_array($status, $terminalStates, true)) {
                        $terminal = $status;
                    }
                }
                abort_unless($terminal !== null, 409, 'A correlated terminal firmware report is required.');
                $installed = $terminal === 'Installed';
                if ($installed) {
                    // Re-read after status history; an earlier station snapshot
                    // cannot certify a version which changed during review.
                    $currentStation = $source->graph('query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id firmwareVersion}}', ['name' => $b->identifier])['ChargingStations'] ?? null;
                    $this->sourceAuthority($authority);
                    abort_unless($release->revoked_at === null && ($station[0]['firmwareVersion'] ?? null) === $release->version
                        && is_array($currentStation) && array_is_list($currentStation) && count($currentStation) === 1
                        && is_array($currentStation[0]) && ($currentStation[0]['id'] ?? null) === $station[0]['id']
                        && ($currentStation[0]['firmwareVersion'] ?? null) === $release->version, 409,
                        'Installed version must match the approved release and current source identity.');
                }
                $this->sourceAuthority($authority);
                $guard->requireCurrentScope($r, $company, $charger, $b);
                $guard->requireLocalIdle($b);
                DB::table('charger_firmware_operations')->where('id', $op->id)->update(['status' => $installed ? 'source_installed' : 'source_failed', 'reviewed_by' => $r->user()->id, 'review_note' => Crypt::encryptString($v['note']), 'reviewed_at' => now(), 'updated_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'firmware.source_reviewed', 'subject' => (string) $op->id, 'created_at' => now()]);
            }

            return response()->json(['reviewed' => true, 'physical_execution_verified' => false, 'charging_sessions_closed' => false])->header('Cache-Control', 'private, no-store');
        });
    }
}
