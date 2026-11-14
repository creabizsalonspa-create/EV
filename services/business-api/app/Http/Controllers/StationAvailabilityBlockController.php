<?php

namespace App\Http\Controllers;

use App\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StationAvailabilityBlockController extends Controller
{
    private function receipt(object $row): array
    {
        return ['id' => $row->id, 'station_id' => (int) $row->station_id, 'starts_at' => CarbonImmutable::parse($row->starts_at, 'UTC')->toIso8601String(),
            'ends_at' => CarbonImmutable::parse($row->ends_at, 'UTC')->toIso8601String(), 'reason_en' => $row->reason_en, 'reason_ar' => $row->reason_ar,
            'revision' => (int) $row->revision, 'cancelled' => $row->cancelled_at !== null, 'scope' => 'STATION_WIDE_OPERATOR_POLICY', 'physical_availability_verified' => false];
    }

    public function index(Station $station)
    {
        $rows = DB::table('station_availability_blocks')->where('station_id', $station->id)->whereNull('cancelled_at')
            ->where('ends_at', '>', now())->orderBy('starts_at')->orderBy('id')->limit(50)->get();

        return response()->json(['station_id' => $station->id, 'data' => $rows->map(fn ($row) => $this->receipt($row)), 'server_time' => now()->toIso8601String()])->header('Cache-Control', 'no-store');
    }

    private function owned(Request $request, Station $station): Station
    {
        abort_unless($request->user()->role === 'vendor', 404);
        $current = Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $current->vendor_id === (int) $request->user()->id, 404);

        return $current;
    }

    public function store(Request $request, Station $station)
    {
        $v = $request->validate(['idempotency_key' => 'required|uuid', 'starts_at' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'ends_at' => 'required|date_format:Y-m-d\TH:i:s\Z|after:starts_at', 'reason_en' => 'required|string|max:300', 'reason_ar' => 'required|string|max:300']);
        foreach (['reason_en', 'reason_ar'] as $key) {
            $v[$key] = trim($v[$key]);
            if ($v[$key] === '') {
                throw ValidationException::withMessages([$key => 'Publish a nonblank public reason.']);
            }
        }

        return DB::transaction(function () use ($request, $station, $v) {
            $owned = $this->owned($request, $station);
            $start = CarbonImmutable::parse($v['starts_at'], 'UTC');
            $end = CarbonImmutable::parse($v['ends_at'], 'UTC');
            $existing = DB::table('station_availability_blocks')->where('station_id', $owned->id)->where('idempotency_key', $v['idempotency_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->created_by === (int) $request->user()->id
                    && CarbonImmutable::parse($existing->starts_at, 'UTC')->eq($start) && CarbonImmutable::parse($existing->ends_at, 'UTC')->eq($end)
                    && $existing->reason_en === $v['reason_en'] && $existing->reason_ar === $v['reason_ar'], 409, 'Request identity was already used for different block details.');

                return response()->json($this->receipt($existing))->header('Cache-Control', 'no-store');
            }
            abort_unless($start->gte(now()->subMinutes(5)) && $start->lte(now()->addYear()) && $end->gt(now()) && $start->diffInDays($end) <= 30, 422, 'Use a current/future UTC interval of at most 30 days within one year.');
            abort_if(DB::table('station_availability_blocks')->where('station_id', $owned->id)->whereNull('cancelled_at')->where('ends_at', '>', now())->count() >= 50, 409, 'At most 50 active/future station blocks are supported.');
            $id = (string) Str::uuid();
            DB::table('station_availability_blocks')->insert(['id' => $id, 'station_id' => $owned->id, 'created_by' => $request->user()->id,
                'idempotency_key' => $v['idempotency_key'], 'starts_at' => $start, 'ends_at' => $end, 'reason_en' => $v['reason_en'], 'reason_ar' => $v['reason_ar'], 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $request->user()->id, 'action' => 'station.availability_block.created', 'subject' => $id, 'created_at' => now()]);

            return response()->json($this->receipt(DB::table('station_availability_blocks')->where('id', $id)->first()), 201)->header('Cache-Control', 'no-store');
        });
    }

    public function cancel(Request $request, Station $station, string $block)
    {
        $v = $request->validate(['revision' => 'required|integer|min:1|max:2147483646']);

        return DB::transaction(function () use ($request, $station, $block, $v) {
            $owned = $this->owned($request, $station);
            $row = DB::table('station_availability_blocks')->where('station_id', $owned->id)->where('id', $block)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->cancelled_at === null) {
                abort_unless((int) $row->revision === (int) $v['revision'], 409, 'Block changed; refresh before cancellation.');
                DB::table('station_availability_blocks')->where('id', $block)->update(['cancelled_at' => now(), 'revision' => $row->revision + 1, 'updated_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => $request->user()->id, 'action' => 'station.availability_block.cancelled', 'subject' => $block, 'created_at' => now()]);
            } else {
                abort_unless((int) $v['revision'] === (int) $row->revision - 1, 409, 'Block changed; refresh before cancellation.');
            }

            return response()->json($this->receipt(DB::table('station_availability_blocks')->where('id', $block)->first()))->header('Cache-Control', 'no-store');
        });
    }
}
