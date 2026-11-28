<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Charging\ChargingActorConsent;
use App\Services\Ocpi\ReservationRoaming;
use App\Services\Ocpi\RoamingDispatch;
use App\Services\Ocpi\RoamingReconciler;
use App\Services\Ocpi\SandboxRoaming;
use App\Services\Ocpi\StoredSourceIntegrity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoamingOrderController extends Controller
{
    private function actor(Request $request): User
    {
        $snapshot = $request->user();
        app(ChargingActorConsent::class)->capture($snapshot);

        return $snapshot;
    }

    public function callAction($method, $parameters)
    {
        $request = collect($parameters)->first(fn ($value) => $value instanceof Request);
        abort_unless($request?->user(), 401);
        if (in_array($method, ['capabilities', 'offers', 'index', 'show', 'operations', 'reconcile'], true)) {
            return DB::transaction(function () use ($request, $method, $parameters) {
                $this->actor($request);

                return $this->{$method}(...array_values($parameters));
            }, 3);
        }
        // Network operations own short claims/checkpoints; never wrap HTTP in a transaction.
        $this->actor($request);

        return $this->{$method}(...array_values($parameters));
    }

    private function driver(Request $r): User
    {
        $actor = $this->actor($r);
        abort_unless($actor->role === 'customer', 403);

        return $actor;
    }

    private function order(Request $r, int $order): object
    {
        $actor = $this->actor($r);

        return DB::table('ocpi_roaming_orders')->where('id', $order)->where('user_id', $actor->id)->firstOrFail();
    }

    private function reply(mixed $data, int $http = 200)
    {
        return response()->json($data, $http)->header('Cache-Control', 'private, no-store');
    }

    public function capabilities(Request $r)
    {
        $actor = $this->actor($r);

        return $this->reply(['sandbox_enabled' => app()->environment(['local', 'testing']) && (bool) config('ocpi.test_commands_enabled'), 'driver_can_start' => $actor->role === 'customer', 'terms_version' => SandboxRoaming::TERMS, 'live_commands_enabled' => false, 'billing_eligible' => false, 'evidence' => 'SANDBOX_PARTNER_UNVERIFIED']);
    }

    public function offers(Request $r, OcpiRecord $location, SandboxRoaming $service)
    {
        $this->driver($r);
        $p = OcpiProvider::findOrFail($location->ocpi_provider_id);
        [$configuration] = $service->policy($p);
        $service->assertNewWorkContract($p);
        abort_unless($location->module === 'locations', 404);
        $filters = $r->validate(['evse_uid' => 'sometimes|string|max:64', 'connector_id' => 'sometimes|string|max:64']);
        $reservationService = app(ReservationRoaming::class);
        $providerAllowsReservation = $reservationService->supported($p);
        $offers = [];
        // Do not traverse or disclose a corrupted, unpublished or foreign projection.
        $sourceIntact = app(StoredSourceIntegrity::class)->consistent($location)
            && $location->country_code === $configuration['party_country']
            && $location->party_id === $configuration['party_id']
            && ($location->normalized['publish'] ?? false) === true;
        $raw = $sourceIntact ? $location->payload : [];
        $locationName = $sourceIntact ? $location->normalized['name'] : '';
        $attempts = 0;
        $truncated = false;
        foreach ($raw['evses'] ?? [] as $evse) {
            foreach ($evse['connectors'] ?? [] as $connector) {
                foreach ($service->advertisedTariffs($connector) as $tariff) {
                    if (isset($filters['evse_uid']) && $filters['evse_uid'] !== ($evse['uid'] ?? null)) {
                        continue;
                    }
                    if (isset($filters['connector_id']) && $filters['connector_id'] !== ($connector['id'] ?? null)) {
                        continue;
                    }
                    if (++$attempts > 100) {
                        $truncated = true;
                        break 3;
                    }
                    try {
                        $v = $service->offer($location, $evse['uid'], $connector['id'], $tariff);
                        $offers[] = ['evse_uid' => $evse['uid'], 'connector_id' => $connector['id'], 'tariff_id' => $tariff, 'tariff' => $v['tariff']->normalized, 'offer_hash' => $v['hash'], 'terms_version' => SandboxRoaming::TERMS, 'reservation_supported' => $providerAllowsReservation && $reservationService->reservableEvse($location, $evse['uid'], $connector['id']), 'source_updated_at' => $location->source_updated_at, 'tariff_updated_at' => $v['tariff']->source_updated_at];
                    } catch (\Throwable) {
                    }
                }
            }
        }

        return $this->reply(['provider_name' => $p->name, 'location_name' => $locationName, 'location_record_id' => $location->id, 'offers' => $offers, 'reservation_supported' => collect($offers)->contains(fn ($offer) => $offer['reservation_supported'] === true), 'reservation_terms_version' => ReservationRoaming::TERMS, 'truncated' => $truncated, 'evidence' => 'SANDBOX_PARTNER_UNVERIFIED', 'live_commands_enabled' => false, 'billing_eligible' => false, 'local_invoice_created' => false]);
    }

    public function index(Request $r, SandboxRoaming $service)
    {
        $actor = $this->actor($r);
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $rows = DB::table('ocpi_roaming_orders')->where('user_id', $actor->id)->orderByDesc('id')->paginate(25)->through(fn ($row) => $service->present($row, true))->toArray();

        return $this->reply($rows + ['evidence' => 'SANDBOX_PARTNER_UNVERIFIED', 'billing_eligible' => false, 'live_commands_enabled' => false]);
    }

    public function create(Request $r, OcpiRecord $location, SandboxRoaming $service, RoamingDispatch $dispatch)
    {
        $actor = $this->driver($r);
        $v = $r->validate(['request_key' => 'required|uuid', 'evse_uid' => 'required|string|max:64', 'connector_id' => 'required|string|max:64', 'tariff_id' => 'required|string|max:64', 'offer_hash' => 'required|string|size:64', 'terms_version' => 'required|in:'.SandboxRoaming::TERMS, 'consent' => 'required|accepted']);
        $v['consent'] = true;
        $result = $service->create($actor, $location, $v);
        if ($result['created']) {
            $dispatch->start($result['order']);
        }

        return $this->reply($service->present($this->order($r, $result['order']->id), true), $result['created'] ? 201 : 200);
    }

    public function reserve(Request $r, OcpiRecord $location, SandboxRoaming $service, ReservationRoaming $reservations)
    {
        $actor = $this->driver($r);
        $v = $r->validate(['request_key' => 'required|uuid', 'evse_uid' => 'required|string|max:36', 'connector_id' => 'required|string|max:64', 'tariff_id' => 'required|string|max:64', 'offer_hash' => 'required|string|size:64', 'terms_version' => 'required|in:'.ReservationRoaming::TERMS, 'consent' => 'required|accepted', 'duration_minutes' => 'required|integer|in:5,10,15']);
        $v['consent'] = true;
        $result = $service->create($actor, $location, $v, (int) $v['duration_minutes']);
        if ($result['created']) {
            $reservations->reserve($actor, $result['order']);
        }

        return $this->reply($service->present($this->order($r, $result['order']->id), true), $result['created'] ? 201 : 200);
    }

    public function cancelReservation(Request $r, int $order, SandboxRoaming $service, ReservationRoaming $reservations)
    {
        $v = $r->validate(['request_key' => 'required|uuid', 'consent' => 'required|accepted']);
        $reservations->cancel($this->actor($r), $this->order($r, $order), $v['request_key']);

        return $this->reply($service->present($this->order($r, $order), true) + ['physical_stop_implied' => false]);
    }

    public function show(Request $r, int $order, SandboxRoaming $service)
    {
        return $this->reply($service->present($this->order($r, $order), true));
    }

    public function reconcile(Request $r, int $order, SandboxRoaming $service, RoamingReconciler $reconcile)
    {
        return $this->reply($service->present(app(ReservationRoaming::class)->review($reconcile->reconcile($this->order($r, $order))), true));
    }

    public function stop(Request $r, int $order, SandboxRoaming $service, RoamingDispatch $dispatch)
    {
        $v = $r->validate(['request_key' => 'required|uuid']);
        $row = $this->order($r, $order);
        $dispatch->stop($this->actor($r), $row, $v['request_key']);

        return $this->reply($service->present($this->order($r, $order), true));
    }

    public function revoke(Request $r, int $order, SandboxRoaming $service, RoamingDispatch $dispatch)
    {
        $own = $this->order($r, $order);
        DB::transaction(function () use ($own, $r) {
            $actor = $this->actor($r);
            OcpiProvider::whereKey($own->provider_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('ocpi_roaming_orders')->where('id', $own->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->user_id === $actor->id, 404);
            if ($row->token_revoked_at !== null) {
                return;
            }
            $attempted = DB::table('ocpi_roaming_commands')->where('order_id', $row->id)->whereIn('action', ['START_SESSION', 'RESERVE_NOW'])->whereNotNull('attempted_at')->exists();
            $values = ['token_revoked_at' => now(), 'token_state' => 'local_revoked', 'updated_at' => now()];
            if (! $attempted) {
                $values += ['state' => 'cancelled_before_dispatch', 'active_target_key' => null];
            }
            DB::table('ocpi_roaming_orders')->where('id', $row->id)->update($values);
            DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'ocpi.sandbox_new_authority_revoked', 'subject' => (string) $row->id, 'created_at' => now()]);
        }, 3);
        $dispatch->revokeToken($this->order($r, $order), $this->actor($r));

        return $this->reply($service->present($this->order($r, $order), true) + ['physical_stop_implied' => false]);
    }

    public function operations(Request $r, OcpiProvider $provider, SandboxRoaming $service)
    {
        $actor = $this->actor($r);
        $provider = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->role === 'admin' || ($actor->role === 'vendor' && $provider->owner_id === $actor->id), 404);
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return $this->reply(DB::table('ocpi_roaming_orders')->where('provider_id', $provider->id)->when($actor->role !== 'admin', fn ($q) => $q->where('provider_owner_id', $actor->id))->orderByDesc('id')->paginate(25)->through(fn ($row) => $service->present($row, false)));
    }
}
