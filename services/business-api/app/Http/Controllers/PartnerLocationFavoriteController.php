<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerLocationFavoriteController extends Controller
{
    private function customer(Request $r): int
    {
        abort_unless($r->user()->role === 'customer', 403);

        return $r->user()->id;
    }

    public function index(Request $r)
    {
        $user = $this->customer($r);
        $page = OcpiRecord::join('partner_location_favorites as f', 'f.record_id', '=', 'ocpi_records.id')->where('f.user_id', $user)->where('module', 'locations')->select(['ocpi_records.*', 'f.created_at as favorite_since'])->orderByDesc('f.created_at')->orderByDesc('ocpi_records.id')->paginate(25);
        $authorized = OcpiProvider::whereIn('id', $page->getCollection()->pluck('ocpi_provider_id'))->where('authorized', true)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $page->through(function ($record) use ($authorized) {
            $available = in_array((int) $record->ocpi_provider_id, $authorized, true) && ($record->normalized['publish'] ?? false) === true;

            return ['id' => $record->id, 'provider_id' => $record->ocpi_provider_id, 'location_available' => $available, 'location' => $available ? $record->normalized : null,
                'received_at' => $available ? $record->received_at : null, 'favorite_since' => $record->favorite_since, 'is_favorite' => true,
                'evidence' => 'PARTNER_REPORTED_UNVERIFIED', 'availability' => 'unknown', 'remote_control' => false, 'reservable' => false];
        });

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function status(Request $r)
    {
        $user = $this->customer($r);
        $v = $r->validate(['record_ids' => 'required|array|min:1|max:25', 'record_ids.*' => 'required|integer|min:1|distinct']);

        return response()->json(['favorite_record_ids' => DB::table('partner_location_favorites')->where('user_id', $user)->whereIn('record_id', $v['record_ids'])->pluck('record_id')->map(fn ($id) => (int) $id)->values()])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $r, string $location)
    {
        $user = $this->customer($r);
        $id = validator(['id' => $location], ['id' => 'required|integer|min:1'])->validate()['id'];

        return DB::transaction(function () use ($user, $id) {
            $record = OcpiRecord::whereKey($id)->where('module', 'locations')->firstOrFail();
            $provider = OcpiProvider::whereKey($record->ocpi_provider_id)->lockForUpdate()->firstOrFail();
            $record = $record->fresh();
            abort_unless($record && $provider->authorized && ($record->normalized['publish'] ?? false) === true, 404);
            DB::table('partner_location_favorites')->insertOrIgnore(['user_id' => $user, 'record_id' => $record->id, 'created_at' => now()]);

            return response()->json(['record_id' => $record->id, 'is_favorite' => true, 'remote_control' => false])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function remove(Request $r, string $location)
    {
        $user = $this->customer($r);
        $id = validator(['id' => $location], ['id' => 'required|integer|min:1'])->validate()['id'];
        DB::table('partner_location_favorites')->where('user_id', $user)->where('record_id', $id)->delete();

        return response()->json(['record_id' => (int) $id, 'is_favorite' => false])->header('Cache-Control', 'private, no-store');
    }
}
