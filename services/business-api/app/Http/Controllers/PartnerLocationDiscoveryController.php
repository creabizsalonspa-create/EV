<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Services\Discovery\PartnerCatalogGeo;
use Illuminate\Http\Request;

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
            'latitude' => 'required_with:longitude,radius_km|numeric|between:-90,90',
            'longitude' => 'required_with:latitude,radius_km|numeric|between:-180,180',
            'radius_km' => 'required_with:latitude,longitude|numeric|between:1,200',
        ]);
        $text = trim($filters['q'] ?? '');

        $query = OcpiRecord::query()
            ->where('module', 'locations')
            ->where('normalized->publish', true)
            ->whereIn('ocpi_provider_id', OcpiProvider::where('authorized', true)->select('id'))
            ->when($text !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('normalized->name', '%'.$text.'%')
                ->orWhereLike('normalized->city', '%'.$text.'%')
                ->orWhereLike('normalized->address', '%'.$text.'%')))
            ->when(! empty($filters['country']), fn ($q) => $q->where('normalized->country', $filters['country']))
            ->when(! empty($filters['connector_standard']), fn ($q) => $q->whereJsonContains('normalized->connector_standards', $filters['connector_standard']));

        $nearby = isset($filters['latitude']);
        if ($nearby) {
            $geo->within($query, (float) $filters['latitude'], (float) $filters['longitude'], (float) $filters['radius_km']);
        }

        // The final ID key also stabilizes ties in distance.
        $now = now()->getTimestamp();

        return $query->orderByDesc('ocpi_records.id')->paginate(25)->appends($filters)
            ->through(function (OcpiRecord $record) use ($nearby, $now) {
                $age = $record->source_updated_at
                    ? max(0, $now - $record->source_updated_at->getTimestamp())
                    : null;

                return [
                    'id' => $record->id,
                    'provider_id' => $record->ocpi_provider_id,
                    'location' => $record->normalized,
                    'received_at' => $record->received_at,
                    'source_updated_at' => $record->source_updated_at,
                    'reported_source_age_minutes' => $age === null ? null : intdiv($age, 60),
                    'source_timestamp_evidence' => 'PARTNER_REPORTED_ONLY',
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
    }
}
