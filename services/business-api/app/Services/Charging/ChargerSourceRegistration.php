<?php

namespace App\Services\Charging;

use App\Http\Middleware\AdminSecondFactorGate;
use App\Models\Charging\AdmissionProfile;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Security\AdminTransactionAuthority;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Metadata-only, single-attempt upstream registration. Never a charger command. */
final class ChargerSourceRegistration
{
    public function scope(Request $request, VendorCompany $company, VendorCharger $charger, bool $write = false): array
    {
        app(MaintenanceActor::class)->check($request->user());
        app(MaintenanceActor::class)->checkBearer($request->user());
        if ($write) {
            $actor = AdminTransactionAuthority::requireCurrent($request->user());
            abort_unless(! AdminSecondFactorGate::required() || AdminSecondFactorGate::verified($request, $actor), 403, 'Current administrator verification is required.');
        }
        app(VendorAccess::class)->require($request->user(), $company, 'inventory.read');
        $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
        $charger = VendorCharger::whereKey($charger->id)->lockForUpdate()->firstOrFail();
        abort_unless($charger->company_id === $company->id, 404);
        $site = VendorSite::whereKey($charger->site_id)->lockForUpdate()->firstOrFail();
        abort_unless($site->company_id === $company->id && $site->station_id, 409, 'Link owned catalog inventory first.');
        $station = Station::whereKey($site->station_id)->lockForUpdate()->firstOrFail();
        abort_unless($station->vendor_id === $company->owner_id && $station->country === $company->country && $site->country === $company->country && $station->source === 'direct_unverified' && $charger->source === 'direct_unverified' && in_array($charger->ocpp_protocol, ['1.6', '2.0.1'], true), 409, 'Only owned direct unverified inventory can enter source registration.');
        if ($write) {
            app(VendorAccess::class)->operational($company);
            abort_unless(User::whereKey($company->owner_id)->where('role', 'vendor')->where('account_status', 'active')->lockForUpdate()->first(), 409, 'A current active vendor owner is required.');
        }

        return [$company, $charger, $station];
    }

    private function sourceUrl(bool $enabled = true): string
    {
        abort_unless(! $enabled || config('csms.upstream_registration_enabled'), 503, 'Upstream registration is disabled.');
        $url = app(CsmsGraphqlTransport::class)->requireApproved();
        $parts = is_string($url) ? parse_url($url) : false;
        abort_unless(is_array($parts) && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']) && strlen($url) <= 2048 && ! preg_match('/[\x00-\x20\x7f\\\\]/', $url), 503, 'A fixed authenticated source URL is required.');
        $tls = ($parts['scheme'] ?? null) === 'https';
        $local = app()->environment(['local', 'testing']) && config('csms.upstream_registration_allow_local_http') && ($parts['scheme'] ?? null) === 'http' && in_array(strtolower($parts['host']), ['127.0.0.1', 'localhost', '[::1]', 'hasura', 'citrine-hasura', 'ev-hasura', 'charging-graphql'], true);
        abort_unless(($tls || $local) && strlen((string) config('csms.graphql_secret')) >= 32, 503, 'A TLS authenticated source is required.');

        return $url;
    }

    private function generation(string $url): string
    {
        return hash('sha256', 'citrineos-v2.1.0-tenant-1|'.$url);
    }

    private function source(bool $enabled = true): string
    {
        return $this->generation($this->sourceUrl($enabled));
    }

    private function event(int $actor, int $registration, string $action): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => $action, 'subject' => (string) $registration, 'created_at' => now()]);
    }

    public function present(?object $row): array
    {
        return ['registration_id' => $row?->id, 'binding_id' => $row?->binding_id, 'profile_id' => $row?->profile_id, 'state' => $row?->state ?? 'not_prepared', 'source_station_id' => $row?->source_station_id, 'verified_at' => $row?->verified_at ? CarbonImmutable::parse($row->verified_at, 'UTC')->utc()->toISOString() : null, 'upstream_registration_verified' => $row?->state === 'registered', 'evidence' => $row?->state === 'registered' ? 'authenticated_source_record_created_and_read_back' : 'no_verified_registration', 'device_commands_sent' => 0, 'charging_capability_granted' => false, 'charger_hardware_verified' => false, 'automatic_retry_allowed' => false, 'source_snapshot_only' => true, 'live_connection_verified' => false];
    }

    public function prepare(Request $request, VendorCompany $company, VendorCharger $charger, array $value): array
    {
        $generation = $this->source();
        try {
            return DB::transaction(function () use ($request, $company, $charger, $value, $generation) {
                [$company, $charger, $station] = $this->scope($request, $company, $charger, true);
                abort_unless(strlen($charger->manufacturer) <= 20 && strlen($charger->model) <= 20 && trim($charger->manufacturer) !== '' && trim($charger->model) !== '', 422, 'Source manufacturer and model must be nonempty and at most20 bytes.');
                $hash = hash('sha256', json_encode([$company->id, $company->owner_id, $charger->id, $station->id, $value['identity'], $charger->ocpp_protocol, $charger->manufacturer, $charger->model, $generation], JSON_THROW_ON_ERROR));
                $old = DB::table('charger_source_registrations')->where('charger_id', $charger->id)->first();
                if ($old) {
                    abort_unless($old->actor_id === $request->user()->id && hash_equals($old->idempotency_key, $value['idempotency_key']) && hash_equals($old->payload_hash, $hash), 409, 'Registration identity changed; retain the original receipt.');
                    $this->lineage($old, $charger, $station);

                    return $this->present($old) + ['created' => false];
                }
                abort_if(DB::table('charger_source_registrations')->where('actor_id', $request->user()->id)->where('idempotency_key', $value['idempotency_key'])->exists(), 409, 'Request identity belongs to another registration.');
                abort_if(DB::table('csms_bindings')->where('identifier', $value['identity'])->orWhere('vendor_charger_id', $charger->id)->exists(), 409, 'Existing source mappings require a separate ownership review.');
                abort_if(AdmissionProfile::where('charger_id', $charger->id)->orWhere('identity', $value['identity'])->exists(), 409, 'Existing admission identities require a separate ownership review.');
                $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => $value['identity'], 'protocol' => $charger->ocpp_protocol, 'created_at' => now(), 'updated_at' => now()]);
                $profile = AdmissionProfile::create(['charger_id' => $charger->id, 'identity' => $value['identity'], 'protocol' => $charger->ocpp_protocol]);
                $id = DB::table('charger_source_registrations')->insertGetId(['charger_id' => $charger->id, 'binding_id' => $binding, 'profile_id' => $profile->id, 'actor_id' => $request->user()->id, 'idempotency_key' => $value['idempotency_key'], 'payload_hash' => $hash, 'source_generation' => $generation, 'state' => 'prepared', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('charger_admission_events')->insert(['profile_id' => $profile->id, 'actor_id' => $request->user()->id, 'action' => 'prepared', 'credential_version' => 0, 'created_at' => now()]);
                $this->event($request->user()->id, $id, 'charger.source_registration_prepared');

                return $this->present(DB::table('charger_source_registrations')->find($id)) + ['created' => true];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Source identity or request identity is already reserved.');
        }
    }

    private function lineage(object $row, VendorCharger $charger, Station $station, bool $currentSource = true): object
    {
        $binding = DB::table('csms_bindings')->where('id', $row->binding_id)->lockForUpdate()->first();
        $profile = AdmissionProfile::whereKey($row->profile_id)->lockForUpdate()->first();
        abort_unless($binding && $profile && $row->charger_id === $charger->id && $profile->charger_id === $charger->id && $binding->vendor_charger_id === $charger->id && $binding->station_id === $station->id && $binding->identifier === $profile->identity && $binding->protocol === $charger->ocpp_protocol && $profile->protocol === $charger->ocpp_protocol && (! $currentSource || hash_equals($row->source_generation, $this->source(false))), 409, 'Canonical registration mapping or authenticated source changed.');

        $hash = hash('sha256', json_encode([$charger->company_id, $station->vendor_id, $charger->id, $station->id, $binding->identifier, $charger->ocpp_protocol, $charger->manufacturer, $charger->model, $row->source_generation], JSON_THROW_ON_ERROR));
        abort_unless(hash_equals($row->payload_hash, $hash), 409, 'Prepared registration inventory changed; retain the original request for review.');

        return $binding;
    }

    private function attemptUrl(string $generation, #[\SensitiveParameter] string $secret): string
    {
        $url = $this->sourceUrl();
        $current = config('csms.graphql_secret');
        abort_unless(hash_equals($generation, $this->generation($url)) && is_string($current)
            && hash_equals($secret, $current), 409, 'Authenticated registration source changed.');

        return $url;
    }

    private function graph(string $query, array $variables, string $generation, #[\SensitiveParameter] string $secret): array
    {
        // Every lookup, mutation and read-back must use the exact prepared
        // source generation, with the registration adapter still enabled.
        // Even another approved HTTPS origin cannot supply its receipt.
        $url = $this->attemptUrl($generation, $secret);
        $response = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => $secret])->post($url, compact('query', 'variables'));
        $this->attemptUrl($generation, $secret);
        abort_unless($response->successful() && strlen($response->body()) <= 32768 && ! $response->json('errors') && is_array($response->json('data')), 503, 'Authenticated source registration requires inspection.');

        return $response->json('data');
    }

    private function lookup(string $identity, string $generation, #[\SensitiveParameter] string $secret): array
    {
        $rows = $this->graph('query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id tenantId ocppConnectionName protocol isOnline chargePointVendor chargePointModel}}', ['name' => $identity], $generation, $secret)['ChargingStations'] ?? null;
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) <= 1, 409, 'Ambiguous source identity requires inspection.');

        return $rows;
    }

    private function verify(mixed $row, array $object): array
    {
        abort_unless(is_array($row) && is_int($row['id'] ?? null) && $row['id'] > 0, 409, 'Typed source registration identity is required.');
        foreach (['tenantId', 'ocppConnectionName', 'protocol', 'isOnline', 'chargePointVendor', 'chargePointModel'] as $field) {
            abort_unless(array_key_exists($field, $row) && $row[$field] === $object[$field], 409, 'Source registration differs from the owned request.');
        }

        return array_intersect_key($row, array_flip(['id', 'tenantId', 'ocppConnectionName', 'protocol', 'isOnline', 'chargePointVendor', 'chargePointModel']));
    }

    public function verified(VendorCompany $company, VendorCharger $charger, bool $currentSource = true): bool
    {
        try {
            return DB::transaction(function () use ($company, $charger, $currentSource) {
                $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
                $charger = VendorCharger::whereKey($charger->id)->lockForUpdate()->firstOrFail();
                if ($charger->company_id !== $company->id) {
                    return false;
                }
                $site = VendorSite::find($charger->site_id);
                $station = $site ? Station::find($site->station_id) : null;
                $row = DB::table('charger_source_registrations')->where('charger_id', $charger->id)->where('state', 'registered')->whereNotNull('source_station_id')->whereNotNull('source_receipt_hash')->whereNotNull('verified_at')->first();
                if (! $row || ! $station || $site->company_id !== $company->id || $station->vendor_id !== $company->owner_id) {
                    return false;
                }
                $this->lineage($row, $charger, $station, $currentSource);

                return true;
            });
        } catch (Throwable) {
            return false;
        }
    }

    public function register(Request $request, VendorCompany $company, VendorCharger $charger, int $registration): array
    {
        $this->source();
        $secret = config('csms.graphql_secret');
        abort_unless(is_string($secret) && strlen($secret) >= 32, 503, 'A TLS authenticated source is required.');
        $claimed = DB::transaction(function () use ($request, $company, $charger, $registration) {
            [, $charger, $station] = $this->scope($request, $company, $charger, true);
            $row = DB::table('charger_source_registrations')->where('id', $registration)->where('charger_id', $charger->id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->lineage($row, $charger, $station);
            if ($row->state !== 'prepared') {
                return false;
            }
            DB::table('charger_source_registrations')->where('id', $row->id)->update(['state' => 'registering', 'attempted_at' => now()->format('Y-m-d H:i:s.u'), 'updated_at' => now()]);
            $this->event($request->user()->id, $row->id, 'charger.source_registration_attempted');

            return true;
        }, 3);
        if ($claimed) {
            try {
                // Never retry this transaction: it contains the one external write.
                DB::transaction(function () use ($request, $company, $charger, $registration, $secret) {
                    [, $charger, $station] = $this->scope($request, $company, $charger, true);
                    $row = DB::table('charger_source_registrations')->where('id', $registration)->where('charger_id', $charger->id)->lockForUpdate()->firstOrFail();
                    abort_unless($row->state === 'registering', 409);
                    $binding = $this->lineage($row, $charger, $station);
                    abort_unless($this->lookup($binding->identifier, $row->source_generation, $secret) === [], 409, 'An existing upstream identity cannot be adopted or overwritten.');
                    $object = ['tenantId' => 1, 'ocppConnectionName' => $binding->identifier, 'protocol' => 'ocpp'.$binding->protocol, 'isOnline' => false, 'chargePointVendor' => $charger->manufacturer, 'chargePointModel' => $charger->model, 'createdAt' => now()->utc()->toISOString(), 'updatedAt' => now()->utc()->toISOString()];
                    [, $charger, $station] = $this->scope($request, $company, $charger, true);
                    $this->lineage($row, $charger, $station);
                    $data = $this->graph('mutation($object:ChargingStations_insert_input!){insert_ChargingStations_one(object:$object){id tenantId ocppConnectionName protocol isOnline chargePointVendor chargePointModel}}', compact('object'), $row->source_generation, $secret);
                    $created = $this->verify($data['insert_ChargingStations_one'] ?? null, $object);
                    $rows = $this->lookup($binding->identifier, $row->source_generation, $secret);
                    abort_unless(count($rows) === 1, 409, 'Created source registration is not yet observable.');
                    $observed = $this->verify($rows[0], $object);
                    abort_unless($observed['id'] === $created['id'], 409, 'Created source identity changed before read-back.');
                    [, $charger, $station] = $this->scope($request, $company, $charger, true);
                    $this->lineage($row, $charger, $station);
                    $this->attemptUrl($row->source_generation, $secret);
                    DB::table('charger_source_registrations')->where('id', $row->id)->update(['state' => 'registered', 'source_station_id' => $created['id'], 'source_receipt_hash' => hash('sha256', json_encode($observed, JSON_THROW_ON_ERROR)), 'verified_at' => now()->format('Y-m-d H:i:s.u'), 'updated_at' => now()]);
                    $this->event($request->user()->id, $row->id, 'charger.source_registration_verified');
                });
            } catch (Throwable $error) {
                DB::transaction(function () use ($registration, $request) {
                    $changed = DB::table('charger_source_registrations')->where('id', $registration)->where('state', 'registering')->update(['state' => 'requires_inspection', 'updated_at' => now()]);
                    if ($changed) {
                        $this->event($request->user()->id, $registration, 'charger.source_registration_requires_inspection');
                    }
                });
                if ($error instanceof HttpExceptionInterface) {
                    throw $error;
                }
                abort(503, 'Registration outcome requires operator inspection; do not repeat the source write.');
            }
        }

        return DB::transaction(function () use ($request, $company, $charger, $registration) {
            [, $charger, $station] = $this->scope($request, $company, $charger, true);
            $row = DB::table('charger_source_registrations')->where('id', $registration)->where('charger_id', $charger->id)->firstOrFail();
            $this->lineage($row, $charger, $station);

            return $this->present($row);
        }, 3);
    }
}
