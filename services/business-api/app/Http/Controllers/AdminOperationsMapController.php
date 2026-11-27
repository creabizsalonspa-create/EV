<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\ConnectorFreshness;
use App\Services\Discovery\CatalogTextFilters;
use App\Services\Discovery\DiscoveryViewport;
use App\Services\Discovery\PartnerCatalogAge;
use App\Services\Discovery\PublicPartnerLocation;
use App\Services\Discovery\SourceReportFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            $partner = $request->input('catalog') === 'partner';
            $v = $request->validate([
                'catalog' => 'nullable|in:platform,partner',
                'q' => 'nullable|string|max:80', 'country' => ['nullable', $partner ? 'regex:/^[A-Z]{3}$/D' : 'regex:/^[A-Z]{2}$/D'],
                'company_id' => 'nullable|integer|between:1,9007199254740991',
                'recent_source_status' => 'nullable|in:'.implode(',', ConnectorFreshness::STATUSES),
                'south' => 'required_with:north,west,east|numeric|between:-90,90',
                'north' => 'required_with:south,west,east|numeric|between:-90,90|gte:south',
                'west' => 'required_with:south,north,east|numeric|between:-180,180',
                'east' => 'required_with:south,north,west|numeric|between:-180,180',
                'page' => 'nullable|integer|between:1,100000',
            ]);
            if ($partner) {
                abort_if(isset($v['company_id']) || isset($v['recent_source_status']), 422, 'Platform-only filters are not applicable to partner reports.');

                return $this->partner($actor, $v);
            }
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
            $page = $query->select(['stations.id', 'stations.name', 'stations.country', 'stations.timezone', 'stations.latitude', 'stations.longitude', 'stations.source',
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

            // This cross-company, private map is a deliberate Admin review.
            // Keep only its bounded scope in audit, never coordinates,
            // search text, vendor names, map contents or credentials.
            $scope = isset($v['company_id']) ? 'company:'.$v['company_id']
                : (isset($v['country']) ? 'country:'.$v['country'] : 'all');
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id,
                'action' => 'admin.private_list.operations_map_viewed',
                'subject' => $scope,
                'created_at' => now(),
            ]);

            return response()->json($page + ['as_of' => now()->toISOString(), 'coordinate_scope' => 'valid_coordinates_only',
                'recent_source_status' => $v['recent_source_status'] ?? null, 'source_max_age_seconds' => 300,
                'scope' => 'platform_inventory_all_companies', 'charging_authority_granted' => false, 'payment_authority_granted' => false])
                ->header('Cache-Control', 'private, no-store');
        });
    }

    private function partner(User $actor, array $filters)
    {
        $query = OcpiRecord::query()->where('module', 'locations')
            ->where('normalized->publish', true)
            ->whereIn('ocpi_provider_id', OcpiProvider::publiclyDiscoverable()->select('id'))
            ->when(! empty($filters['country']), fn ($q) => $q->where('normalized->country', $filters['country']));
        if (! empty($filters['q'])) {
            $driver = DB::connection()->getDriverName();
            $operator = match ($driver) {
                'pgsql' => 'ILIKE',
                'sqlite' => 'LIKE',
                default => throw new \LogicException('Unsupported partner map database.'),
            };
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])).'%';
            $query->where(function ($match) use ($driver, $operator, $pattern) {
                foreach (['name', 'city', 'address'] as $field) {
                    $column = $driver === 'pgsql'
                        ? "(ocpi_records.normalized->>'".$field."')"
                        : "json_extract(ocpi_records.normalized, '$.".$field."')";
                    $match->orWhereRaw("$column $operator ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        $bounds = isset($filters['south']) ? $filters : ['south' => -90, 'north' => 90, 'west' => -180, 'east' => 180];
        abort_if((float) $bounds['west'] === (float) $bounds['east'], 422, 'A nonempty longitude range is required.');
        // Reuse the safe JSON coordinate predicate; the platform builder is never executed.
        app(DiscoveryViewport::class)->apply(Station::query(), $query, $bounds);
        $checkedAt = now();
        $page = $query->select('ocpi_records.*')->addSelect([
            'provider_label' => OcpiProvider::publiclyDiscoverable()->select('name')
                ->whereColumn('ocpi_providers.id', 'ocpi_records.ocpi_provider_id')->limit(1),
        ])->orderByDesc('ocpi_records.id')->paginate(25)->through(function (OcpiRecord $row) use ($checkedAt) {
            $location = PublicPartnerLocation::project($row->normalized ?? []);
            $age = PartnerCatalogAge::present($row, $checkedAt);

            return [
                'id' => $row->id, 'provider_id' => $row->ocpi_provider_id, 'provider_label' => $row->provider_label,
                'name' => $location['name'] ?? $location['id'] ?? 'Unnamed partner location',
                'country' => $location['country'] ?? null,
                'latitude' => (float) $location['latitude'], 'longitude' => (float) $location['longitude'],
                'source' => 'PARTNER_REPORTED_UNVERIFIED', 'reported_source_age_minutes' => $age['reported_source_age_minutes'],
                'availability' => 'unknown', 'physical_availability_verified' => false, 'remote_control' => false,
            ];
        })->toArray();

        DB::table('audit_events')->insert([
            'actor_id' => $actor->id, 'action' => 'admin.private_list.operations_map_viewed',
            'subject' => isset($filters['country']) ? 'partner:country:'.$filters['country'] : 'partner:all',
            'created_at' => now(),
        ]);

        return response()->json($page + [
            'as_of' => $checkedAt->toISOString(), 'coordinate_scope' => 'valid_coordinates_only',
            'scope' => 'authorized_partner_catalog', 'charging_authority_granted' => false, 'payment_authority_granted' => false,
        ])->header('Cache-Control', 'private, no-store');
    }
}
