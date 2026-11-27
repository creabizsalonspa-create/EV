<?php

namespace App\Http\Controllers;

use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Services\Charging\RemoteSessionAuthorization;
use App\Services\Charging\SourceStatusTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Private, immutable review metadata. Never creates charging topology or capacity. */
final class DirectConnectorReviewController extends Controller
{
    private function fingerprint(object $binding): string
    {
        return hash('sha256', json_encode([$binding->id, $binding->station_id, $binding->vendor_charger_id,
            $binding->identifier, $binding->protocol, $binding->registration_receipt], JSON_THROW_ON_ERROR));
    }

    private function present(object $row, string $fingerprint): array
    {
        return ['id' => $row->id, 'connector_id' => $row->connector_id,
            'source_evse_number' => $row->source_evse_number, 'source_connector_number' => $row->source_connector_number,
            'current_registration_matches' => hash_equals($row->registration_fingerprint, $fingerprint),
            'source_observed_at' => $row->source_observed_at, 'reviewed_at' => $row->reviewed_at,
            'evidence' => 'DIRECT_CONNECTOR_REVIEW_ONLY', 'charging_mapping_created' => false,
            'physical_availability_verified' => false, 'remote_control_authorized' => false];
    }

    public function index(Request $r, VendorCompany $company, VendorCharger $charger, ChargerLiveConnectorsController $live)
    {
        $filters = $r->validate(['page' => 'sometimes|integer|min:1|max:1000000',
            'connector_id' => 'sometimes|integer|min:1|max:9007199254740991']);

        return DB::transaction(function () use ($r, $company, $charger, $live, $filters) {
            $binding = $live->registeredScope($r, $company, $charger);
            $fingerprint = $this->fingerprint($binding);
            if (isset($filters['connector_id'])) {
                abort_unless(VendorConnector::whereKey($filters['connector_id'])->where('charger_id', $charger->id)
                    ->lockForUpdate()->first(), 404);
            }
            $page = DB::table('direct_connector_review_receipts')->where('charger_id', $charger->id)
                ->when(isset($filters['connector_id']), fn ($q) => $q->where('connector_id', $filters['connector_id']))
                ->orderBy('id')->paginate(25)->through(fn ($row) => $this->present($row, $fingerprint));

            return response()->json($page)->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function create(Request $r, VendorCompany $company, VendorCharger $charger, ChargerLiveConnectorsController $live,
        ChargerResetController $guard, RemoteSessionAuthorization $source)
    {
        abort_unless($r->user()->role === 'vendor' && $r->user()->id === $company->owner_id, 404);
        $v = $r->validate(['connector_id' => 'required|integer|min:1|max:9007199254740991',
            'source_evse_number' => 'required|integer|between:0,1000', 'source_connector_number' => 'required|integer|between:1,100']);
        $connectorId = (int) $v['connector_id'];
        $evse = (int) $v['source_evse_number'];
        $number = (int) $v['source_connector_number'];
        $binding = $live->registeredScope($r, $company, $charger);
        abort_unless($binding->protocol === '1.6' ? $evse === 0 : $evse > 0, 422);
        abort_unless(VendorConnector::whereKey($connectorId)->where('charger_id', $charger->id)->exists(), 404);
        $fingerprint = $this->fingerprint($binding);
        $authority = [config('csms.graphql_url'), config('csms.graphql_secret')];

        // Never trust a caller-supplied snapshot or perform network I/O inside
        // the write transaction. The same receipt and actor must survive it.
        $snapshot = $live->inspect($r, $company, $charger, $guard, $source, true)->getData(true);
        abort_unless($snapshot['evidence'] === 'REGISTERED_DIRECT_SOURCE'
            && $snapshot['source_topology_complete'] === true && $snapshot['source_identity_rechecked'] === true, 409);
        $matches = array_values(array_filter($snapshot['connectors'], fn ($row) =>
            $row['source_evse_number'] === $evse && $row['source_connector_number'] === $number && $row['state'] === 'recent'));
        abort_unless(count($matches) === 1, 409, 'A recent exact source connector observation is required.');
        $observed = SourceStatusTime::parse($matches[0]['source_event_at']);
        $sourceId = $matches[0]['source_connector_database_id'];
        $eventId = $matches[0]['source_event_id'];
        abort_unless(is_int($sourceId) && $sourceId > 0 && is_int($eventId) && $eventId > 0, 409);
        try {
            return DB::transaction(function () use ($r, $company, $charger, $live, $connectorId, $evse, $number, $fingerprint, $authority, $observed, $sourceId, $eventId) {
                $fresh = $live->registeredScope($r, $company, $charger);
                $currentCompany = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
                abort_unless($currentCompany->owner_id === $r->user()->id && $r->user()->role === 'vendor', 404);
                abort_unless(config('csms.registered_live_monitoring_enabled') === true
                    && [config('csms.graphql_url'), config('csms.graphql_secret')] === $authority
                    && hash_equals($fingerprint, $this->fingerprint($fresh))
                    && $observed->gte(now()->subMinutes(5)), 409, 'Source review authority changed.');
                abort_unless(VendorConnector::whereKey($connectorId)->where('charger_id', $charger->id)->lockForUpdate()->first(), 404);
                $old = DB::table('direct_connector_review_receipts')->where('connector_id', $connectorId)->first();
                if ($old) {
                    abort_unless($old->charger_id === $charger->id && $old->source_evse_number === $evse
                        && $old->source_connector_number === $number && $old->source_connector_database_id === $sourceId
                        && $eventId >= $old->source_event_id
                        && $observed->gte(CarbonImmutable::parse($old->source_observed_at, 'UTC'))
                        && hash_equals($old->registration_fingerprint, $fingerprint),
                        409, 'Existing connector review is immutable.');

                    return response()->json($this->present($old, $fingerprint))->header('Cache-Control', 'private, no-store');
                }
                abort_if(DB::table('direct_connector_review_receipts')->where('charger_id', $charger->id)
                    ->where('source_evse_number', $evse)->where('source_connector_number', $number)->exists(), 409, 'Source target already reviewed.');
                abort_if(DB::table('direct_connector_review_receipts')->where('registration_id', $fresh->registration_receipt[0])
                    ->where('source_connector_database_id', $sourceId)->exists(), 409, 'Source connector identity already reviewed.');
                $id = DB::table('direct_connector_review_receipts')->insertGetId([
                    'charger_id' => $charger->id, 'connector_id' => $connectorId,
                    'registration_id' => $fresh->registration_receipt[0], 'actor_id' => $r->user()->id,
                    'source_evse_number' => $evse, 'source_connector_number' => $number,
                    'source_connector_database_id' => $sourceId, 'source_event_id' => $eventId,
                    'registration_fingerprint' => $fingerprint, 'source_observed_at' => $observed->format('Y-m-d H:i:s.u'),
                    'reviewed_at' => now()->format('Y-m-d H:i:s.u'),
                ]);
                DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'charging.direct_connector_reviewed',
                    'subject' => (string) $id, 'created_at' => now()]);

                return response()->json($this->present(DB::table('direct_connector_review_receipts')->where('id', $id)->first(), $fingerprint), 201)
                    ->header('Cache-Control', 'private, no-store');
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Connector review already exists; refresh.');
        }
    }
}
