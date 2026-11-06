<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\RecordSynchronizer;
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

    private function reply(mixed $data, int $code = 1000, int $http = 200)
    {
        return response()->json(['status_code' => $code, 'timestamp' => now()->toISOString(), 'data' => $data], $http)->header('Cache-Control', 'private, no-store')->header('X-Request-ID', (string) Str::uuid())->header('X-Correlation-ID', (string) Str::uuid());
    }

    private function handle(Request $r, string $module, ?string $external, ?string $country = null, ?string $party = null)
    {
        try {
            $exchange = app(CredentialExchange::class);
            [$provider, $link] = app(OcpiProtocolCredentialsController::class)->authenticate($r, $exchange, 'records');
            [$connection] = $exchange->configuration($provider);
            abort_unless(in_array($module, $exchange->receiverModules($provider), true) && $provider->version === '2.2.1' && is_string($provider->endpoints[$module] ?? null), 403);
            $country ??= $connection['party_country'];
            $party ??= $connection['party_id'];
            abort_unless($country === $connection['party_country'] && $party === $connection['party_id'], 403);
            if ($external !== null) {
                abort_unless(strlen($external) >= 1 && strlen($external) <= 64 && ! preg_match('/[\x00-\x20\x7f\/\\\\]/', $external), 400);
            }
            $identity = ['ocpi_provider_id' => $provider->id, 'module' => $module, 'country_code' => $country, 'party_id' => $party];
            if ($r->isMethod('GET')) {
                $record = OcpiRecord::where($identity)->where('external_id', $external)->first();
                abort_unless($record, 404);

                return $this->reply($record->payload);
            }
            abort_unless(strlen($r->getContent()) <= 262144 && str_starts_with(ltrim($r->getContent()), '{'), 413);
            $body = json_decode($r->getContent(), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(is_array($body), 400);
            $id = $external ?? ($body['id'] ?? null);
            abort_unless(is_string($id) && strlen($id) >= 1 && strlen($id) <= 64 && ! preg_match('/[\x00-\x20\x7f\/\\\\]/', $id), 400);
            foreach (['country_code' => $country, 'party_id' => $party, 'id' => $id] as $field => $value) {
                abort_unless(($r->isMethod('PATCH') && ! array_key_exists($field, $body)) || ($body[$field] ?? null) === $value, 403);
            }
            // OCPI PATCH carries the new source timestamp; a partial update cannot silently reuse one.
            abort_unless(is_string($body['last_updated'] ?? null), 400);
            $counts = DB::transaction(function () use ($provider, $link, $exchange, $r, $module, $identity, $id, $body) {
                OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
                DB::table('ocpi_credential_links')->where('id', $link->id)->lockForUpdate()->firstOrFail();
                [$current, $active] = app(OcpiProtocolCredentialsController::class)->authenticate($r, $exchange, 'records');
                abort_unless($active->generation === $link->generation && $current->authorization_revision === $provider->authorization_revision && $active->local_token_digest === $link->local_token_digest, 401);
                $record = $body;
                if ($r->isMethod('PATCH')) {
                    $old = OcpiRecord::where($identity)->where('external_id', $id)->first();
                    abort_unless($old, 404);
                    $record = $this->merge($old->payload, $body);
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
