<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\Station;
use App\Services\Discovery\CatalogTextFilters;
use App\Services\Discovery\PublicPartnerLocation;
use App\Services\Discovery\StationCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** One database-paginated search over already-published inventory; no provider calls. */
final class UnifiedDiscoveryController extends Controller
{
    public function index(Request $request)
    {
        $v = $request->validate(['q' => 'nullable|string|max:80', 'scope' => 'nullable|in:all,catalog,partner', 'connector' => 'nullable|in:CCS2,Type2,CHAdeMO', 'page' => 'nullable|integer|between:1,1000000']);
        $catalog = Station::query();
        $partner = OcpiRecord::query()->where('module', 'locations')->where('normalized->publish', true)
            ->whereIn('ocpi_provider_id', OcpiProvider::where('authorized', true)->select('id'));
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
            $partner->where(function ($query) use ($driver, $operator, $pattern) {
                foreach (['name', 'address', 'city'] as $field) {
                    $column = $driver === 'pgsql' ? "(ocpi_records.normalized->>'$field')" : "json_extract(ocpi_records.normalized, '$.$field')";
                    $query->orWhereRaw("$column $operator ? ESCAPE '!'", [$pattern]);
                }
                $query->orWhereIn('ocpi_provider_id', OcpiProvider::where('authorized', true)->whereRaw("name $operator ? ESCAPE '!'", [$pattern])->select('id'));
            });
        }
        if (! empty($v['connector'])) {
            $catalog->where(function ($q) use ($v) {
                $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('charging_connector_mappings as m')
                    ->join('vendor_connectors as c', 'c.id', '=', 'm.connector_id')->whereColumn('m.station_id', 'stations.id')->where('c.connector_type', $v['connector']))
                    ->orWhere(fn ($legacy) => $legacy->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('charging_connector_mappings as m')->whereColumn('m.station_id', 'stations.id'))->where('connector_type', $v['connector']));
            });
            $standards = ['CCS2' => 'IEC_62196_T2_COMBO', 'Type2' => 'IEC_62196_T2', 'CHAdeMO' => 'CHADEMO'];
            $partner->whereJsonContains('normalized->connector_standards', $standards[$v['connector']]);
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
        $stations = app(StationCatalog::class)->present(Station::whereIn('id', $rows->where('kind', 'catalog')->pluck('id'))->get())->keyBy('id');
        $partners = OcpiRecord::whereIn('id', $rows->where('kind', 'partner')->pluck('id'))->where('module', 'locations')->where('normalized->publish', true)
            ->whereIn('ocpi_provider_id', OcpiProvider::where('authorized', true)->select('id'))
            ->get(['id', 'ocpi_provider_id', 'normalized', 'source_updated_at', 'received_at'])->keyBy('id');
        $labels = OcpiProvider::whereIn('id', $partners->pluck('ocpi_provider_id'))->where('authorized', true)->pluck('name', 'id');
        $page->setCollection($rows->map(function ($row) use ($stations, $partners, $labels) {
            if ($row->kind === 'catalog') {
                $station = $stations->get($row->id);

                return $station ? ['key' => 'catalog:'.$row->id, 'kind' => 'catalog', 'station' => $station] : null;
            }
            $record = $partners->get($row->id);
            if (! $record || ! $labels->has($record->ocpi_provider_id)) {
                return null;
            }

            return ['key' => 'partner:'.$row->id, 'kind' => 'partner', 'record_id' => $row->id,
                'provider_label' => $labels->get($record->ocpi_provider_id), 'provider_label_evidence' => 'PROVIDER_CONFIGURED_UNVERIFIED',
                'location' => PublicPartnerLocation::project($record->normalized ?? []),
                'source_updated_at' => $record->source_updated_at, 'received_at' => $record->received_at,
                'evidence' => 'PARTNER_REPORTED_UNVERIFIED', 'availability' => 'unknown', 'reservable' => false, 'remote_control' => false];
        })->filter()->values());

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }
}
