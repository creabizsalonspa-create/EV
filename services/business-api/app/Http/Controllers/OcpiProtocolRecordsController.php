<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\RecordSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Managed token authority is scoped to the approved peer's own source records. */
class OcpiProtocolRecordsController extends Controller
{
    public function session(Request $r, string $country, string $party, string $external)
    {
        return $this->handle($r, 'sessions', $external, $country, $party);
    }

    public function cdr(Request $r, ?string $external = null)
    {
        return $this->handle($r, 'cdrs', $external);
    }

    public function location(Request $r, string $country, string $party, string $external, ?string $evse = null, ?string $connector = null)
    {
        return $this->handle($r, 'locations', $external, $country, $party, $evse, $connector);
    }

    public function tariff(Request $r, string $country, string $party, string $external)
    {
        return $this->handle($r, 'tariffs', $external, $country, $party);
    }

    private function reply(mixed $data, int $code = 1000, int $http = 200)
    {
        return response()->json(['status_code' => $code, 'timestamp' => now()->toISOString(), 'data' => $data], $http)->header('Cache-Control', 'private, no-store')->header('X-Request-ID', (string) Str::uuid())->header('X-Correlation-ID', (string) Str::uuid());
    }

    private function handle(Request $r, string $module, ?string $external, ?string $country = null, ?string $party = null, ?string $evse = null, ?string $connector = null)
    {
        try {
            $exchange = app(CredentialExchange::class);
            $resource = in_array($module, ['locations', 'tariffs'], true) ? 'catalog' : 'records';
            [$provider, $link] = app(OcpiProtocolCredentialsController::class)->authenticate($r, $exchange, $resource);
            [$connection] = $exchange->configuration($provider);
            abort_unless(in_array($module, $exchange->receiverModules($provider), true) && $provider->version === '2.2.1' && is_string($provider->endpoints[$module] ?? null), 403);
            $country ??= $connection['party_country'];
            $party ??= $connection['party_id'];
            abort_unless($country === $connection['party_country'] && $party === $connection['party_id'], 403);
            if ($external !== null) {
                abort_unless(strlen($external) >= 1 && strlen($external) <= 64 && ! preg_match('/[\x00-\x20\x7f\/\\\\]/', $external), 400);
            }
            foreach ([$evse, $connector] as $part) {
                abort_unless($part === null || (strlen($part) >= 1 && strlen($part) <= 64 && ! preg_match('/[\x00-\x20\x7f\/\\\\]/', $part)), 400);
            }
            $identity = ['ocpi_provider_id' => $provider->id, 'module' => $module, 'country_code' => $country, 'party_id' => $party];
            if ($r->isMethod('GET')) {
                $data = DB::transaction(function () use ($provider, $link, $r, $exchange, $identity, $external, $evse, $connector, $resource) {
                    OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
                    DB::table('ocpi_credential_links')->where('id', $link->id)->lockForUpdate()->firstOrFail();
                    [$current, $active] = app(OcpiProtocolCredentialsController::class)->authenticate($r, $exchange, $resource);
                    abort_unless($active->generation === $link->generation && $current->authorization_revision === $provider->authorization_revision, 401);
                    $record = OcpiRecord::where($identity)->where('external_id', $external)->first();
                    abort_unless($record, 404);
                    abort_if(DB::table('ocpi_catalog_retirements')->where('record_id', $record->id)->where('content_hash', $record->content_hash)->exists(), 404);

                    return $evse === null ? $record->payload : $this->component($record->payload, $evse, $connector);
                }, 3);

                return $this->reply($data);
            }
            if ($r->isMethod('DELETE')) {
                abort_unless($module === 'tariffs' && strlen($r->getContent()) <= 1024, 405);
                DB::transaction(function () use ($provider, $link, $r, $exchange, $identity, $external, $resource) {
                    OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
                    DB::table('ocpi_credential_links')->where('id', $link->id)->lockForUpdate()->firstOrFail();
                    [$current, $active] = app(OcpiProtocolCredentialsController::class)->authenticate($r, $exchange, $resource);
                    abort_unless($active->generation === $link->generation && $current->authorization_revision === $provider->authorization_revision && $current->version === '2.2.1' && is_string($current->endpoints['tariffs'] ?? null), 401);
                    $record = OcpiRecord::where($identity)->where('external_id', $external)->first();
                    abort_unless($record, 404);
                    // Preserve the canonical source payload and every historical
                    // financial reference. DELETE only withdraws current catalog use.
                    DB::table('ocpi_catalog_retirements')->insertOrIgnore(['record_id' => $record->id, 'content_hash' => $record->content_hash, 'credential_generation' => $active->generation,
                        'payload_encrypted' => $record->getRawOriginal('payload'), 'source_updated_at' => $record->source_updated_at->format('Y-m-d H:i:s.u'), 'retired_at' => now()->format('Y-m-d H:i:s.u')]);
                    DB::table('ocpi_record_receipts')->insertOrIgnore(['provider_id' => $current->id, 'record_id' => $record->id, 'credential_generation' => $active->generation, 'method' => 'DELETE', 'content_hash' => $record->content_hash, 'created_at' => now()]);
                }, 3);

                return $this->reply(null);
            }
            abort_unless(strlen($r->getContent()) <= 262144 && str_starts_with(ltrim($r->getContent()), '{'), 413);
            $body = json_decode($r->getContent(), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(is_array($body), 400);
            $id = $external ?? ($body['id'] ?? null);
            abort_unless(is_string($id) && strlen($id) >= 1 && strlen($id) <= 64 && ! preg_match('/[\x00-\x20\x7f\/\\\\]/', $id), 400);
            foreach (['country_code' => $country, 'party_id' => $party, 'id' => $id] as $field => $value) {
                if ($evse !== null && $field === 'id') {
                    continue;
                }
                abort_unless((($r->isMethod('PATCH') || $evse !== null) && ! array_key_exists($field, $body)) || ($body[$field] ?? null) === $value, 403);
            }
            if ($evse !== null) {
                $key = $connector === null ? 'uid' : 'id';
                abort_unless(($r->isMethod('PATCH') && ! array_key_exists($key, $body)) || ($body[$key] ?? null) === ($connector ?? $evse), 403);
            }
            // OCPI PATCH carries the new source timestamp; a partial update cannot silently reuse one.
            abort_unless(is_string($body['last_updated'] ?? null), 400);
            $counts = DB::transaction(function () use ($provider, $link, $exchange, $r, $module, $identity, $id, $body, $evse, $connector, $resource) {
                OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
                DB::table('ocpi_credential_links')->where('id', $link->id)->lockForUpdate()->firstOrFail();
                [$current, $active] = app(OcpiProtocolCredentialsController::class)->authenticate($r, $exchange, $resource);
                abort_unless($active->generation === $link->generation && $current->authorization_revision === $provider->authorization_revision && $active->local_token_digest === $link->local_token_digest, 401);
                abort_unless($current->version === '2.2.1' && is_string($current->endpoints[$module] ?? null), 403);
                $record = $body;
                if ($r->isMethod('PATCH') || $evse !== null) {
                    $old = OcpiRecord::where($identity)->where('external_id', $id)->first();
                    abort_unless($old, 404);
                    $record = $evse === null ? $this->merge($old->payload, $body) : $this->updateComponent($old->payload, $evse, $connector, $body, $r->isMethod('PATCH'));
                }
                if ($module === 'tariffs') {
                    $old = OcpiRecord::where($identity)->where('external_id', $id)->first();
                    $retirement = $old ? DB::table('ocpi_catalog_retirements')->where('record_id', $old->id)->orderByDesc('id')->first() : null;
                    // A delayed pre-withdrawal PUT must not silently reactivate
                    // a withdrawn tariff. Fresh publication must follow receipt time.
                    abort_if($retirement && (CarbonImmutable::parse($record['last_updated'])->lte(CarbonImmutable::parse($retirement->retired_at, 'UTC'))
                        || CarbonImmutable::parse($record['last_updated'])->lte(CarbonImmutable::parse($retirement->source_updated_at, 'UTC'))), 409);
                }
                $result = app(RecordSynchronizer::class)->ingest($current, $module, [$record]);
                // A stale push is not acknowledged as an applied update.
                abort_if($result['stale'] !== 0, 409);
                $accepted = OcpiRecord::where($identity)->where('external_id', $id)->firstOrFail();
                DB::table('ocpi_record_receipts')->insertOrIgnore(['provider_id' => $current->id, 'record_id' => $accepted->id, 'credential_generation' => $active->generation,
                    'method' => $r->method(), 'content_hash' => $accepted->content_hash, 'created_at' => now()]);

                return $result;
            }, 3);
            $response = $this->reply(null, 1000, $counts['created'] ? 201 : 200);
            if ($module === 'cdrs') {
                $url = $exchange->localPayload('')['url'];
                $response->header('Location', substr($url, 0, -strlen('versions')).'2.2.1/cdrs/'.rawurlencode($id));
            }

            return $response;
        } catch (Throwable $e) {
            $http = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 409;

            return $this->reply(null, $http === 401 || $http === 403 ? 2000 : 2001, $http);
        }
    }

    private function component(array $location, string $evse, ?string $connector): array
    {
        $evses = array_values(array_filter($location['evses'] ?? [], fn ($e) => ($e['uid'] ?? null) === $evse));
        abort_unless(count($evses) === 1, 404);
        if ($connector === null) {
            return $evses[0];
        }
        $connectors = array_values(array_filter($evses[0]['connectors'] ?? [], fn ($c) => ($c['id'] ?? null) === $connector));
        abort_unless(count($connectors) === 1, 404);

        return $connectors[0];
    }

    private function updateComponent(array $location, string $evse, ?string $connector, array $body, bool $patch): array
    {
        $evses = $location['evses'] ?? [];
        $indexes = array_keys(array_filter($evses, fn ($e) => ($e['uid'] ?? null) === $evse));
        abort_if(count($indexes) > 1, 409);
        if ($indexes === []) {
            abort_if($patch || $connector !== null, 404);
            $evses[] = $body;
        } else {
            $index = $indexes[0];
            if ($connector === null) {
                $evses[$index] = $patch ? $this->merge($evses[$index], $body) : $body;
            } else {
                $connectors = $evses[$index]['connectors'] ?? [];
                $positions = array_keys(array_filter($connectors, fn ($c) => ($c['id'] ?? null) === $connector));
                abort_if(count($positions) > 1, 409);
                if ($positions === []) {
                    abort_if($patch, 404);
                    $connectors[] = $body;
                } else {
                    $position = $positions[0];
                    $connectors[$position] = $patch ? $this->merge($connectors[$position], $body) : $body;
                }
                $evses[$index]['connectors'] = $connectors;
                $evses[$index]['last_updated'] = $body['last_updated'];
            }
        }
        $location['evses'] = array_values($evses);
        $location['last_updated'] = $body['last_updated'];

        return $location;
    }

    private function merge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($base[$key]);
            } elseif (is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null) && ! array_is_list($base[$key])) {
                $base[$key] = $this->merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
