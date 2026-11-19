<?php

namespace App\Http\Controllers;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Security\AdminTransactionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminOverviewController extends Controller
{
    /**
     * Read-only operational overview with private audit history. An Admin
     * who lost access after their request was authenticated cannot receive
     * these records from a stale session snapshot.
     */
    public function show(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $snapshot = $request->user();
            abort_unless($snapshot instanceof User, 403);
            $actor = AdminTransactionAuthority::requireCurrent($snapshot);

            $overview = [
                'users' => User::count(),
                'stations' => Station::count(),
                'reservations' => Reservation::count(),
                'simulations' => ChargingSession::count(),
                'audit_events' => DB::table('audit_events')
                    ->select(['id', 'actor_id', 'action', 'subject', 'created_at'])
                    ->orderByDesc('id')->limit(50)->get(),
            ];

            // A successful private audit-history read is independently
            // auditable without storing the response contents or secrets.
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id,
                'action' => 'admin.private_list.overview_viewed',
                'subject' => 'overview',
                'created_at' => now(),
            ]);

            return response()->json($overview)->header('Cache-Control', 'no-store, private');
        });
    }
}
