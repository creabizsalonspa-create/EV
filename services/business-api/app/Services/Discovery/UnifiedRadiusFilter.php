<?php

namespace App\Services\Discovery;

use Illuminate\Database\Eloquent\Builder;

/** Local published-catalog proximity, never route distance or live availability. */
final class UnifiedRadiusFilter
{
    public function __construct(private CatalogGeo $catalogGeo, private PartnerCatalogGeo $partnerGeo) {}

    public function apply(Builder $catalog, Builder $partner, array $input): void
    {
        $latitude = (float) $input['latitude'];
        $longitude = (float) $input['longitude'];
        $radius = (float) $input['radius_km'];

        // Both independent radius searches already use the same bounded
        // spherical math. Explicit longitude admission also matters at poles,
        // where the preliminary longitude interval covers every meridian.
        $catalog->whereBetween('stations.latitude', [-90, 90])
            ->whereBetween('stations.longitude', [-180, 180]);
        $this->catalogGeo->within($catalog, $latitude, $longitude, $radius);
        $this->partnerGeo->within($partner, $latitude, $longitude, $radius);

        // The union owns the typed identity projection and stable pagination.
        // Discard only source-specific SELECT/ORDER bindings, not predicates.
        // Never sort a partially loaded page and call it a global radius search.
        $catalog->select([])->reorder();
        $partner->select([])->reorder();
    }
}
