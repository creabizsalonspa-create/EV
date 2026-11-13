<?php

namespace App\Services\Discovery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogPriceFilter
{
    /** Currency-specific simulated energy rate, excluding tax; never a total-cost quote. */
    public function apply(Builder $query, array $filters): void
    {
        if (! isset($filters['max_rate_per_kwh'])) {
            return;
        }
        $currency = $filters['price_currency'];
        $units = DB::table('regions')->where('currency', $currency)->distinct()->pluck('minor_units');
        if ($units->count() !== 1 || ! in_array((int) $units->first(), [0, 1, 2, 3], true)) {
            throw ValidationException::withMessages(['price_currency' => 'A consistently configured currency is required.']);
        }
        $units = (int) $units->first();
        [$whole, $fraction] = array_pad(explode('.', $filters['max_rate_per_kwh']), 2, '');
        if (strlen($fraction) > $units) {
            throw ValidationException::withMessages(['max_rate_per_kwh' => 'Price precision exceeds the configured currency.']);
        }
        $maximum = (int) $whole * (10 ** $units) + (int) str_pad($fraction, $units, '0');
        $at = now();
        // Match the finance service's latest effective version, including its
        // deterministic ID tie-break. An old cheap or future tariff cannot match.
        $current = DB::table('finance_tariff_versions as latest')->select('latest.id')
            ->whereColumn('latest.station_id', 'stations.id')->where('latest.effective_from', '<=', $at)
            ->orderByDesc('latest.effective_from')->orderByDesc('latest.id')->limit(1);
        $query->where('stations.source', 'simulator')->where('stations.currency', $currency)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('finance_tariff_versions as price')
                ->where('price.id', '=', $current)->where('price.currency', $currency)
                ->where('price.minor_units', $units)->where('price.rate_minor_per_kwh', '<=', $maximum));
    }
}
