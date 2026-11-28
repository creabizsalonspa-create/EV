<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Security\AdminTransactionAuthority;
use App\Services\Discovery\LicensedCoverageHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminLicensedCoverageController extends Controller
{
    public function show(Request $request, LicensedCoverageHistory $history)
    {
        $snapshot = $request->user();
        abort_unless($snapshot instanceof User && $snapshot->role === 'admin', 403);
        [$report, $locale] = DB::transaction(function () use ($snapshot, $history) {
            $actor = AdminTransactionAuthority::requireCurrent($snapshot);

            return [$history->report(), $actor->locale === 'ar' ? 'ar' : 'en'];
        }, 3);

        return response()->view('admin-licensed-coverage', ['report' => $report, 'locale' => $locale])
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
