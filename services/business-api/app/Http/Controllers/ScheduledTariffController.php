<?php

namespace App\Http\Controllers;

use App\Models\Finance\TariffVersion;
use App\Models\Station;
use App\Security\CurrentFinanceReader;
use App\Services\Finance\FinanceService;
use App\Services\Finance\ScheduledTariffWindow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Explicit administrative time-based pricing experiment; no production billing. */
final class ScheduledTariffController extends Controller
{
    private function requireEnabled(): void
    {
        abort_unless(app()->environment(['local', 'testing']) && config('charging_tariffs.scheduled_enabled'), 503, 'Scheduled simulator tariffs are disabled.');
    }

    private function pending(Station $station, \DateTimeInterface $clock): ?TariffVersion
    {
        $future = TariffVersion::where('station_id', $station->id)->where('effective_from', '>', $clock);
        $latest = (clone $future)->orderByDesc('effective_from')->orderByDesc('id')->first();
        abort_if($latest && (clone $future)->where('effective_from', '<>', $latest->effective_from)->exists(),
            409, 'Multiple future activation times require manual review.');

        return $latest;
    }

    public function show(Request $request, Station $station, FinanceService $finance)
    {
        $this->requireEnabled();
        abort_unless($request->user()?->role === 'admin', 403);

        return DB::transaction(function () use ($request, $station, $finance) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($request);
            abort_unless($actor->role === 'admin', 403);
            $station = Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
            abort_unless($station->source === 'simulator', 409, 'Only simulated tariffs are enabled.');
            $this->requireEnabled();
            $clock = now('UTC');

            return response()->json([
                'active_tariff' => $finance->activeTariff($station, $clock),
                'pending_tariff' => $this->pending($station, $clock),
                'server_time' => $clock->toIso8601String(),
                'evidence' => 'SIMULATED',
                'physical_availability_verified' => false,
            ])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function store(Request $request, Station $station, FinanceService $finance)
    {
        $this->requireEnabled();
        abort_unless($request->user()?->role === 'admin', 403);
        $v = $request->validate([
            'effective_at' => 'required|string|max:20',
            'expected_active_tariff_id' => 'present|nullable|integer|min:1',
            'expected_pending_tariff_id' => 'sometimes|nullable|integer|min:1',
            'expected_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'expected_minor_units' => 'required|integer|between:0,3',
            'rate_minor_per_kwh' => 'required|integer|between:0,1000000',
            'tax_bps' => 'required|integer|between:0,10000',
            'commission_bps' => 'required|integer|between:0,10000',
            'idempotency_key' => 'required|uuid',
        ]);
        $at = ScheduledTariffWindow::parse($v['effective_at']);
        abort_unless($at !== null, 422, 'Use a valid absolute UTC second.');
        $key = hash('sha256', "scheduled-tariff-v1\0".$request->user()->id."\0".strtolower($v['idempotency_key']));
        $values = [
            'rate_minor_per_kwh' => (int) $v['rate_minor_per_kwh'],
            'tax_bps' => (int) $v['tax_bps'],
            'commission_bps' => (int) $v['commission_bps'],
            'currency' => $v['expected_currency'],
            'minor_units' => (int) $v['expected_minor_units'],
        ];
        $expected = $v['expected_active_tariff_id'] === null ? null : (int) $v['expected_active_tariff_id'];
        $expectedPending = isset($v['expected_pending_tariff_id']) ? (int) $v['expected_pending_tariff_id'] : null;
        $intent = [$station->id, $v['effective_at'], $expected, $values];
        if ($expectedPending !== null) {
            $intent[] = $expectedPending;
        }
        $fingerprint = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($request, $station, $finance, $at, $key, $values, $expected, $expectedPending, $fingerprint) {
                $actor = app(CurrentFinanceReader::class)->requireCurrent($request);
                abort_unless($actor->role === 'admin', 403);
                $station = Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
                abort_unless($station->source === 'simulator', 409, 'Only simulated tariffs are enabled.');
                $this->requireEnabled();
                $old = TariffVersion::where('publishing_key', $key)->first();
                if ($old) {
                    abort_unless(hash_equals($old->publishing_fingerprint, $fingerprint), 409, 'Schedule identity payload mismatch.');

                    return response()->json($old, 201)->header('Cache-Control', 'private, no-store');
                }
                $region = DB::table('regions')->where('country', $station->country)
                    ->where('currency', $station->currency)->lockForUpdate()->first();
                abort_unless($region && $values['currency'] === $station->currency
                    && $values['minor_units'] === (int) $region->minor_units, 409, 'Pricing currency or precision changed.');
                $clock = now('UTC');
                abort_unless(ScheduledTariffWindow::allowed($at, $clock), 422, 'Schedule at least 60 seconds and at most 30 days ahead.');
                abort_unless($finance->activeTariff($station, $clock)?->id === $expected, 409, 'Active tariff changed; review the complete future rate.');
                $pending = $this->pending($station, $clock);
                abort_unless(ScheduledTariffWindow::matchesPending(
                    $expectedPending, $pending?->id, $pending?->effective_from, $at
                ), 409, 'Pending tariff changed or activation time differs; refresh schedule.');
                // An explicit correction appends an immutable version at
                // exactly the existing activation instant. Active selection
                // already breaks ties by descending version ID.

                $tariff = TariffVersion::create($values + [
                    'station_id' => $station->id, 'created_by' => $actor->id,
                    'effective_from' => $at->format('Y-m-d H:i:s'),
                    'publishing_key' => $key, 'publishing_fingerprint' => $fingerprint,
                ]);
                DB::table('audit_events')->insert([
                    'actor_id' => $actor->id, 'action' => $pending ? 'finance.simulated_tariff_schedule_corrected' : 'finance.simulated_tariff_scheduled',
                    'subject' => (string) $tariff->id, 'created_at' => now(),
                ]);

                return response()->json($tariff, 201)->header('Cache-Control', 'private, no-store');
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Tariff scheduling conflict; refresh history.');
        }
    }
}
