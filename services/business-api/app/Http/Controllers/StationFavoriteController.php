<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Services\Discovery\StationCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StationFavoriteController extends Controller
{
    private function customer(Request $r): int
    {
        abort_unless($r->user()->role === 'customer', 403);

        return $r->user()->id;
    }

    public function index(Request $r, StationCatalog $catalog)
    {
        $user = $this->customer($r);
        $page = Station::query()->join('station_favorites as f', 'f.station_id', '=', 'stations.id')->where('f.user_id', $user)->select(['stations.*', 'f.created_at as favorite_since'])->orderByDesc('f.created_at')->orderByDesc('stations.id')->paginate(25);

        return $page->setCollection($catalog->present($page->getCollection(), $user));
    }

    public function status(Request $r)
    {
        $user = $this->customer($r);
        $v = $r->validate(['station_ids' => 'required|array|min:1|max:25', 'station_ids.*' => 'required|integer|distinct|min:1']);
        $ids = DB::table('station_favorites')->where('user_id', $user)->whereIn('station_id', $v['station_ids'])->pluck('station_id')->map(fn ($id) => (int) $id)->values();

        return response()->json(['favorite_station_ids' => $ids]);
    }

    private function stationId(string $station): int
    {
        $v = validator(['station_id' => $station], ['station_id' => 'required|integer|min:1'])->validate();

        return (int) $v['station_id'];
    }

    public function save(Request $r, string $station)
    {
        $user = $this->customer($r);
        $station = $this->stationId($station);
        Station::findOrFail($station);
        DB::table('station_favorites')->insertOrIgnore(['user_id' => $user, 'station_id' => $station, 'created_at' => now()]);

        return response()->json(['station_id' => $station, 'is_favorite' => true]);
    }

    public function remove(Request $r, string $station)
    {
        $user = $this->customer($r);
        $station = $this->stationId($station);
        DB::table('station_favorites')->where('user_id', $user)->where('station_id', $station)->delete();

        return response()->json(['station_id' => $station, 'is_favorite' => false]);
    }
}
