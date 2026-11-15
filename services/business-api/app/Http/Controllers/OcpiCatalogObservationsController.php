<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Services\Ocpi\CatalogObservations;
use Illuminate\Http\Request;

final class OcpiCatalogObservationsController extends Controller
{
    public function show(Request $request, OcpiProvider $provider, CatalogObservations $service)
    {
        $v = $request->validate(['module' => 'nullable|in:all,locations,tariffs']);

        return response()->json($service->read($request->user(), $provider, $v['module'] ?? 'all'))->header('Cache-Control', 'private, no-store');
    }
}
