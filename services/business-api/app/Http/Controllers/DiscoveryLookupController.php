<?php

namespace App\Http\Controllers;

use App\Services\Discovery\DiscoveryLookups;
use App\Services\Discovery\LookupFailure;
use Illuminate\Http\Request;

final class DiscoveryLookupController extends Controller
{
    public function capabilities(DiscoveryLookups $lookups)
    {
        return response()->json($lookups->capabilities());
    }

    public function addresses(Request $request, DiscoveryLookups $lookups)
    {
        $input = $request->validate(['consent' => 'required|accepted', 'query' => 'required|string|min:3|max:180', 'language' => 'required|in:en,ar', 'country' => 'nullable|string|regex:/^[a-zA-Z]{2}$/']);

        return $this->respond(fn () => $lookups->addresses($input));
    }

    public function route(Request $request, DiscoveryLookups $lookups)
    {
        $input = $request->validate(['consent' => 'required|accepted', 'language' => 'required|in:en,ar', 'points' => 'required|array|min:2|max:5', 'points.*' => 'required|array:latitude,longitude', 'points.*.latitude' => 'required|numeric|between:-90,90', 'points.*.longitude' => 'required|numeric|between:-180,180']);

        return $this->respond(fn () => $lookups->route($input));
    }

    private function respond(\Closure $lookup)
    {
        try {
            return response()->json($lookup());
        } catch (LookupFailure $failure) {
            return response()->json(['message' => 'Discovery lookup unavailable. Manual search and external navigation remain available.', 'reason' => $failure->reason], $failure->status);
        }
    }
}
