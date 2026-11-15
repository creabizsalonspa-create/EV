<?php

return [
    // Catalog report/ingestion age only. Invalid policy stays unavailable.
    'max_age_seconds' => env('OCPI_CATALOG_MAX_AGE_SECONDS', 86400),
];
