<?php

namespace App\Http\Controllers;

use App\Models\ChargerCommand;
use App\Models\Charging\OperationalCase;
use App\Models\Charging\SessionLink;
use App\Models\Station;
use App\Services\Charging\CommandReconciler;
use App\Services\Charging\SourceConsumer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ChargingOperationsController extends Controller
{
    private const UNCERTAIN = ['dispatching', 'submitted', 'outcome_unknown', 'rejected'];

    private function authorizeOperator(Request $r, ?ChargerCommand $command = null): void
    {
        abort_unless(in_array($r->user()->role, ['vendor', 'admin'], true), 403);
        if ($command && $r->user()->role !== 'admin') {
            abort_unless(Station::whereKey($command->station_id)->where('vendor_id', $r->user()->id)->exists(), 404);
        }
    }

    public function index(Request $r)
    {
        $this->authorizeOperator($r);
        $q = ChargerCommand::where(function ($q) {
            $q->whereIn('status', self::UNCERTAIN)->orWhereExists(fn ($s) => $s->selectRaw('1')->from('charging_operational_cases')->whereColumn('command_id', 'charger_commands.id'));
        });
        if ($r->user()->role !== 'admin') {
            $q->whereIn('station_id', Station::where('vendor_id', $r->user()->id)->select('id'));
        }
        $page = $q->orderByDesc('id')->paginate(25);
        $cases = OperationalCase::whereIn('command_id', $page->getCollection()->pluck('id'))->get()->keyBy('command_id');
        $linked = SessionLink::whereIn('start_command_id', $page->getCollection()->pluck('id'))->orWhereIn('stop_command_id', $page->getCollection()->pluck('id'))->get();
        $inspectable = $linked->pluck('start_command_id')->merge($linked->pluck('stop_command_id'))->filter()->all();
        $proof = DB::table('command_source_observations')->whereIn('command_id', $page->getCollection()->pluck('id'))->pluck('command_id')->all();

        return $page->through(fn ($c) => ['id' => $c->id, 'station_id' => $c->station_id, 'action' => $c->action, 'status' => $c->status, 'failure_code' => $c->failure_code, 'updated_at' => $c->updated_at, 'case' => $cases->get($c->id), 'can_inspect' => in_array($c->id, $inspectable, true), 'source_observed' => in_array($c->id, $proof, true), 'physical_charging_verified' => false]);
    }

    public function show(Request $r, ChargerCommand $command)
    {
        $this->authorizeOperator($r, $command);
        $case = OperationalCase::where('command_id', $command->id)->first();
        $events = $case ? DB::table('charging_operational_case_events')->where('case_id', $case->id)->orderByDesc('id')->paginate(25)->through(function ($event) {
            $event->note = Crypt::decryptString($event->note);

            return $event;
        }) : null;

        return response()->json(['case' => $case, 'events' => $events, 'can_inspect' => SessionLink::where('start_command_id', $command->id)->orWhere('stop_command_id', $command->id)->exists(), 'source_observed' => DB::table('command_source_observations')->where('command_id', $command->id)->exists(), 'command' => ['id' => $command->id, 'status' => $command->status], 'physical_charging_verified' => false])->header('Cache-Control', 'no-store');
    }

    public function open(Request $r, ChargerCommand $command)
    {
        $this->authorizeOperator($r, $command);
        $v = $r->validate(['note' => 'required|string|min:10|max:2000']);

        return DB::transaction(function () use ($r, $command, $v) {
            $c = ChargerCommand::whereKey($command->id)->lockForUpdate()->firstOrFail();
            $this->authorizeOperator($r, $c);
            if ($old = OperationalCase::where('command_id', $c->id)->first()) {
                return response()->json($old);
            }
            abort_unless(in_array($c->status, self::UNCERTAIN, true), 409, 'Only admitted or uncertain commands need this recovery queue.');
            $case = OperationalCase::create(['command_id' => $c->id, 'state' => 'open', 'operator_note' => $v['note'], 'opened_by' => $r->user()->id]);
            $this->event($case, $r->user()->id, 'opened', $v['note']);

            return response()->json($case, 201);
        }, 3);
    }

    public function review(Request $r, ChargerCommand $command)
    {
        $this->authorizeOperator($r, $command);
        $v = $r->validate(['note' => 'required|string|min:10|max:2000', 'state' => 'required|in:investigating,awaiting_source,resolved']);

        return DB::transaction(function () use ($r, $command, $v) {
            $c = ChargerCommand::whereKey($command->id)->lockForUpdate()->firstOrFail();
            $this->authorizeOperator($r, $c);
            $case = OperationalCase::where('command_id', $c->id)->lockForUpdate()->firstOrFail();
            $hasProof = DB::table('command_source_observations')->where('command_id', $c->id)->exists();
            abort_if($v['state'] === 'resolved' && ! $hasProof, 409, 'No applied source evidence; manual resolution cannot release charging capacity.');
            $case->update(['state' => $v['state'], 'operator_note' => $v['note'], 'reviewed_by' => $r->user()->id]);
            $this->event($case, $r->user()->id, $v['state'], $v['note']);

            return response()->json(['case' => $case, 'command_resent' => false, 'capacity_release_forced' => false]);
        }, 3);
    }

    public function inspect(Request $r, ChargerCommand $command, SourceConsumer $consumer, CommandReconciler $reconciler)
    {
        $this->authorizeOperator($r, $command);
        $case = OperationalCase::where('command_id', $command->id)->firstOrFail();
        $link = SessionLink::where('charging_session_id', $command->payload['session_id'] ?? 0)->firstOrFail();
        abort_unless(in_array($command->id, [$link->start_command_id, $link->stop_command_id], true), 409);
        abort_unless(DB::table('csms_bindings')->where('id', $link->binding_id)->where('station_id', $command->station_id)->exists(), 409);
        // No command/case lock is held across authenticated source network reads.
        $events = $consumer->consume($link, true);
        $reconciled = $reconciler->forSession($link->charging_session_id);
        $hasProof = DB::table('command_source_observations')->where('command_id', $command->id)->exists();
        DB::transaction(function () use ($case, $r, $hasProof) {
            $current = OperationalCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'resolved') {
                $current->update(['state' => $hasProof ? 'source_observed' : 'awaiting_source']);
            }
            $this->event($current, $r->user()->id, 'source_inspected', $hasProof ? 'Applied source evidence is present.' : 'No applied source evidence; command remains quarantined.');
        });

        return response()->json(['events_received' => $events, 'reconciled' => $reconciled, 'source_observed' => $hasProof, 'command_resent' => false, 'capacity_release_forced' => false, 'physical_charging_verified' => false]);
    }

    private function event(OperationalCase $case, int $actor, string $action, string $note): void
    {
        DB::table('charging_operational_case_events')->insert(['case_id' => $case->id, 'actor_id' => $actor, 'action' => $action, 'note' => Crypt::encryptString($note), 'created_at' => now()]);
    }
}
