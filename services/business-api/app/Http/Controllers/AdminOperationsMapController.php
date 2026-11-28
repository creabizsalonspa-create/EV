<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\User;
use App\Services\Charging\ConnectorFreshness;
use App\Services\Discovery\AdminOperationsPartnerLayer;
use App\Services\Discovery\CatalogTextFilters;
use App\Services\Discovery\SourceReportFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Private, bounded inventory overview. A map pin never grants charger authority. */
final class AdminOperationsMapController extends Controller
{
    public function index(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $snapshot = $request->user();
            $actor = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->role === 'admin' && $actor->account_status === 'active', 403);
            abort_unless($actor->role === $snapshot->role && (int) $actor->auth_epoch === (int) $snapshot->auth_epoch
                && hash_equals((string) $actor->password, (string) $snapshot->password), 409, 'Account credentials changed; authenticate again.');
            $partner = $request->input('scope') === 'partner';
            $v = $request->validate([
                'scope' => 'nullable|string|in:platform,partner',
                'q' => 'nullable|string|max:80',
                'country' => ['nullable', 'string', $partner ? 'regex:/^[A-Z]{3}$/D' : 'regex:/^[A-Z]{2}$/D'],
                'company_id' => ['nullable', 'integer', 'between:1,9007199254740991', Rule::prohibitedIf($partner)],
                'recent_source_status' => ['nullable', Rule::in(ConnectorFreshness::STATUSES), Rule::prohibitedIf($partner)],
                'south' => 'required_with:north,west,east|numeric|between:-90,90',
                'north' => 'required_with:south,west,east|numeric|between:-90,90|gte:south',
                'west' => 'required_with:south,north,east|numeric|between:-180,180',
                'east' => 'required_with:south,north,west|numeric|between:-180,180',
                'page' => 'nullable|integer|between:1,100000',
            ]);
            $checkedAt = now('UTC');
            $page = $partner ? app(AdminOperationsPartnerLayer::class)->page($v, $checkedAt) : $this->platformPage($v);

            // Keep only the bounded scope in audit. Never include search text,
            // coordinates, names or credentials.
            $scope = isset($v['company_id']) ? 'company:'.$v['company_id']
                : (isset($v['country']) ? 'country:'.$v['country'] : 'all');
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id,
                'action' => 'admin.private_list.operations_map_viewed',
                'subject' => $partner ? 'partner:'.$scope : $scope,
                'created_at' => now(),
            ]);

            return response()->json($page + [
                'as_of' => $checkedAt->toISOString(), 'coordinate_scope' => 'valid_coordinates_only',
                'recent_source_status' => $v['recent_source_status'] ?? null,
                'source_max_age_seconds' => $partner ? null : 300,
                'scope' => $partner ? 'published_partner_catalog' : 'platform_inventory_all_companies',
                'charging_authority_granted' => false, 'payment_authority_granted' => false,
            ])->header('Cache-Control', 'private, no-store');
        });
    }

    private function platformPage(array $v): array
    {
        $query = Station::query()->leftJoin('vendor_sites as site', 'site.station_id', '=', 'stations.id')
            ->leftJoin('vendor_companies as company', fn ($join) => $join->on('company.id', '=', 'site.company_id')->on('company.owner_id', '=', 'stations.vendor_id'))
            ->whereBetween('stations.latitude', [-90, 90])->whereBetween('stations.longitude', [-180, 180]);
        app(CatalogTextFilters::class)->apply($query, $v);
        if (! empty($v['country'])) {
            $query->where('stations.country', $v['country']);
        }
        if (! empty($v['company_id'])) {
            $query->where('company.id', $v['company_id']);
        }
        if (isset($v['south'])) {
            $query->whereBetween('stations.latitude', [$v['south'], $v['north']]);
            if ($v['west'] <= $v['east']) {
                $query->whereBetween('stations.longitude', [$v['west'], $v['east']]);
            } else {
                $query->where(fn ($q) => $q->where('stations.longitude', '>=', $v['west'])->orWhere('stations.longitude', '<=', $v['east']));
            }
        }
        app(SourceReportFilter::class)->apply($query, $v);

        return $query->select(['stations.id', 'stations.name', 'stations.country', 'stations.timezone', 'stations.latitude', 'stations.longitude', 'stations.source',
            'company.id as company_id', 'company.legal_name as company_name', 'company.status as company_status'])
            ->selectRaw('(SELECT COUNT(*) FROM vendor_chargers AS charger WHERE charger.site_id = site.id AND charger.company_id = company.id) AS charger_count')
            ->selectRaw('(SELECT COUNT(*) FROM charging_connector_mappings AS mapping WHERE mapping.station_id = stations.id) AS mapped_connector_count')
            ->orderByDesc('stations.id')->paginate(25)->through(fn ($row) => [
                'id' => $row->id, 'name' => $row->name, 'country' => $row->country, 'timezone' => $row->timezone,
                'latitude' => (float) $row->latitude, 'longitude' => (float) $row->longitude, 'source' => $row->source,
                'company' => $row->company_id === null ? null : ['id' => (int) $row->company_id, 'name' => $row->company_name, 'review_status' => $row->company_status],
                'charger_count' => (int) $row->charger_count, 'mapped_connector_count' => (int) $row->mapped_connector_count,
                'availability' => 'unknown', 'physical_availability_verified' => false, 'remote_control' => false,
            ])->toArray();
    }
}
