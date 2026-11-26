<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
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

    public function releases(Request $r, VendorCompany $company, SignedFirmwareCatalog $catalog)
    {
        app(MaintenanceActor::class)->check($r->user());
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
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['catalog_key' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/D'], 'note' => 'required|string|min:10|max:2000']);
        $release = $catalog->verify($v['catalog_key']);

        return DB::transaction(function () use ($r, $company, $release, $v) {
            VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            app(MaintenanceActor::class)->check($r->user());
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
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['note' => 'required|string|min:10|max:2000']);

        return DB::transaction(function () use ($r, $company, $release, $v) {
            VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            app(MaintenanceActor::class)->check($r->user());
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

    private function sourceCapability(object $binding): void
    {
        $nonce = (string) Str::uuid();
        $reply = Http::timeout(8)->withoutRedirecting()->get(rtrim(config('csms.rider_source_api_url'), '/').'/creabiz/rider-auth/capabilities', ['nonce' => $nonce]);
        $proof = $reply->json('proof');
        $secret = (string) config('csms.rider_client_secret');
        abort_unless(strlen($secret) >= 32 && $reply->successful() && $reply->json('ready') === true && $reply->json('tenant_id') === 1 && in_array($binding->protocol, $reply->json('signed_firmware_protocols', []), true) && is_string($proof) && hash_equals(hash_hmac('sha256', "creabiz-rider-v1\n".$nonce, $secret), $proof), 503, 'Supported signed firmware source is not verified.');
    }

    private function compatibleSource(object $binding, VendorCharger $charger, object $release): void
    {
        $data = app(RemoteSessionAuthorization::class)->graph('query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){chargePointVendor chargePointModel firmwareVersion protocol}}', ['name' => $binding->identifier]);
        $rows = $data['ChargingStations'] ?? null;
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) === 1 &&
            is_array($rows[0]) &&
            is_string($rows[0]['chargePointVendor'] ?? null) &&
            is_string($rows[0]['chargePointModel'] ?? null) &&
            is_string($rows[0]['firmwareVersion'] ?? null) &&
            is_string($rows[0]['protocol'] ?? null), 409, 'Complete typed source firmware identity is required.');
        abort_unless($rows[0]['chargePointVendor'] === $charger->manufacturer &&
            $rows[0]['chargePointModel'] === $charger->model &&
            in_array($rows[0]['protocol'], [$binding->protocol, 'ocpp'.$binding->protocol], true),
            409, 'Source model, protocol or manufacturer changed.');
        app(FirmwareCompatibility::class)->source($release, $binding->protocol, $rows[0]);
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
            $guard->idle($b);
            $this->compatibleSource($b, $charger, $release);
            $this->sourceCapability($b);
            $raw = bin2hex(random_bytes(32));
            $id = DB::table('charger_firmware_operations')->insertGetId(['binding_id' => $b->id, 'release_id' => $release->id, 'actor_id' => $r->user()->id, 'idempotency_key' => $v['idempotency_key'], 'payload_hash' => $hash, 'reason' => Crypt::encryptString($v['reason']), 'status' => 'requested', 'download_token_digest' => hash('sha256', $raw), 'download_token_ciphertext' => Crypt::encryptString($raw), 'download_expires_at' => now()->addHours(2), 'created_at' => now(), 'updated_at' => now()]);
            abort_unless($id <= 2147483647, 409, 'Firmware request range exhausted.');
            // Commit exact source provenance with the request before any delivery.
            // A process crash after send must not roll this capsule back.
            $serialized = json_encode($this->payload($release, (int) $id, $raw), JSON_THROW_ON_ERROR);
            DB::table('charger_firmware_operations')->where('id', $id)->update(['source_payload_digest' => hash('sha256', $serialized), 'source_payload_ciphertext' => Crypt::encryptString($serialized)]);

            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'firmware.deployment_requested', 'subject' => (string) $id, 'created_at' => now()]);

            return ['row' => DB::table('charger_firmware_operations')->find($id), 'created' => true];
        });
        $row = $created['row'];
        if ($created['created'] && DB::table('charger_firmware_operations')->where('id', $row->id)->where('status', 'requested')->update(['status' => 'dispatching', 'attempted_at' => now(), 'updated_at' => now()])) {
            $state = 'outcome_unknown';
            $wireAttempted = false;
            $blockedAt = null;
            try {
                $state = DB::transaction(function () use ($r, $company, $charger, $guard, $b, $row, &$wireAttempted) {
                    Station::whereKey($b->station_id)->lockForUpdate()->firstOrFail();
                    $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
                    $guard->scope($r, $company, $charger, true);
                    app(VendorAccess::class)->operational($company);
                    $this->enabled();
                    $current = DB::table('charger_firmware_operations')->where('id', $row->id)->lockForUpdate()->firstOrFail();
                    abort_unless($current->status === 'dispatching' && $current->reviewed_at === null, 409);
                    $release = DB::table('charger_firmware_releases')->where('id', $row->release_id)->whereNull('revoked_at')->lockForUpdate()->firstOrFail();
                    $this->bytes($release);
                    $guard->idle($b);
                    $this->compatibleSource($b, $charger, $release);
                    $serialized = Crypt::decryptString($current->source_payload_ciphertext);
                    abort_unless(hash_equals($current->source_payload_digest, hash('sha256', $serialized)), 409, 'Firmware source provenance changed.');
                    $payload = json_decode($serialized, true, 32, JSON_THROW_ON_ERROR);
                    abort_unless(($payload['requestId'] ?? null) === (int) $current->id && ($payload['firmware']['signature'] ?? null) === $release->signature && ($payload['firmware']['signingCertificate'] ?? null) === $release->signing_certificate, 409, 'Approved release lineage changed.');
                    $operationsBase = config('csms.operations_base_url');
                    $token = app(OperationsCredentials::class)->token($operationsBase);
                    $this->enabled();
                    abort_unless(config('csms.operations_base_url') === $operationsBase, 409, 'Operations authority changed before firmware dispatch.');
                    abort_unless(is_string($token) && $token !== '', 503);
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
                abort_unless(in_array($op->status, ['transport_submitted', 'outcome_unknown', 'dispatching'], true) && $op->attempted_at && ($op->status !== 'dispatching' || now()->gte(CarbonImmutable::parse($op->attempted_at)->addMinute())), 409, 'Firmware source outcome remains uncertain.');
                $guard->idle($b, $op->attempted_at);
                $release = DB::table('charger_firmware_releases')->where('id', $op->release_id)->firstOrFail();
                $stationData = $source->graph('query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id firmwareVersion}}', ['name' => $b->identifier]);
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
                $requests = $requestData['OCPPMessages'] ?? null;
                abort_unless(is_array($requests) && array_is_list($requests) && count($requests) === 1
                    && is_array($requests[0]) && is_int($requests[0]['id'] ?? null)
                    && $requests[0]['id'] > 0 && is_string($requests[0]['createdAt'] ?? null),
                    409, 'One typed owned firmware source request is required.');
                $requestAt = SourceStatusTime::parse($requests[0]['createdAt'], 300);
                abort_unless($requestAt->gte(CarbonImmutable::parse($op->attempted_at)), 409, 'Source firmware request predates its operation.');
                $messageData = $source->graph('query($station:Int!,$after:timestamptz!,$match:jsonb!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"cs"},type:{_eq:2},createdAt:{_gte:$after},action:{_in:["FirmwareStatusNotification","SignedFirmwareStatusNotification"]},payload:{_contains:$match}},order_by:{id:desc},limit:1){id createdAt payload}}', ['station' => $station[0]['id'], 'after' => $requests[0]['createdAt'], 'match' => ['requestId' => (int) $op->id]]);
                $messages = $messageData['OCPPMessages'] ?? null;
                abort_unless(is_array($messages) && array_is_list($messages) && count($messages) === 1 &&
                    is_array($messages[0]) && is_int($messages[0]['id'] ?? null) &&
                    $messages[0]['id'] > 0 && is_string($messages[0]['createdAt'] ?? null) &&
                    is_array($messages[0]['payload'] ?? null) &&
                    is_int($messages[0]['payload']['requestId'] ?? null) &&
                    $messages[0]['payload']['requestId'] === (int) $op->id &&
                    is_string($messages[0]['payload']['status'] ?? null),
                    409, 'One typed correlated firmware status is required.');
                abort_unless(SourceStatusTime::parse($messages[0]['createdAt'], 300)->gte($requestAt), 409,
                    'Source firmware status predates the owned request.');
                $status = $messages[0]['payload']['status'];
                $installed = $status === 'Installed';
                abort_unless($installed || in_array($status, ['DownloadFailed', 'InstallationFailed', 'InvalidSignature', 'InstallVerificationFailed', 'InvalidCertificate'], true), 409, 'A correlated terminal firmware report is required.');
                if ($installed) {
                    abort_unless($release->revoked_at === null && ($station[0]['firmwareVersion'] ?? null) === $release->version, 409, 'Installed version must match the approved release.');
                }
                DB::table('charger_firmware_operations')->where('id', $op->id)->update(['status' => $installed ? 'source_installed' : 'source_failed', 'reviewed_by' => $r->user()->id, 'review_note' => Crypt::encryptString($v['note']), 'reviewed_at' => now(), 'updated_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'firmware.source_reviewed', 'subject' => (string) $op->id, 'created_at' => now()]);
            }

            return response()->json(['reviewed' => true, 'physical_execution_verified' => false, 'charging_sessions_closed' => false])->header('Cache-Control', 'private, no-store');
        });
    }
}
