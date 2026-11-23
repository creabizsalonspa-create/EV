<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Services\Ocpi\CatalogQualityHistory;
use Illuminate\Http\Request;

final class OcpiCatalogQualityHistoryController extends Controller
{
    public function show(Request $request, OcpiProvider $provider, CatalogQualityHistory $quality)
    {
        return response()->json($quality->read($request->user(), $provider))
            ->header('Cache-Control', 'private, no-store');
    }
}
