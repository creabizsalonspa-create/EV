<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\InboxNotification;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\FaultMonitor;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Repair documentation never releases maintenance blocks or sends charger commands. */
class ChargingRepairController extends Controller
{
    private function scope(Request $r, VendorCompany $company, VendorCharger $charger, bool $write = false): object
    {
        $actor = User::findOrFail($r->user()->id);
        $r->setUserResolver(fn () => $actor);

        return app(ChargerResetController::class)->scope($r, $company->fresh(), $charger->fresh(), $write);
    }

    private function alerts(object $binding)
    {
        return DB::table('charging_fault_alerts as a')->join('charging_status_observations as o', 'o.id', '=', 'a.observation_id')->where('o.source_generation', FaultMonitor::generation($binding));
    }

    private function reply(mixed $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $r->validate([
            'page' => 'sometimes|integer|min:1|max:100000',
            'focus_order_id' => 'sometimes|integer|min:1',
        ]);
        $b = $this->scope($r, $company, $charger);
        $query = $this->alerts($b)->join('charging_repair_orders as w', 'w.alert_id', '=', 'a.id');
        $focusPage = null;
        if ($r->filled('focus_order_id')) {
            $focus = $r->integer('focus_order_id');
            // The focus must belong to this caller's canonical binding.
            // Never reveal another vendor's order or its list position.
            abort_unless((clone $query)->where('w.id', $focus)->exists(), 404);
            $newer = (clone $query)->where('w.id', '>', $focus)->count();
            $focusPage = intdiv($newer, 25) + 1;
        }
        $page = $focusPage ?? $r->integer('page', 1);
        $rows = $query->select('w.id', 'w.alert_id', 'w.state', 'w.version',
            'w.created_at', 'w.updated_at', 'o.evse_number', 'o.connector_number',
            'a.recovery_observation_id')
            ->orderByDesc('w.id')->paginate(25, ['*'], 'page', $page)->toArray();
        if ($focusPage !== null) {
            // Read-committed insertion between the count and page query
            // must not claim that the requested order was actually shown.
            abort_unless(collect($rows['data'])->contains(
                fn ($row) => (int) data_get($row, 'id') === $focus
            ), 409, 'Repair list changed while resolving the notification. Retry.');
        }

        return $this->reply($rows + ['focus_page' => $focusPage, 'can_manage' => $r->user()->role === 'admin' || $r->user()->id === $company->owner_id, 'commands_sent' => false, 'capacity_changed' => false, 'physical_repair_verified' => false]);
    }

    public function history(Request $r, VendorCompany $company, VendorCharger $charger, int $order)
    {
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $b = $this->scope($r, $company, $charger);
        abort_unless($this->alerts($b)->join('charging_repair_orders as w', 'w.alert_id', '=', 'a.id')->where('w.id', $order)->exists(), 404);
        $rows = DB::table('charging_repair_events')->where('order_id', $order)->orderByDesc('version')->paginate(25)->through(fn ($e) => ['state' => $e->state, 'version' => $e->version, 'note' => Crypt::decryptString($e->note_ciphertext), 'created_at' => $e->created_at])->toArray();

        return $this->reply($rows);
    }

    public function save(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $this->scope($r, $company, $charger, true);
        $v = $r->validate(['request_key' => 'required|uuid', 'alert_id' => 'required|integer|min:1', 'expected_version' => 'required|integer|min:0', 'state' => 'required|in:open,in_progress,review,closed', 'note' => 'required|string|min:10|max:2000']);
        $hash = hash('sha256', json_encode([$company->id, $charger->id, $v['alert_id'], $v['expected_version'], $v['state'], $v['note']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($r, $company, $charger, $v, $hash) {
            VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $b = $this->scope($r, $company, $charger, true);
            $locked = DB::table('csms_bindings')->where('id', $b->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals(FaultMonitor::generation($b), FaultMonitor::generation($locked)), 409);
            $alert = $this->alerts($b)->where('a.id', $v['alert_id'])->select('a.*')->lockForUpdate()->first();
            abort_unless($alert, 404);
            $old = DB::table('charging_repair_events')->where('actor_id', $r->user()->id)->where('request_key', $v['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_hash, $hash), 409);

                return $this->reply(['id' => $old->order_id, 'version' => $old->version, 'state' => $old->state, 'replayed' => true, 'capacity_changed' => false, 'commands_sent' => false]);
            }
            $order = DB::table('charging_repair_orders')->where('alert_id', $alert->id)->lockForUpdate()->first();
            abort_unless(($order->version ?? 0) === $v['expected_version'], 409, 'Repair history changed. Refresh before recording another update.');
            $allowed = ['open' => ['in_progress'], 'in_progress' => ['review', 'open'], 'review' => ['in_progress', 'closed'], 'closed' => ['open']];
            abort_unless($order ? in_array($v['state'], $allowed[$order->state], true) : $v['state'] === 'open', 409);
            // Closure documents operator review of canonical source recovery only.
            // No physical repair, fault clearance or connector availability is inferred.
            abort_if($v['state'] === 'closed' && $alert->recovery_observation_id === null, 409, 'Observe source recovery before closing repair review.');
            $version = ($order->version ?? 0) + 1;
            $values = ['state' => $v['state'], 'version' => $version, 'updated_at' => now()];
            if ($order) {
                $id = $order->id;
                DB::table('charging_repair_orders')->where('id', $id)->update($values);
            } else {
                $id = DB::table('charging_repair_orders')->insertGetId($values + ['alert_id' => $alert->id, 'created_by' => $r->user()->id, 'created_at' => now()]);
            }
            DB::table('charging_repair_events')->insert(['order_id' => $id, 'actor_id' => $r->user()->id, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'state' => $v['state'], 'version' => $version, 'note_ciphertext' => Crypt::encryptString($v['note']), 'created_at' => now()]);
            app(VendorAccess::class)->audit($r->user(), 'charging_repair.'.$v['state'], $id);
            // The existing repair event (unique actor/request key) and owner
            // notice are in one transaction. A replay returns above without
            // another message. Notes remain encrypted, never exposed here.
            if ($r->user()->id !== $company->owner_id) {
                $kind = match ($v['state']) {
                    'open' => $order
                        ? 'charging.repair.reopened.simulated'
                        : 'charging.repair.opened.simulated',
                    'in_progress' => 'charging.repair.work_started.simulated',
                    'review' => 'charging.repair.review_requested.simulated',
                    'closed' => 'charging.repair.review_closed.simulated',
                };
                InboxNotification::create([
                    'user_id' => $company->owner_id,
                    'kind' => $kind,
                    'metadata' => [
                        'source' => 'simulator',
                        'evidence' => 'SIMULATED_SOURCE_OBSERVED',
                        'physical_verification' => false,
                        'company_id' => $company->id,
                        'charger_id' => $charger->id,
                        'station_id' => $locked->station_id,
                        'alert_id' => $alert->id,
                        'repair_order_id' => $id,
                        'review_only' => true,
                    ],
                ]);
            }

            return $this->reply(['id' => $id, 'version' => $version, 'state' => $v['state'], 'replayed' => false, 'capacity_changed' => false, 'commands_sent' => false]);
        }, 3);
    }
}
