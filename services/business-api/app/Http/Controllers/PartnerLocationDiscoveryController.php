<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Services\Discovery\PartnerCatalogAge;
use App\Services\Discovery\PartnerCatalogText;
use App\Services\Discovery\PartnerCatalogTime;
use App\Services\Discovery\PartnerCatalogGeo;
use App\Services\Discovery\PublicPartnerLocation;
use App\Services\Ocpi\ContractReviewGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PartnerLocationDiscoveryController extends Controller
{
    /**
     * The same canonical OCPI records as the existing roaming catalog.
     * Coordinates and ages never imply live availability or control rights.
     */
    public function index(Request $request, PartnerCatalogGeo $geo)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:80',
            'country' => 'nullable|string|regex:/^[A-Z]{3}$/D',
            'connector_standard' => 'nullable|string|max:64',
            'provider' => 'nullable|string|max:80',
            'max_source_age_hours' => 'nullable|integer|between:1,168',
            'reported_evse_status' => 'nullable|string|in:AVAILABLE,BLOCKED,CHARGING,INOPERATIVE,OUTOFORDER,PLANNED,REMOVED,RESERVED,UNKNOWN',
            'latitude' => 'required_with:longitude,radius_km|numeric|between:-90,90',
            'longitude' => 'required_with:latitude,radius_km|numeric|between:-180,180',
            'radius_km' => 'required_with:latitude,longitude|numeric|between:1,200',
        ]);
        $checkedAt = now('UTC');
        $text = trim($filters['q'] ?? '');
        $providerText = trim($filters['provider'] ?? '');
        $authorizedProviders = app(ContractReviewGate::class)->eligiblePublic(OcpiProvider::query()->where('authorized', true));
        if ($providerText !== '') {
            $driver = DB::connection()->getDriverName();
            if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
                throw new \LogicException('Unsupported partner provider-search database.');
            }
            $operator = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $providerText).'%';
            $authorizedProviders->whereRaw("ocpi_providers.name $operator ? ESCAPE '!'", [$pattern]);
        }

        $query = OcpiRecord::query()
            ->where('module', 'locations')
            ->where('normalized->publish', true)
            ->whereIn('ocpi_provider_id', $authorizedProviders->select('id'))
            ->when($text !== '', function ($query) use ($text) {
                // SQL LIKE wildcards are not the user's search text. Both supported
                // databases accept an explicit ! escape; every value is bound.
                $driver = DB::connection()->getDriverName();
                if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
                    throw new \LogicException('Unsupported partner text-search database.');
                }
                $operator = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
                $query->where(function ($match) use ($operator, $pattern) {
                    foreach (['name', 'city', 'address'] as $field) {
                        $column = PartnerCatalogText::field($field);
                        $match->orWhereRaw("$column $operator ? ESCAPE '!'", [$pattern]);
                    }
                });
            })
            ->when(isset($filters['max_source_age_hours']), function ($q) use ($filters, $checkedAt) {
                // A source update timestamp is not telemetry, occupancy or
                // charging authority. Do not accept future clocks as fresh.
                PartnerCatalogTime::within($q, $checkedAt, (int) $filters['max_source_age_hours']);
            })
            ->when(! empty($filters['reported_evse_status']), function ($q) use ($filters) {
                // These are the partner's *reported* EVSE states. This query
                // never converts AVAILABLE into live charging availability.
                $standard = $filters['connector_standard'] ?? null;
                // When both filters are selected, require the reported state
                // and connector standard on the same EVSE, not sibling EVSEs.
                PartnerCatalogText::reportedState($q, $filters['reported_evse_status'], $standard);
            })
            ->when(! empty($filters['country']), fn ($q) => $q->where('normalized->country', $filters['country']))
            ->when(! empty($filters['connector_standard']), fn ($q) => PartnerCatalogText::standard($q, $filters['connector_standard']));

        $nearby = isset($filters['latitude']);
        if ($nearby) {
            $geo->within($query, (float) $filters['latitude'], (float) $filters['longitude'], (float) $filters['radius_km']);
        } else {
            $query->select('ocpi_records.*');
        }
        // Name is provider-configured and not proof of a legal network operator.
        // The same authorization gate prevents exposing a revoked provider label.
        $query->addSelect([
            'provider_label' => app(ContractReviewGate::class)->eligiblePublic(OcpiProvider::query()->where('authorized', true))->select('name')
                ->whereColumn('ocpi_providers.id', 'ocpi_records.ocpi_provider_id')
                ->limit(1),
        ]);

        // The final ID key also stabilizes ties in distance.

        $page = $query->orderByDesc('ocpi_records.id')->paginate(25)->appends($filters)
            ->through(function (OcpiRecord $record) use ($nearby, $checkedAt) {
                return [
                    'id' => $record->id,
                    'provider_id' => $record->ocpi_provider_id,
                    'provider_label' => $record->provider_label,
                    'provider_label_evidence' => 'PROVIDER_CONFIGURED_UNVERIFIED',
                    'location' => PublicPartnerLocation::project($record->normalized ?? []),
                    ...PartnerCatalogAge::present($record, $checkedAt),
                    'evidence' => 'PARTNER_REPORTED_UNVERIFIED',
                    'availability' => 'unknown',
                    'remote_control' => false,
                    'reservable' => false,
                    ...($nearby ? [
                        'distance_km' => round((float) $record->distance_km, 3),
                        'distance_basis' => 'great_circle',
                    ] : []),
                ];
            });

        // Exact latitude/longitude searches in GET URLs must not be retained
        // in shared/proxy or browser caches, regardless of login state.
        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }
}
