<?php

namespace App\Http\Controllers;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PlatformController extends Controller
{
    private function audit(Request $r, string $action, string $subject): void
    {
        DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => $action, 'subject' => $subject, 'created_at' => now()]);
    }

    public function register(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:254|unique:users', 'password' => 'required|string|min:12|max:128', 'locale' => 'sometimes|in:en,ar']);
        $u = User::create(['name' => $v['name'], 'email' => $v['email'], 'password' => Hash::make($v['password'])]);
        $u->locale = $v['locale'] ?? 'en';
        $u->save();
        Auth::login($u);
        $r->session()->regenerate();

        return response()->json($u, 201);
    }

    public function login(Request $r)
    {
        $v = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($v)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }
        $r->session()->regenerate();

        return response()->json($r->user());
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->noContent();
    }

    public function stations(Request $r)
    {
        $v = $r->validate(['q' => 'sometimes|string|max:100', 'connector_type' => 'sometimes|in:CCS2,Type2,CHAdeMO', 'country' => 'sometimes|string|size:2', 'min_power_kw' => 'sometimes|integer|min:1|max:1000']);
        $q = Station::query();
        if (isset($v['q'])) {
            $q->where('name', 'like', '%'.$v['q'].'%');
        }
        foreach (['connector_type', 'country'] as $k) {
            if (isset($v[$k])) {
                $q->where($k, $v[$k]);
            }
        }
        if (isset($v['min_power_kw'])) {
            $q->where('power_kw', '>=', $v['min_power_kw']);
        }

        return $q->orderByDesc('id')->paginate(25)->through(fn ($s) => array_merge($s->toArray(), ['availability' => 'unknown', 'remote_control' => false, 'reservable' => $s->source === 'simulator', 'evidence' => $s->source === 'simulator' ? 'SIMULATED' : 'DISCOVERY_ONLY']));
    }

    public function createStation(Request $r)
    {
        abort_unless($r->user()->role === 'vendor', 403);
        $v = $r->validate(['name' => 'required|string|max:150', 'country' => 'required|string|size:2|uppercase|exists:regions,country', 'currency' => 'required|string|size:3|uppercase', 'timezone' => 'required|timezone', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'source' => 'required|in:discovery,simulator', 'connector_type' => 'required|in:CCS2,Type2,CHAdeMO', 'power_kw' => 'required|integer|between:1,1000']);

        abort_unless(DB::table('regions')->where('country', $v['country'])->where('currency', $v['currency'])->exists(), 422, 'Currency does not match configured region.');

        return DB::transaction(function () use ($r, $v) {
            $s = Station::create($v + ['vendor_id' => $r->user()->id]);
            $this->audit($r, 'station.created', (string) $s->id);

            return response()->json($s, 201);
        });
    }

    public function vehicles(Request $r)
    {
        return Vehicle::where('user_id', $r->user()->id)->paginate(25);
    }

    public function createVehicle(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:100', 'connector_type' => 'required|in:CCS2,Type2,CHAdeMO']);

        return response()->json(Vehicle::create($v + ['user_id' => $r->user()->id]), 201);
    }

    public function reservations(Request $r)
    {
        $q = Reservation::query();
        if ($r->user()->role === 'vendor') {
            $q->whereIn('station_id', Station::where('vendor_id', $r->user()->id)->select('id'));
        } elseif ($r->user()->role !== 'admin') {
            $q->where('user_id', $r->user()->id);
        }

        $page = $q->orderByDesc('id')->paginate(25);
        $bound = DB::table('csms_bindings')->whereIn('station_id', $page->getCollection()->pluck('station_id'))->pluck('station_id')->flip();

        return $page->through(fn ($reservation) => $reservation->toArray() + ['csms_bound' => $bound->has($reservation->station_id)]);
    }

    public function reserve(Request $r)
    {
        abort_unless($r->user()->role === 'customer', 403);
        $v = $r->validate(['station_id' => 'required|integer|exists:stations,id', 'starts_at' => 'required|date_format:Y-m-d\TH:i:s\Z', 'idempotency_key' => 'required|string|max:100']);
        $v['station_id'] = (int) $v['station_id'];
        $start = CarbonImmutable::parse($v['starts_at'], 'UTC');
        abort_unless($start->second === 0 && in_array($start->minute, [0, 30]), 422, 'Use a UTC half-hour boundary.');
        $v['starts_at'] = $start->format('Y-m-d H:i:s');
        $prior = Reservation::where('user_id', $r->user()->id)->where('idempotency_key', $v['idempotency_key'])->first();
        if ($prior) {
            abort_unless($prior->station_id === $v['station_id'] && $prior->starts_at === $v['starts_at'], 409, 'Idempotency key payload mismatch.');

            return response()->json($prior);
        }
        abort_unless($start->isFuture() && $start->lessThan(now()->addDays(30)), 422, 'Choose a slot within the next 30 days.');
        abort_unless(Station::findOrFail($v['station_id'])->source === 'simulator', 409, 'Reservations are unavailable for discovery-only locations.');
        try {
            return DB::transaction(function () use ($r, $v) {
                $b = Reservation::create($v + ['user_id' => $r->user()->id]);
                $this->audit($r, 'reservation.created', (string) $b->id);

                return response()->json($b, 201);
            });
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'])) {
                abort(409, 'Slot or idempotency key already used; refresh reservations.');
            } throw $e;
        }
    }

    public function cancel(Request $r, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $r->user()->id, 404);

        return DB::transaction(function () use ($r, $reservation) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_if(ChargingSession::where('reservation_id', $reservation->id)->exists(), 409, 'A started reservation cannot be cancelled.');
            $reservation->update(['status' => 'cancelled']);
            $this->audit($r, 'reservation.cancelled', (string) $reservation->id);

            return $reservation;
        });
    }
}
