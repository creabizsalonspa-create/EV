<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerClient;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OcpiController extends Controller
{
    private function owner(Request $r, OcpiProvider $provider): void
    {
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $provider->owner_id === $r->user()->id), 404);
    }

    private function audit(Request $r, string $action, int $id): void
    {
        DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => $action, 'subject' => (string) $id, 'created_at' => now()]);
    }

    public function providers(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['vendor', 'admin']), 403);

        return OcpiProvider::when($r->user()->role !== 'admin', fn ($q) => $q->where('owner_id', $r->user()->id))->paginate(25);
    }

    public function create(Request $r, PartnerClient $client)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['owner_id' => 'required|integer|exists:users,id', 'name' => 'required|string|max:150', 'connection_key' => 'required|string|alpha_dash|max:80|unique:ocpi_providers', 'authorized' => 'required|boolean']);
        abort_unless(User::whereKey($v['owner_id'])->where('role', 'vendor')->exists(), 422, 'Provider owner must be a vendor.');
        abort_unless(is_array(config('ocpi.connections.'.$v['connection_key'])), 422, 'Connection must first be provisioned in secure server configuration.');

        return DB::transaction(function () use ($r, $v, $client) {
            $provider = OcpiProvider::create($v);
            if ($provider->authorized) {
                try {
                    $client->connection($provider);
                } catch (OcpiException $e) {
                    abort(422, $e->getMessage());
                }
            }$this->audit($r, 'ocpi.provider_created', $provider->id);

            return response()->json($provider, 201);
        });
    }

    public function authorization(Request $r, OcpiProvider $provider, PartnerClient $client)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['authorized' => 'required|boolean']);

        return DB::transaction(function () use ($r, $provider, $v, $client) {
            $provider = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $provider->authorized = $v['authorized'];
            if ($provider->authorized) {
                try {
                    $client->connection($provider);
                } catch (OcpiException $e) {
                    abort(422, $e->getMessage());
                }
            }
            $provider->fill(['version' => null, 'endpoints' => null, 'negotiated_at' => null])->save();
            $this->audit($r, $provider->authorized ? 'ocpi.provider_authorized' : 'ocpi.provider_revoked', $provider->id);

            return response()->json($provider);
        });
    }

    public function negotiate(Request $r, OcpiProvider $provider, PartnerClient $client)
    {
        $this->owner($r, $provider);
        try {
            $endpoints = $client->negotiate($provider);
        } catch (OcpiException $e) {
            abort(409, $e->getMessage());
        }$this->audit($r, 'ocpi.version_negotiated', $provider->id);

        return response()->json(['version' => '2.2.1', 'readable_modules' => array_keys($endpoints), 'commands_enabled' => false, 'evidence' => 'CONTRACT_UNVERIFIED']);
    }

    public function sync(Request $r, OcpiProvider $provider, string $module, PartnerClient $client, RecordSynchronizer $sync)
    {
        $this->owner($r, $provider);
        abort_unless(in_array($module, PartnerClient::MODULES, true), 404);
        try {
            $records = $client->pull($provider, $module);
            $counts = $sync->ingest($provider, $module, $records);
        } catch (OcpiException $e) {
            abort(409, $e->getMessage());
        }
        $this->audit($r, 'ocpi.'.$module.'_synchronized', $provider->id);

        return response()->json(['counts' => $counts, 'evidence' => 'PARTNER_REPORTED_UNVERIFIED', 'remote_control' => false, 'billing_eligible' => false]);
    }

    public function records(Request $r, OcpiProvider $provider, string $module)
    {
        $this->owner($r, $provider);
        abort_unless(in_array($module, PartnerClient::MODULES, true), 404);

        return OcpiRecord::where('ocpi_provider_id', $provider->id)->where('module', $module)->orderByDesc('id')->paginate(25);
    }

    public function locations()
    {
        return OcpiRecord::where('module', 'locations')->where('normalized->publish', true)->whereIn('ocpi_provider_id', OcpiProvider::where('authorized', true)->select('id'))->orderByDesc('id')->paginate(25)->through(fn ($r) => ['id' => $r->id, 'provider_id' => $r->ocpi_provider_id, 'location' => $r->normalized, 'received_at' => $r->received_at, 'evidence' => 'PARTNER_REPORTED_UNVERIFIED', 'availability' => 'unknown', 'remote_control' => false, 'reservable' => false]);
    }
}
