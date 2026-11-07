<?php

namespace App\Http\Controllers;

use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VendorFeedbackAnalyticsController extends Controller
{
    /**
     * All statistics come only from currently published, source-qualified
     * simulator feedback. This is not physical charger or live network data.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()?->role === 'vendor', 403);
        $v = $request->validate([
            'days' => 'sometimes|in:7,30,90',
            'station_id' => 'sometimes|required|integer|min:1',
        ]);
        $vendorId = (int) $request->user()->id;
        $days = (int) ($v['days'] ?? 30);
        $stationId = isset($v['station_id']) ? (int) $v['station_id'] : null;
        if ($stationId !== null) {
            // An unknown or foreign station must not disclose station ownership.
            abort_unless(Station::whereKey($stationId)->where('vendor_id', $vendorId)->exists(), 404);
        }
        $start = now()->subDays($days);
        $records = DB::table('charging_reviews as r')
            ->join('stations as s', function ($join) {
                $join->on('s.id', '=', 'r.station_id')
                    ->on('s.vendor_id', '=', 'r.vendor_id');
            })
            ->join('charging_review_events as e', function ($join) {
                $join->on('e.review_id', '=', 'r.id')
                    ->on('e.revision', '=', 'r.revision');
            })
            ->where('r.vendor_id', $vendorId)
            ->where('s.source', 'simulator')
            ->where('r.state', 'published')
            ->whereIn('r.evidence', ['SIMULATED_APP_ONLY', 'SIMULATED_SOURCE_OBSERVED'])
            ->whereIn('e.action', ['create', 'revise'])
            ->where('e.created_at', '>=', $start)
            ->when($stationId !== null, fn ($q) => $q->where('r.station_id', $stationId));

        $summary = (clone $records)->selectRaw(
            'COUNT(*) as total, COALESCE(SUM(e.rating),0) as rating_sum'
        )->first();

        $distribution = array_fill_keys(range(1, 5), 0);
        foreach ((clone $records)->selectRaw('e.rating as stars, COUNT(*) as total')
            ->groupBy('e.rating')->get() as $row) {
            $distribution[(int) $row->stars] = (int) $row->total;
        }

        $provenance = [
            'SIMULATED_APP_ONLY' => 0,
            'SIMULATED_SOURCE_OBSERVED' => 0,
        ];
        foreach ((clone $records)->selectRaw('r.evidence as evidence, COUNT(*) as total')
            ->groupBy('r.evidence')->get() as $row) {
            $provenance[$row->evidence] = (int) $row->total;
        }

        $stationGroups = (clone $records)
            ->selectRaw('r.station_id as station_id, s.name as station_name, COUNT(*) as total, SUM(e.rating) as rating_sum')
            ->groupBy('r.station_id', 's.name')
            ->orderByDesc('total')
            ->orderBy('r.station_id')
            ->limit(20)->get()
            ->map(fn ($row) => [
                'station_id' => (int) $row->station_id,
                'station_name' => $row->station_name,
                'total' => (int) $row->total,
                'average_rating' => round((int) $row->rating_sum / (int) $row->total, 2),
            ])->all();

        $total = (int) $summary->total;
        return response()->json([
            'days' => $days,
            'station_id' => $stationId,
            'total' => $total,
            'average_rating' => $total ? round((int) $summary->rating_sum / $total, 2) : null,
            'star_distribution' => $distribution,
            'evidence_counts' => $provenance,
            'stations' => $stationGroups,
            'source' => 'MODERATED_SIMULATOR_REVIEWS_ONLY',
            'physical_charging_verified' => false,
            'snapshot_at' => now()->toIso8601String(),
            'range_basis' => 'LATEST_PUBLISHED_REVIEW_EVENT_TIME',
        ])->header('Cache-Control', 'private, no-store');
    }
}
