<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\Station;
use App\Models\User;
use App\Services\Discovery\CatalogPowerFilter;
use App\Services\Discovery\CatalogPriceFilter;
use App\Services\Discovery\CatalogTextFilters;
use App\Services\Discovery\DiscoveryViewport;
use App\Services\Discovery\PartnerCatalogAge;
use App\Services\Discovery\PartnerCatalogText;
use App\Services\Discovery\PartnerCatalogTime;
use App\Services\Discovery\PublicPartnerLocation;
use App\Services\Discovery\SourceReportFilter;
use App\Services\Discovery\StationCatalog;
use App\Services\Discovery\UnifiedCountryFilter;
use App\Services\Discovery\UnifiedRadiusFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One database-paginated search over already-published inventory; no provider calls. */
final class UnifiedDiscoveryController extends Controller
{
    public function index(Request $request)
    {
        $v = $request->validate(['q' => 'nullable|string|max:80', 'operator' => 'nullable|string|max:100', 'address' => 'nullable|string|max:150', 'access' => 'nullable|string|max:150', 'max_rate_per_kwh' => ['nullable', 'required_with:price_currency', 'string', 'regex:/^[0-9]{1,6}(\.[0-9]{1,3})?$/D'], 'price_currency' => 'nullable|required_with:max_rate_per_kwh|string|size:3|uppercase', 'scope' => 'nullable|in:all,catalog,partner', 'country' => 'nullable|in:AE,CA,US', 'min_power_kw' => 'nullable|integer|between:1,1000', 'max_power_kw' => 'nullable|integer|between:1,1000',
            'latitude' => 'nullable|required_with:longitude,radius_km|numeric|between:-90,90',
            'longitude' => 'nullable|required_with:latitude,radius_km|numeric|between:-180,180',
            'radius_km' => 'nullable|required_with:latitude,longitude|numeric|between:1,200',
            'south' => 'nullable|required_with:north,west,east|numeric|between:-90,90',
            'north' => 'nullable|required_with:south,west,east|numeric|between:-90,90',
            'west' => 'nullable|required_with:south,north,east|numeric|between:-180,180',
            'east' => 'nullable|required_with:south,north,west|numeric|between:-180,180', 'connector' => 'nullable|in:CCS2,Type2,CHAdeMO', 'page' => 'nullable|integer|between:1,1000000', 'saved_only' => 'nullable|boolean', 'max_source_age_hours' => 'nullable|integer|between:1,168', 'recent_source_status' => 'nullable|in:Available,Occupied,Preparing,Charging,SuspendedEV,SuspendedEVSE,Finishing,Reserved,Unavailable,Faulted']);
        $checkedAt = now('UTC');
        $actor = $this->currentActor($request);
        $customer = $actor->role === 'customer' ? $actor->id : null;
        abort_if($request->boolean('saved_only') && $customer === null, 403);
        $catalog = Station::query();
        $partner = OcpiRecord::query()->where('module', 'locations')->explicitlyPublished()
            ->whereIn('ocpi_provider_id', OcpiProvider::publiclyDiscoverable()->select('id'));
        // Explicit market filter uses the charging location's country,
        // never the OCPI partner party's country code.
        if (! empty($v['country'])) {
            UnifiedCountryFilter::apply($catalog, $partner, $v['country']);
        }
        // Viewport bounds are opt-in and evaluated on both sources in SQL
        // before the union and pagination, including date-line crossings.
        if (isset($v['south'], $v['north'], $v['west'], $v['east'])) {
            if ((float) $v['south'] >= (float) $v['north']) {
                throw ValidationException::withMessages(['north' => 'North must be greater than south.']);
            }
            if ((float) $v['west'] === (float) $v['east']) {
                throw ValidationException::withMessages(['east' => 'East and west must differ.']);
            }
            app(DiscoveryViewport::class)->apply($catalog, $partner, $v);
        }
        // A consented radius is a local great-circle predicate on both
        // published sources, before pagination. It never contacts a provider.
        if (isset($v['latitude'], $v['longitude'], $v['radius_km'])) {
            app(UnifiedRadiusFilter::class)->apply($catalog, $partner, $v);
        }
        // Only canonical published station power is comparable: no partner
        // crowd-reported wattage is promoted to verified charger capability.
        if (isset($v['min_power_kw'], $v['max_power_kw'])
            && (int) $v['min_power_kw'] > (int) $v['max_power_kw']) {
            throw ValidationException::withMessages(['max_power_kw' => 'Maximum rated power must be at least minimum rated power.']);
        }
        app(CatalogPowerFilter::class)->apply($catalog, $v);
        if (isset($v['min_power_kw']) || isset($v['max_power_kw'])) {
            $partner->whereRaw('1 = 0');
        }
        // Apply individual filters before the SQL union and pagination.
        // Explicit access and price filters require canonical platform evidence.
        app(CatalogTextFilters::class)->apply($catalog, array_intersect_key($v, array_flip(['operator', 'address', 'access'])));
        app(CatalogPriceFilter::class)->apply($catalog, $v);
        if (trim($v['access'] ?? '') !== '' || isset($v['max_rate_per_kwh'])) {
            $partner->whereRaw('1 = 0');
        }
        foreach (['operator', 'address'] as $field) {
            $value = trim($v[$field] ?? '');
            if ($value === '') {
                continue;
            }
            $driver = DB::connection()->getDriverName();
            $comparison = match ($driver) {
                'pgsql' => 'ILIKE', 'sqlite' => 'LIKE',
                default => throw new \LogicException('Unsupported discovery database.'),
            };
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
            if ($field === 'operator') {
                $partner->whereIn('ocpi_provider_id', OcpiProvider::publiclyDiscoverable()->whereRaw("name $comparison ? ESCAPE '!'", [$pattern])->select('id'));
            } else {
                $partner->where(function ($query) use ($comparison, $pattern) {
                    foreach (['address', 'city'] as $key) {
                        $column = PartnerCatalogText::field($key);
                        $query->orWhereRaw("$column $comparison ? ESCAPE '!'", [$pattern]);
                    }
                });
            }
        }
        if (isset($v['max_source_age_hours'])) {
            // Partner source timestamps only: never a receipt-age or availability claim.
            PartnerCatalogTime::within($partner, $checkedAt, (int) $v['max_source_age_hours']);
        }
        if ($request->boolean('saved_only')) {
            $catalog->whereIn('id', DB::table('station_favorites')->where('user_id', $customer)->select('station_id'));
            $partner->whereIn('id', DB::table('partner_location_favorites')->where('user_id', $customer)->select('record_id'));
        }
        if (! empty($v['recent_source_status'])) {
            app(SourceReportFilter::class)->apply($catalog, [...$v, ...(! empty($v['connector']) ? ['connector_type' => $v['connector']] : [])]);
            $partner->whereRaw('1 = 0'); // Partner reports cannot fulfill canonical five-minute source evidence.
        }
        $text = trim($v['q'] ?? '');
        if ($text !== '') {
            $catalog->where(function ($query) use ($text) {
                foreach (['q', 'address', 'operator'] as $field) {
                    $query->orWhere(fn ($match) => app(CatalogTextFilters::class)->apply($match, [$field => $text]));
                }
            });
            $driver = DB::connection()->getDriverName();
            $operator = match ($driver) {
                'pgsql' => 'ILIKE', 'sqlite' => 'LIKE',
                default => throw new \LogicException('Unsupported discovery database.'),
            };
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
            $partner->where(function ($query) use ($operator, $pattern) {
                foreach (['name', 'address', 'city'] as $field) {
                    $column = PartnerCatalogText::field($field);
                    $query->orWhereRaw("$column $operator ? ESCAPE '!'", [$pattern]);
                }
                $query->orWhereIn('ocpi_provider_id', OcpiProvider::publiclyDiscoverable()->whereRaw("name $operator ? ESCAPE '!'", [$pattern])->select('id'));
            });
        }
        if (! empty($v['connector'])) {
            $catalog->where(function ($q) use ($v) {
                $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('charging_connector_mappings as m')
                    ->join('vendor_connectors as c', 'c.id', '=', 'm.connector_id')->whereColumn('m.station_id', 'stations.id')->where('c.connector_type', $v['connector']))
                    ->orWhere(fn ($legacy) => $legacy->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('charging_connector_mappings as m')->whereColumn('m.station_id', 'stations.id'))->where('connector_type', $v['connector']));
            });
            $standards = ['CCS2' => 'IEC_62196_T2_COMBO', 'Type2' => 'IEC_62196_T2', 'CHAdeMO' => 'CHADEMO'];
            PartnerCatalogText::standard($partner, $standards[$v['connector']]);
        }
        if (($v['scope'] ?? 'all') === 'catalog') {
            $partner->whereRaw('1 = 0');
        } elseif (($v['scope'] ?? 'all') === 'partner') {
            $catalog->whereRaw('1 = 0');
        }
        // Stable typed identity prevents equal local IDs from collapsing suppliers.
        // Pagination happens before hydration; no provider-sized arrays are loaded.
        $union = $catalog->selectRaw("id, 'catalog' AS kind")->toBase()
            ->unionAll($partner->selectRaw("id, 'partner' AS kind")->toBase());
        $page = DB::query()->fromSub($union, 'discovery')->orderByDesc('id')->orderBy('kind')->paginate(25)->appends($v);
        $rows = $page->getCollection();
        $stations = app(StationCatalog::class)->present(Station::whereIn('id', $rows->where('kind', 'catalog')->pluck('id'))->get(), $customer)->keyBy('id');
        $partners = OcpiRecord::whereIn('id', $rows->where('kind', 'partner')->pluck('id'))->where('module', 'locations')->explicitlyPublished()
            ->whereIn('ocpi_provider_id', OcpiProvider::publiclyDiscoverable()->select('id'))
            ->get(['id', 'ocpi_provider_id', 'normalized', 'source_updated_at', 'received_at'])->keyBy('id');
        $labels = OcpiProvider::publiclyDiscoverable()->whereIn('id', $partners->pluck('ocpi_provider_id'))->pluck('name', 'id');
        $savedPartners = $customer === null ? collect() : DB::table('partner_location_favorites')->where('user_id', $customer)->whereIn('record_id', $partners->keys())->pluck('record_id')->flip();
        $this->currentActor($request);
        $page->setCollection($rows->map(function ($row) use ($stations, $partners, $labels, $savedPartners, $checkedAt) {
            if ($row->kind === 'catalog') {
                $station = $stations->get($row->id);

                return $station ? ['key' => 'catalog:'.$row->id, 'kind' => 'catalog', 'is_favorite' => $station['is_favorite'] ?? false, 'station' => $station] : null;
            }
            $record = $partners->get($row->id);
            if (! $record || ! $labels->has($record->ocpi_provider_id)) {
                return null;
            }

            return ['key' => 'partner:'.$row->id, 'kind' => 'partner', 'record_id' => $row->id, 'is_favorite' => $savedPartners->has($row->id),
                'provider_label' => $labels->get($record->ocpi_provider_id), 'provider_label_evidence' => 'PROVIDER_CONFIGURED_UNVERIFIED',
                'location' => PublicPartnerLocation::project($record->normalized ?? []),
                ...PartnerCatalogAge::present($record, $checkedAt),
                'evidence' => 'PARTNER_REPORTED_UNVERIFIED', 'availability' => 'unknown', 'reservable' => false, 'remote_control' => false];
        })->filter()->values());

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    private function currentActor(Request $request): User
    {
        $snapshot = $request->user();
        $current = User::findOrFail($snapshot->id);
        abort_unless($current->account_status === 'active' && $current->role === $snapshot->role
            && (int) $current->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $current->password, (string) $snapshot->password), 409, 'Account authorization changed. Sign in again.');

        return $current;
    }
}
