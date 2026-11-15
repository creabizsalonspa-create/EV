<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Services\Charging\ChargingActorConsent;
use App\Services\Ocpi\SessionMeterHistory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RoamingMeterHistoryController extends Controller
{
    public function customer(Request $request, int $order)
    {
        return $this->report($request, $order);
    }

    public function operator(Request $request, OcpiProvider $provider, int $order)
    {
        return $this->report($request, $order, $provider->id);
    }

    private function report(Request $request, int $id, ?int $providerId = null)
    {
        $request->validate(['page' => 'sometimes|integer|min:1|max:1000000', 'through' => 'sometimes|integer|min:0|max:9007199254740991']);

        return DB::transaction(function () use ($request, $id, $providerId) {
            $actor = app(ChargingActorConsent::class)->capture($request->user());
            $candidate = DB::table('ocpi_roaming_orders')->where('id', $id)->when($providerId !== null, fn ($q) => $q->where('provider_id', $providerId))->firstOrFail();
            $provider = OcpiProvider::whereKey($candidate->provider_id)->lockForUpdate()->firstOrFail();
            $order = DB::table('ocpi_roaming_orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($providerId === null) {
                abort_unless($order->user_id === $actor->id, 404);
            } else {
                abort_unless($actor->role === 'admin' || ($actor->role === 'vendor' && $provider->owner_id === $actor->id && $order->provider_owner_id === $actor->id), 404);
            }
            $lineage = app(SessionMeterHistory::class)->orderLineage($order);
            $query = DB::table('ocpi_session_meter_history')->where('record_id', $order->source_session_record_id)
                ->when($lineage !== null, fn ($q) => $q->where('lineage_hash', $lineage), fn ($q) => $q->whereRaw('1 = 0'))
                ->when($providerId !== null && $actor->role !== 'admin', fn ($q) => $q->where('provider_owner_id', $actor->id));
            $through = $request->has('through') ? (int) $request->input('through') : (int) ((clone $query)->max('id') ?? 0);
            $query->where('id', '<=', $through);
            $first = (clone $query)->orderBy('id')->value('received_at');
            $rows = $query->orderByDesc('id')->paginate(25, ['id', 'status', 'reported_energy_kwh', 'energy_wh', 'meter_regressed', 'precision_requires_review', 'source_clock_ahead', 'source_updated_at', 'received_at']);
            $rows->through(function ($row) {
                foreach (['meter_regressed', 'precision_requires_review', 'source_clock_ahead'] as $key) {
                    $row->{$key} = (bool) $row->{$key};
                }
                foreach (['source_updated_at', 'received_at'] as $key) {
                    $row->{$key} = CarbonImmutable::parse($row->{$key}, 'UTC')->toISOString();
                }
                $row->energy_wh = $row->energy_wh === null ? null : (int) $row->energy_wh;

                return $row;
            });

            return response()->json(['scope' => 'roaming_source_meter_history', 'order_id' => $order->id, 'source_bound' => $lineage !== null, 'through_id' => $through,
                'recorded_since' => $first ? CarbonImmutable::parse($first, 'UTC')->toISOString() : null,
                'coverage' => 'ACCEPTED_VERSIONS_SINCE_INSTRUMENTATION', 'backfilled' => false, 'observations' => $rows,
                'evidence' => 'PARTNER_REPORTED_UNVERIFIED', 'physical_energy_verified' => false, 'billing_authority' => false, 'commands_sent' => false])->header('Cache-Control', 'private, no-store');
        }, 3);
    }
}
