<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\AvailabilityCommand;
use App\Services\Charging\AvailabilitySource;
use App\Services\Charging\FaultMonitor;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

final class ChargerAvailabilityController extends Controller
{
    private const TABLE = 'charger_availability_operations';

    public function index(Request $request, VendorCompany $company, VendorCharger $charger, AvailabilitySource $source)
    {
        $binding = $this->scope($request, $company, $charger);
        $operations = DB::table(self::TABLE)->where('binding_id', $binding->id)->latest('id')->paginate(25)
            ->through(fn ($row) => $this->present($row, $binding))->toArray();

        $canRequest = false;
        try {
            $source->enabled(true);
            $this->scope($request, $company, $charger, true);
            $canRequest = true;
        } catch (Throwable) {
            // Protected enrollment and adapter configuration remain private.
        }

        $connectors = $canRequest ? $this->mapping($binding)->orderBy('c.id')->get(['c.id', 'c.connector_number']) : [];

        return response()->json($operations + ['can_request' => $canRequest, 'connectors' => $connectors])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, VendorCompany $company, VendorCharger $charger, AvailabilitySource $source)
    {
        $binding = $this->scope($request, $company, $charger, true);
        $v = $request->validate([
            'requested_state' => 'required|in:Operative,Inoperative',
            'connector_id' => 'nullable|integer|min:1',
            'reason' => 'required|string|min:10|max:500',
            'idempotency_key' => 'required|string|max:100',
        ]);
        $connector = isset($v['connector_id']) ? (int) $v['connector_id'] : null;
        $hash = $this->hash([$charger->id, $connector, $v['requested_state'], $v['reason']]);
        $actor = $request->user()->id;
        [$operation, $created] = DB::transaction(function () use ($request, $company, $charger, $source, $binding, $connector, $v, $hash, $actor) {
            $current = $this->locked($request, $company, $charger, $binding, true);
            $old = DB::table(self::TABLE)->where('actor_id', $actor)->where('idempotency_key', $v['idempotency_key'])->first();
            if ($old) {
                abort_unless($old->binding_id === $current->id && hash_equals($old->request_hash, $hash), 409);

                return [$old, false];
            }
            $source->enabled(true);
            abort_if(DB::table(self::TABLE)->where('binding_id', $current->id)->whereIn('status', ['pending', 'dispatching', 'transport_submitted', 'outcome_unknown'])
                ->where('created_at', '>=', now()->subMinute())->exists(), 409, 'Wait for the preceding command to settle before requesting another operation.');
            $payload = $this->payload($current, $v['requested_state'], $connector);
            $id = DB::table(self::TABLE)->insertGetId([
                'binding_id' => $current->id, 'actor_id' => $actor, 'idempotency_key' => $v['idempotency_key'], 'request_hash' => $hash,
                'source_generation' => FaultMonitor::generation($current), 'connector_id' => $connector, 'requested_state' => $v['requested_state'],
                'source_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'reason' => Crypt::encryptString($v['reason']),
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, $id, 'requested');

            return [DB::table(self::TABLE)->find($id), true];
        }, 3);

        if ($created && DB::table(self::TABLE)->where('id', $operation->id)->where('status', 'pending')
            ->update(['status' => 'dispatching', 'updated_at' => now()])) {
            $this->dispatch($request, $company, $charger, $source, $binding, $operation);
        }
        $current = $this->scope($request, $company, $charger);

        return response()->json($this->present(DB::table(self::TABLE)->find($operation->id), $current), $created ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    private function dispatch(Request $request, VendorCompany $company, VendorCharger $charger, AvailabilitySource $source, object $binding, object $operation): void
    {
        $attempt = null;
        $state = 'not_dispatched';
        try {
            $authority = $source->authority(true);
            $station = $source->station($binding);
            $source->requireAuthority($authority, true);
            $credential = $source->dispatchCredential($authority);
            $source->requireAuthority($authority, true);
            $watermark = $source->watermark($station);
            $source->requireAuthority($authority, true);
            abort_unless($source->station($binding) === $station, 409);
            $source->requireAuthority($authority, true);
            $prepared = DB::table(self::TABLE)->where('id', $operation->id)->where('status', 'dispatching')->whereNull('attempted_at')->update([
                'source_station_id' => $station, 'source_watermark' => $watermark,
                'attempted_at' => now()->utc()->format('Y-m-d H:i:s.u'), 'updated_at' => now(),
            ]);
            abort_unless($prepared === 1, 409);
            DB::transaction(function () use ($request, $company, $charger, $source, $binding, $operation, $credential, $authority, &$attempt, &$state) {
                $current = $this->locked($request, $company, $charger, $binding, true);
                $source->requireAuthority($authority, true);
                abort_unless(hash_equals($operation->source_generation, FaultMonitor::generation($current)), 409);
                $payload = $this->payload($current, $operation->requested_state, $operation->connector_id);
                abort_unless($payload === json_decode($operation->source_payload, true, flags: JSON_THROW_ON_ERROR), 409);
                $row = DB::table(self::TABLE)->where('id', $operation->id)->lockForUpdate()->firstOrFail();
                abort_unless($row->status === 'dispatching' && $row->attempted_at !== null && now()->subSeconds(25)->lt($row->created_at), 409);
                $state = $source->submit($current, function () use ($credential, $row, &$attempt): string {
                    $token = $credential();
                    $attempt = $row->attempted_at;

                    return $token;
                }, $payload, $authority) ? 'transport_submitted' : 'outcome_unknown';
            });
        } catch (Throwable) {
            $state = $attempt === null ? 'not_dispatched' : 'outcome_unknown';
        }
        DB::transaction(function () use ($operation, $state) {
            DB::table(self::TABLE)->where('id', $operation->id)->where('status', 'dispatching')->update(
                ['status' => $state, 'updated_at' => now()] + ($state === 'not_dispatched'
                    ? ['attempted_at' => null, 'source_station_id' => null, 'source_watermark' => null] : []),
            );
            $this->audit($operation->actor_id, $operation->id, $state);
        }, 3);
    }

    public function observe(Request $request, VendorCompany $company, VendorCharger $charger, int $operation, AvailabilitySource $source)
    {
        $binding = $this->scope($request, $company, $charger);
        $row = DB::table(self::TABLE)->where('id', $operation)->where('binding_id', $binding->id)->firstOrFail();
        if ($this->canObserve($row, $binding)) {
            $authority = $source->authority();
            $station = $source->station($binding, false);
            $source->requireAuthority($authority);
            $evidence = $station === $row->source_station_id ? $source->confirmation($row, $authority) : null;
            if ($evidence !== null) {
                $finalStation = $source->station($binding, false);
                $source->requireAuthority($authority);
                if ($finalStation !== $row->source_station_id) {
                    $evidence = null;
                }
            }
            if ($evidence !== null) {
                DB::transaction(function () use ($request, $company, $charger, $binding, $row, $evidence, $source, $authority) {
                    $source->requireAuthority($authority);
                    $current = $this->locked($request, $company, $charger, $binding, false);
                    $latest = DB::table(self::TABLE)->where('id', $row->id)->lockForUpdate()->firstOrFail();
                    $source->requireAuthority($authority);
                    if (! $this->canObserve($latest, $current) || $latest->attempted_at !== $row->attempted_at
                        || $latest->source_station_id !== $row->source_station_id || $latest->source_watermark !== $row->source_watermark) {
                        return;
                    }
                    $updated = DB::table(self::TABLE)->where('id', $row->id)->whereNull('observed_at')->update($evidence + ['updated_at' => now()]);
                    if ($updated) {
                        $this->audit($request->user()->id, $row->id, 'source_observed');
                    }
                }, 3);
            }
        }
        $current = $this->scope($request, $company, $charger);

        return response()->json($this->present(DB::table(self::TABLE)->find($row->id), $current))->header('Cache-Control', 'private, no-store');
    }

    private function scope(Request $request, VendorCompany $company, VendorCharger $charger, bool $write = false): object
    {
        // Keep the captured actor so a changed account epoch fails the maintenance gate.
        $company = $company->fresh();
        $binding = app(ChargerResetController::class)->scope($request, $company, $charger->fresh(), $write);
        if ($write) {
            app(VendorAccess::class)->operational($company);
        }

        // Mobile logout revokes the individual token without changing the account
        // epoch. Recheck it after source I/O before persisting an observation.
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $query = $token->newQuery()->whereKey($token->getKey());
            $current = DB::transactionLevel() > 0 ? $query->lockForUpdate()->first() : $query->first();
            $expiration = config('sanctum.expiration');
            abort_unless($current && (! $current->expires_at || $current->expires_at->isFuture())
                && (! $expiration || $current->created_at->gt(now()->subMinutes($expiration))), 403);
        }

        return $binding;
    }

    private function locked(Request $request, VendorCompany $company, VendorCharger $charger, object $original, bool $write): object
    {
        Station::whereKey($original->station_id)->lockForUpdate()->firstOrFail();
        VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
        VendorCharger::whereKey($charger->id)->lockForUpdate()->firstOrFail();
        $current = $this->scope($request, $company, $charger, $write);
        $binding = DB::table('csms_bindings')->where('id', $current->id)->lockForUpdate()->firstOrFail();
        abort_unless(hash_equals(FaultMonitor::generation($original), FaultMonitor::generation($binding)), 409);

        return $binding;
    }

    private function mapping(object $binding)
    {
        return DB::table('charging_connector_mappings as m')
            ->join('vendor_connectors as c', 'c.id', '=', 'm.connector_id')
            ->join('charging_evses as e', 'e.id', '=', 'm.evse_id')
            ->where('m.station_id', $binding->station_id)->where('c.charger_id', $binding->vendor_charger_id)
            ->where('e.charger_id', $binding->vendor_charger_id)->where('e.binding_id', $binding->id)
            ->where('e.mode', 'csms')->where('m.source_connector_number', '>', 0)
            ->where('e.source_evse_number', $binding->protocol === '1.6' ? '=' : '>', 0);
    }

    private function payload(object $binding, string $state, ?int $connector): array
    {
        if ($connector === null) {
            return AvailabilityCommand::payload($binding->protocol, $state);
        }
        $mapping = $this->mapping($binding)->where('c.id', $connector)->first(['e.source_evse_number', 'm.source_connector_number', 'm.source_target_key']);
        abort_unless($mapping && hash_equals(
            $this->targetKey($binding->id, $mapping->source_evse_number, $mapping->source_connector_number),
            (string) $mapping->source_target_key,
        ), 404, 'Connector has no canonical source target.');

        return AvailabilityCommand::payload($binding->protocol, $state, $binding->protocol === '1.6' ? null : (int) $mapping->source_evse_number, (int) $mapping->source_connector_number);
    }

    private function targetKey(int $binding, int $evse, int $connector): string
    {
        return hash('sha256', $binding.':'.$evse.':'.$connector);
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function audit(int $actor, int $operation, string $event): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => 'charger.availability_'.$event, 'subject' => (string) $operation, 'created_at' => now()]);
    }

    private function canObserve(object $row, object $binding): bool
    {
        $settled = in_array($row->status, ['transport_submitted', 'outcome_unknown'], true)
            || ($row->status === 'dispatching' && now()->subMinute()->gte($row->created_at));

        return $settled && $row->attempted_at !== null && $row->observed_at === null
            && hash_equals($row->source_generation, FaultMonitor::generation($binding));
    }

    private function present(object $row, object $binding): array
    {
        $status = $row->status;
        if (in_array($status, ['pending', 'dispatching', 'transport_submitted'], true) && now()->subMinute()->gte($row->created_at)) {
            $status = 'outcome_unknown';
        }

        return [
            'id' => $row->id, 'connector_id' => $row->connector_id, 'requested_state' => $row->requested_state,
            'status' => $status, 'created_at' => $row->created_at, 'observed_at' => $row->observed_at,
            'can_observe' => $this->canObserve($row, $binding),
            'evidence' => 'SIMULATED_SOURCE', 'physical_availability_verified' => false,
        ];
    }
}
